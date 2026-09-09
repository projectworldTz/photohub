<?php

namespace App\Services;

use App\Models\FinalPhoto;
use App\Models\Gallery;
use Illuminate\Http\UploadedFile;

class FinalPhotoService
{
    public function store(Gallery $gallery, UploadedFile $file, ?int $proofPhotoId = null): FinalPhoto
    {
        return $this->storeBatch($gallery, [$file], $proofPhotoId)[0];
    }

    public function storeBatch(Gallery $gallery, array $files, ?int $proofPhotoId = null): array
    {
        return app(PhotoUploadService::class)->storeBatch($gallery, $files, true, true, $proofPhotoId);
    }
}
