<?php

namespace App\Services;

use App\Models\FinalPhoto;
use App\Models\Gallery;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;

class FinalPhotoService
{
    public function store(Gallery $gallery, UploadedFile $file, ?int $proofPhotoId = null): FinalPhoto
    {
        $proof = $proofPhotoId ? $gallery->photos()->whereKey($proofPhotoId)->firstOrFail() : $gallery->photos()->whereHas('selections')->whereRaw('LOWER(filename) = ?', [strtolower($file->getClientOriginalName())])->first();
        $uuid = (string) Str::uuid();
        $base = "businesses/{$gallery->business_id}/galleries/{$gallery->id}/finals";
        $original = $file->storeAs($base.'/originals', $uuid.'.'.strtolower($file->extension()), 'local');
        [$width, $height] = getimagesize($file->getRealPath()) ?: [null, null];
        $contents = file_get_contents($file->getRealPath());
        $image = $contents === false ? false : @imagecreatefromstring($contents);
        if (! $image || ! $width || ! $height) {
            Storage::disk('local')->delete($original);
            throw new RuntimeException('The uploaded final photo could not be decoded.');
        }
        $paths = [];
        foreach (['thumbnail' => 420, 'preview' => 1600] as $kind => $max) {
            $scale = min(1, $max / max($width, $height));
            $w = max(1, (int) ($width * $scale));
            $h = max(1, (int) ($height * $scale));
            $out = imagecreatetruecolor($w, $h);
            imagecopyresampled($out, $image, 0, 0, 0, 0, $w, $h, $width, $height);
            ob_start();
            imagejpeg($out, null, 86);
            $paths[$kind] = $base.'/'.$kind.'/'.$uuid.'.jpg';
            Storage::disk('local')->put($paths[$kind], ob_get_clean());
            imagedestroy($out);
        }
        imagedestroy($image);
        $replaced = $proof ? FinalPhoto::withTrashed()->where('gallery_id', $gallery->id)->where('proof_photo_id', $proof->id)->get() : collect();
        $final = FinalPhoto::create(['business_id' => $gallery->business_id, 'gallery_id' => $gallery->id, 'proof_photo_id' => $proof?->id, 'uuid' => $uuid, 'filename' => $file->getClientOriginalName(), 'original_path' => $original, 'preview_path' => $paths['preview'], 'thumbnail_path' => $paths['thumbnail'], 'file_size' => $file->getSize(), 'width' => $width, 'height' => $height, 'mime_type' => $file->getMimeType(), 'status' => 'ready']);

        foreach ($replaced as $old) {
            Storage::disk('local')->delete(array_filter([$old->original_path, $old->preview_path, $old->thumbnail_path]));
            $old->forceDelete();
        }

        return $final;
    }
}
