<?php

namespace Tests\Feature;

use App\Services\QRCodeService;
use Tests\TestCase;

class QrCodeTest extends TestCase
{
    public function test_qr_service_returns_png(): void
    {
        $png = app(QRCodeService::class)->png('https://example.com/gallery/ABC');
        $this->assertStringStartsWith("\x89PNG", $png);
    }
}
