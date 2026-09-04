<?php

namespace App\Services\AI;

use App\Models\Photo;

class DisabledPhotoAnalysisService implements PhotoAnalysisInterface
{
    public function analyze(Photo $photo): array
    {
        return [];
    }

    public function available(): bool
    {
        return false;
    }
}
