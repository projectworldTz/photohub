<?php

namespace App\Services;

use Endroid\QrCode\Encoding\Encoding;
use Endroid\QrCode\ErrorCorrectionLevel;
use Endroid\QrCode\QrCode;
use Endroid\QrCode\RoundBlockSizeMode;
use Endroid\QrCode\Writer\PngWriter;

class QRCodeService
{
    public function png(string $url): string
    {
        $qr = new QrCode(data: $url, encoding: new Encoding('UTF-8'), errorCorrectionLevel: ErrorCorrectionLevel::Medium, size: 360, margin: 16, roundBlockSizeMode: RoundBlockSizeMode::Margin);

        return (new PngWriter)->write($qr)->getString();
    }
}
