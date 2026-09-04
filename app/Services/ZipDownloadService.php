<?php

namespace App\Services;

use App\Models\Gallery;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use ZipArchive;

class ZipDownloadService
{
    public function create(Gallery $gallery, array $ids, bool $paymentSatisfied = false): string
    {
        abort_unless($gallery->downloads_enabled && (! $gallery->payment_required || $paymentSatisfied), 403, 'Payment and gallery download permission are required.');
        $photos = $gallery->photos()->whereIn('id', array_unique($ids))->where('is_downloadable', true)->get();
        if (! $photos->count() || $photos->count() !== count(array_unique($ids))) {
            throw ValidationException::withMessages(['photos' => 'One or more photos are not downloadable.']);
        }$dir = storage_path('app/private/temp-zips');
        if (! is_dir($dir)) {
            mkdir($dir, 0755, true);
        }$path = $dir.'/'.uniqid('gallery-', true).'.zip';
        $zip = new ZipArchive;
        abort_unless($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) === true, 500);
        foreach ($photos as $photo) {
            $source = Storage::disk('local')->path($photo->original_path);
            abort_unless(is_file($source), 404);
            $zip->addFile($source, basename($photo->filename));
        }$zip->close();

        return $path;
    }
}
