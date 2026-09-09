<?php

namespace App\Services;

use App\Models\Business;
use App\Models\FinalPhoto;
use App\Models\Gallery;
use App\Models\Photo;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

class PhotoUploadService
{
    public function __construct(private StorageQuotaService $quota, private WatermarkService $watermarks) {}

    public function storeFiles(Business $business, array $files, string $directory): array
    {
        $staged = [];
        foreach ($files as $key => $file) {
            $path = 'businesses/'.$business->id.'/'.$directory.'/'.Str::uuid().'.'.$file->extension();
            $staged[] = ['key' => $key, 'path' => $path, 'writes' => [$path => $file->getRealPath()]];
        }
        $results = $this->commit($business, $staged, fn ($item) => [$item['key'] => $item['path']]);

        return array_replace([], ...$results);
    }

    /** Stage every variant before accepting a batch, so its exact size is known. */
    public function storeBatch(Gallery $gallery, array $files, bool $final = false, bool $delivery = false, ?int $proofId = null): array
    {
        $business = Business::findOrFail($gallery->business_id);
        app(SubscriptionLimitService::class)->assertCanStore($business, array_sum(array_map(fn ($file) => $file->getSize(), $files)));
        $staged = [];
        $temporary = [];
        try {
            foreach ($files as $file) {
                $uuid = (string) Str::uuid();
                $base = (PhotoStorage::isLocal() ? 'studios/' : 'businesses/')."{$gallery->business_id}/galleries/{$gallery->id}".($delivery ? '/edited' : '');
                $original = $base.'/originals/'.$uuid.'.'.strtolower($file->extension());
                $variants = $this->variants($file->getRealPath(), $gallery->business_id, $gallery->watermark_enabled && ! $final, $temporary);
                $proof = $delivery ? ($proofId ? $gallery->photos()->whereKey($proofId)->firstOrFail() : $gallery->photos()->whereHas('selections')->whereRaw('LOWER(filename) = ?', [strtolower($file->getClientOriginalName())])->first()) : null;
                $attributes = ['business_id' => $gallery->business_id, 'gallery_id' => $gallery->id, 'uuid' => $uuid, 'filename' => $file->getClientOriginalName(), 'original_path' => $original, 'file_size' => $file->getSize(), 'width' => $variants['width'], 'height' => $variants['height'], 'mime_type' => $file->getMimeType(), 'status' => 'ready'];
                $writes = [$original => $file->getRealPath()];
                foreach (['thumbnail', 'preview'] as $kind) {
                    $path = $base.'/'.$kind.'/'.$uuid.'.jpg';
                    $attributes[$kind.'_path'] = $path;
                    $writes[$path] = $variants[$kind];
                }
                $attributes += $delivery ? ['proof_photo_id' => $proof?->id] : ['is_proof' => ! $final, 'is_final' => $final, 'is_downloadable' => $final, 'watermarked' => $gallery->watermark_enabled && ! $final];
                $staged[] = compact('attributes', 'writes');
            }

            return $this->commit($business, $staged, function ($item) use ($delivery) {
                $attributes = $item['attributes'];
                if ($delivery && $attributes['proof_photo_id']) {
                    // Replaced versions remain recoverable and continue consuming storage.
                    FinalPhoto::where('business_id', $attributes['business_id'])->where('gallery_id', $attributes['gallery_id'])->where('proof_photo_id', $attributes['proof_photo_id'])->delete();
                }

                return $delivery ? FinalPhoto::create($attributes) : Photo::create($attributes);
            });
        } finally {
            foreach ($temporary as $handle) {
                fclose($handle);
            }
        }
    }

    /** Compatibility for variant jobs queued before deployment. */
    public function processExisting(Photo $photo, bool $watermark): void
    {
        if ($photo->status === 'ready') {
            return;
        }
        $temporary = [];
        try {
            $variants = $this->variants(PhotoStorage::disk($photo->original_path)->path($photo->original_path), $photo->business_id, $watermark, $temporary);
            $writes = $attributes = [];
            foreach (['thumbnail', 'preview'] as $kind) {
                $path = "businesses/{$photo->business_id}/galleries/{$photo->gallery_id}/{$kind}/".Str::uuid().'.jpg';
                $writes[$path] = $variants[$kind];
                $attributes[$kind.'_path'] = $path;
            }
            $this->commit(Business::findOrFail($photo->business_id), [compact('writes', 'attributes')], function ($item) use ($photo, $watermark) {
                $photo->update($item['attributes'] + ['status' => 'ready', 'watermarked' => $watermark]);

                return $photo;
            });
        } finally {
            foreach ($temporary as $handle) {
                fclose($handle);
            }
        }
    }

