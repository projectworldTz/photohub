<?php

namespace App\Services;

use App\Jobs\ProcessPhotoVariants;
use App\Models\Gallery;
use App\Models\Photo;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class PhotoProcessingService
{
    public function __construct(private WatermarkService $watermarks) {}

    public function store(Gallery $g, UploadedFile $file, bool $final = false): Photo
    {
        $uuid = (string) Str::uuid();
        $ext = strtolower($file->extension());
        $base = 'businesses/'.$g->business_id.'/galleries/'.$g->id;
        $original = $file->storeAs($base.'/originals', $uuid.'.'.$ext, 'local');
        [$w,$h] = getimagesize($file->getRealPath()) ?: [null, null];
        $photo = Photo::create(['business_id' => $g->business_id, 'gallery_id' => $g->id, 'uuid' => $uuid, 'filename' => $file->getClientOriginalName(), 'original_path' => $original, 'file_size' => $file->getSize(), 'width' => $w, 'height' => $h, 'mime_type' => $file->getMimeType(), 'status' => 'processing', 'is_proof' => ! $final, 'is_final' => $final, 'is_downloadable' => $final]);
        ProcessPhotoVariants::dispatch($photo->id, $g->watermark_enabled && ! $final);

        return $photo;
    }

    public function process(Photo $p, bool $watermark): void
    {
        $source = Storage::disk('local')->path($p->original_path);
        $ext = strtolower(pathinfo($p->original_path, PATHINFO_EXTENSION));
        $img = match ($ext) {
            'png' => imagecreatefrompng($source),'webp' => imagecreatefromwebp($source),default => imagecreatefromjpeg($source)
        };
        if (! $img) {
            throw new RuntimeException("Unable to decode photo {$p->id}.");
        }

        foreach (['thumbnail' => 420, 'preview' => 1600] as $kind => $max) {
            $w = imagesx($img);
            $h = imagesy($img);
            $scale = min(1, $max / max($w, $h));
            $nw = (int) ($w * $scale);
            $nh = (int) ($h * $scale);
            $out = imagecreatetruecolor($nw, $nh);
            imagecopyresampled($out, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            if ($watermark && $kind === 'preview') {
                $this->watermarks->apply($out, $p->business_id);
            }
            $path = 'businesses/'.$p->business_id.'/galleries/'.$p->gallery_id.'/'.$kind.'/'.$p->uuid.'.jpg';
            ob_start();
            imagejpeg($out, null, 84);
            Storage::disk('local')->put($path, ob_get_clean());
            imagedestroy($out);
            $p->{$kind.'_path'} = $path;
        }
        $p->watermarked = $watermark;
        $p->status = 'ready';
        $p->save();
        imagedestroy($img);
    }
}
