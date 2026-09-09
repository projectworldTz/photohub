<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class CloudApiService
{
    public function request(int $businessId, string $method, string $path, array $data = [], ?string $file = null): array
    {
        $url = rtrim((string) config('photohub.cloud_url'), '/');
        if (! PhotoStorage::isLocal() || ! config('photohub.cloud_enabled') || $businessId !== config('photohub.business_id') || ! config('photohub.studio_token') || ! $url) {
            throw new RuntimeException('Cloud synchronization is not configured for this studio.');
        }
        if (parse_url($url, PHP_URL_SCHEME) !== 'https' && ! (config('photohub.allow_http') && parse_url($url, PHP_URL_SCHEME) === 'http')) {
            throw new RuntimeException('Cloud synchronization requires HTTPS.');
        }
        $client = Http::baseUrl($url.'/api/sync/v1')->withToken(config('photohub.studio_token'))->acceptJson()->connectTimeout(3)->timeout($file ? 45 : 10)->withoutRedirecting();
        $stream = null;
        try {
            if ($file) {
                $stream = PhotoStorage::disk($file)->readStream($file);
                if (! is_resource($stream)) {
                    throw new RuntimeException('Photo is unavailable.');
                }
                $client = $client->attach('file', $stream, basename($file));
            }
            $result = $client->{strtolower($method)}($path, $data)->throw()->json();
            if (! is_array($result)) {
                throw new RuntimeException('Invalid cloud response.');
            }

            return $result;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
