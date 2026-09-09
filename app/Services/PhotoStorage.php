<?php

namespace App\Services;

use Illuminate\Support\Facades\Storage;

class PhotoStorage
{
    public static function isLocal(): bool
    {
        return in_array(config('photohub.mode'), ['local', 'hybrid'], true);
    }

    public static function disk(string $path)
    {
        return Storage::disk(str_starts_with($path, 'studios/') ? 'photohub_local' : 'local');
    }
}
