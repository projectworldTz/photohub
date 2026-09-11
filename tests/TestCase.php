<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Storage;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['photohub.mode' => 'cloud', 'photohub.cloud_enabled' => false]);
        // Seeders and uploads in tests must never write into real studio storage.
        Storage::fake('local');
    }
}
