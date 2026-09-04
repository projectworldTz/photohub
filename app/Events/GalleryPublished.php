<?php

namespace App\Events;

use App\Models\Gallery;
use Illuminate\Foundation\Events\Dispatchable;

class GalleryPublished
{
    use Dispatchable;

    public function __construct(public Gallery $gallery) {}
}
