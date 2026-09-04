<?php

namespace App\Jobs;

use App\Models\Photo;
use App\Services\PhotoProcessingService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessPhotoVariants implements ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    public function __construct(public int $photoId, public bool $watermark) {}

    public function handle(PhotoProcessingService $service): void
    {
        $photo = Photo::findOrFail($this->photoId);
        $service->process($photo, $this->watermark);
    }

    public function failed(): void
    {
        Photo::whereKey($this->photoId)->update(['status' => 'failed']);
    }
}
