<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Client\ConnectionException;
use App\Exceptions\CloudConnectionException;
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
        $client = Http::baseUrl($url.'/api/sync/v1')->withToken($connection->api_token)->acceptJson()->connectTimeout(10)->timeout($file ? 60 : 30)->withoutRedirecting();
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
        } catch (ConnectionException $error) {
            $step = match (true) {
                $path === 'health' => 'Checking the cloud connection',
                str_ends_with($path, '/photos') => 'Uploading a photo',
                str_ends_with($path, '/publish') => 'Preparing the customer link',
                str_ends_with($path, '/selections') => 'Getting client selections',
                strtolower($method) === 'delete' => 'Removing the online gallery',
                default => 'Preparing the online gallery',
            };
            preg_match('/cURL error (\d+)/', $error->getMessage(), $match);
            $code = isset($match[1]) ? (int) $match[1] : null;
            // Never log the exception, request body, bearer token, or customer URL.
            Log::warning('PhotoHub cloud transport failed', ['business_id' => $businessId,
                'step' => $step, 'curl_code' => $code, 'timeout_seconds' => $file ? 60 : 30]);
            $reason = match ($code) {
                28 => 'timed out while waiting for the cloud',
                6 => 'failed because the cloud address could not be resolved',
                60 => 'failed because the cloud HTTPS certificate could not be verified',
                default => 'failed because the cloud connection was interrupted',
            };
            $message = $step.' '.$reason.'. Your local work and completed uploads are safe. Retry this action to resume.';
            if ($path === 'health') $connection->update(['status' => 'failed', 'last_error' => $message]);
            throw new CloudConnectionException($message);
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
