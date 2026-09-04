<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class FinalPhoto extends Model
{
    use BelongsToBusiness, SoftDeletes;

    protected $fillable = ['business_id', 'gallery_id', 'proof_photo_id', 'uuid', 'filename', 'original_path', 'preview_path', 'thumbnail_path', 'file_size', 'width', 'height', 'mime_type', 'status'];

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }

    public function proofPhoto(): BelongsTo
    {
        return $this->belongsTo(Photo::class, 'proof_photo_id');
    }
}
