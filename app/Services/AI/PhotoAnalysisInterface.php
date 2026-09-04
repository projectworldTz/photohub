<?php

namespace App\Services\AI;

use App\Models\Photo;

interface PhotoAnalysisInterface
{
    public function analyze(Photo $photo): array;

    public function available(): bool;
}
