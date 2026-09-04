<?php

namespace App\Models;

use App\Models\Concerns\BelongsToBusiness;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class Photo extends Model
{
    use BelongsToBusiness,SoftDeletes;

    protected $fillable = ['business_id', 'gallery_id', 'uuid', 'filename', 'original_path', 'preview_path', 'thumbnail_path', 'file_size', 'width', 'height', 'mime_type', 'status', 'is_proof', 'is_final', 'is_downloadable', 'watermarked', 'face_identifier'];

    protected function casts(): array
    {
        return ['is_proof' => 'boolean', 'is_final' => 'boolean', 'is_downloadable' => 'boolean', 'watermarked' => 'boolean'];
    }

    public function gallery(): BelongsTo
    {
        return $this->belongsTo(Gallery::class);
    }

    public function portfolioCategories(): BelongsToMany
    {
        return $this->belongsToMany(PortfolioCategory::class, 'portfolio_photos');
    }

    public function selections(): HasMany
    {
        return $this->hasMany(PhotoSelection::class);
    }

    public function favorites(): HasMany
    {
        return $this->hasMany(PhotoFavorite::class);
    }
}
