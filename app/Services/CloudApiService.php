<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use RuntimeException;

class CloudApiService
{
    public function request(int $businessId, string $method, string $path, array $data = [], ?string $file = null): array
    {
        if (! PhotoStorage::isLocal()) {
            throw new RuntimeException('Online sharing is only available from the local application.');
        }
        $studios = app(CloudStudioService::class);
        $connection = $studios->ensure(\App\Models\Business::findOrFail($businessId));
        $url = $studios->validateUrl($connection->base_url);
        $client = Http::baseUrl($url.'/api/sync/v1')->withToken($connection->api_token)->acceptJson()->connectTimeout(3)->timeout($file ? 45 : 10)->withoutRedirecting();
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

            if ($path === 'health') $studios->acceptHealth($connection, $result);
            else $connection->update(['last_connected_at' => now()]);
            return $result;
        } catch (\Illuminate\Http\Client\RequestException $error) {
            if ($error->response->status() === 401 || $path === 'health') {
                $connection->update(['status' => 'failed', 'last_error' => $studios->safeError($error)]);
            }
            throw $error;
        } finally {
            if (is_resource($stream)) {
                fclose($stream);
            }
        }
    }
}
