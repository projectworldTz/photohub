<?php

namespace App\Services;

use App\Models\BusinessSetting;
use Illuminate\Support\Facades\Storage;

class WatermarkService
{
    public function apply(\GdImage $image, int $businessId): void
    {
        $settings = BusinessSetting::forBusiness($businessId)->whereIn('key', ['watermark_text', 'watermark_opacity', 'watermark_position', 'watermark_logo'])->pluck('value', 'key');
        $opacity = (int) data_get($settings, 'watermark_opacity.value', 50);
        $position = (string) data_get($settings, 'watermark_position.value', 'bottom_right');
        $logoPath = data_get($settings, 'watermark_logo.value');
        if ($logoPath && Storage::disk('local')->exists($logoPath) && $this->applyLogo($image, Storage::disk('local')->path($logoPath), $position, $opacity)) {
            return;
        }
        $text = (string) data_get($settings, 'watermark_text.value', 'PhotoHub');
        $width = imagefontwidth(5) * strlen($text);
        [$x, $y] = $this->coordinates(imagesx($image), imagesy($image), $width, imagefontheight(5), $position);
        imagestring($image, 5, $x, $y, $text, imagecolorallocatealpha($image, 255, 255, 255, (int) round(127 * (100 - $opacity) / 100)));
    }

    private function applyLogo(\GdImage $image, string $path, string $position, int $opacity): bool
    {
        $content = file_get_contents($path);
        $logo = $content ? @imagecreatefromstring($content) : false;
        if (! $logo) {
            return false;
        }
        $targetWidth = min((int) (imagesx($image) * .22), imagesx($logo));
        $targetHeight = max(1, (int) (imagesy($logo) * ($targetWidth / imagesx($logo))));
        $resized = imagecreatetruecolor($targetWidth, $targetHeight);
        imagealphablending($resized, false);
        imagesavealpha($resized, true);
        imagecopyresampled($resized, $logo, 0, 0, 0, 0, $targetWidth, $targetHeight, imagesx($logo), imagesy($logo));
        [$x, $y] = $this->coordinates(imagesx($image), imagesy($image), $targetWidth, $targetHeight, $position);
        imagecopymerge($image, $resized, $x, $y, 0, 0, $targetWidth, $targetHeight, $opacity);
        imagedestroy($resized);
        imagedestroy($logo);

        return true;
    }

    private function coordinates(int $width, int $height, int $markWidth, int $markHeight, string $position): array
    {
        $margin = 20;

        return match ($position) {
            'top_left' => [$margin, $margin], 'top_right' => [$width - $markWidth - $margin, $margin],
            'bottom_left' => [$margin, $height - $markHeight - $margin], 'center' => [(int) (($width - $markWidth) / 2), (int) (($height - $markHeight) / 2)],
            default => [$width - $markWidth - $margin, $height - $markHeight - $margin],
        };
    }
}
