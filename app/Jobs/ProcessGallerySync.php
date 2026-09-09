<?php

namespace App\Jobs;

use App\Models\SyncJob;
use App\Services\GallerySyncService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class ProcessGallerySync implements ShouldQueue
{
    use Queueable;

    public int $timeout = 1200;

    public function __construct(public int $syncJobId) {}

    public function handle(GallerySyncService $sync): void
    {
        if ($job = SyncJob::find($this->syncJobId)) {
            $sync->process($job);
        }
    }
}
