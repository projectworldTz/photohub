<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class StorageService
{
    public function disk()
    {
        return Storage::disk(config('filesystems.default'));
    }

    public function privateDownload(string $path, string $name)
    {
        abort_unless($this->disk()->exists($path), 404);

        return $this->disk()->download($path, $name);
    }
}
