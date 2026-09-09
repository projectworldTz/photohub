<?php

namespace App\Services;

use App\Models\Gallery;
use App\Models\Photo;
use Illuminate\Http\UploadedFile;

class PhotoProcessingService
{
    public function store(Gallery $gallery, UploadedFile $file, bool $final = false): Photo
    {
        return $this->storeBatch($gallery, [$file], $final)[0];
    }

    public function storeBatch(Gallery $gallery, array $files, bool $final = false): array
    {
        return app(PhotoUploadService::class)->storeBatch($gallery, $files, $final);
    }

    public function process(Photo $photo, bool $watermark): void
    {
        app(PhotoUploadService::class)->processExisting($photo, $watermark);
    }
}