    private function variants(string $source, int $businessId, bool $watermark, array &$temporary): array
    {
        $contents = file_get_contents($source);
        $image = $contents === false ? false : @imagecreatefromstring($contents);
        if (! $image) {
            throw new RuntimeException('The uploaded photo could not be decoded.');
        }
        try {
            if (function_exists('exif_read_data')) {
                $exif = @exif_read_data($source);
                $orientation = (int) ($exif['Orientation'] ?? 1);
                if (in_array($orientation, [2, 5, 7], true)) {
                    imageflip($image, IMG_FLIP_HORIZONTAL);
                }
                if ($orientation === 4) {
                    imageflip($image, IMG_FLIP_VERTICAL);
                }
                $angle = match ($orientation) {
                    3 => 180, 5, 8 => 90, 6, 7 => -90, default => 0
                };
                if ($angle) {
                    $rotated = imagerotate($image, $angle, 0);
                    if ($rotated) {
                        imagedestroy($image);
                        $image = $rotated;
                    }
                }
            }
            $result = ['width' => imagesx($image), 'height' => imagesy($image)];
            foreach (['thumbnail' => 420, 'preview' => 1600] as $kind => $max) {
                $scale = min(1, $max / max($result['width'], $result['height']));
                $w = max(1, (int) ($result['width'] * $scale));
                $h = max(1, (int) ($result['height'] * $scale));
                $out = imagecreatetruecolor($w, $h);
                try {
                    imagecopyresampled($out, $image, 0, 0, 0, 0, $w, $h, $result['width'], $result['height']);
                    if ($watermark && $kind === 'preview') {
                        $this->watermarks->apply($out, $businessId);
                    }
                    $handle = tmpfile();
                    if (! $handle) {
                        throw new RuntimeException('Unable to stage photo variants.');
                    }
                    $temporary[] = $handle;
                    if (! imagejpeg($out, $handle, 86)) {
                        throw new RuntimeException('Unable to encode photo variant.');
                    }
                    $result[$kind] = stream_get_meta_data($handle)['uri'];
                } finally {
                    imagedestroy($out);
                }
            }

            return $result;
        } finally {
            imagedestroy($image);
        }
    }

    private function commit(Business $business, array $staged, callable $save): array
    {
        $written = [];
        try {
            return $this->quota->locked($business, function ($locked) use ($staged, $save, &$written) {
                $bytes = 0;
                foreach ($staged as $item) {
                    foreach ($item['writes'] as $source) {
                        $bytes += filesize($source);
                    }
                }
                $this->quota->assertFits($locked, $bytes);
                $records = [];
                foreach ($staged as $item) {
                    foreach ($item['writes'] as $path => $source) {
                        if (PhotoStorage::disk($path)->exists($path)) {
                            throw new RuntimeException('Upload destination already exists.');
                        }
                        $written[] = $path;
                        $stream = fopen($source, 'rb');
                        try {
                            if (! PhotoStorage::disk($path)->put($path, $stream)) {
                                throw new RuntimeException('Unable to save uploaded photo.');
                            }
                        } finally {
                            if (is_resource($stream)) {
                                fclose($stream);
                            }
                        }
                        $this->quota->record($locked->id, $path, PhotoStorage::disk($path)->size($path));
                    }
                    $records[] = $save($item);
                }

                return $records;
            });
        } catch (Throwable $error) {
            // Delete only this failed batch's new files, never existing photos.
            foreach ($written as $path) {
                try {
                    PhotoStorage::disk($path)->delete($path);
                } catch (Throwable $cleanupError) {
                    report($cleanupError);
                }
            }
            if ($written) {
                $this->quota->reconcile($business);
            }
            throw $error;
        }
    }
}
