<?php

namespace App\Services;

use App\Exceptions\CloudConnectionException;
use App\Models\Business;
use App\Models\StudioCloudConnection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Throwable;

class CloudStudioService
{
    /** Lazy local-only creation covers future studios and partially upgraded databases. */
    public function connection(Business $business): StudioCloudConnection
    {
        abort_unless(PhotoStorage::isLocal(), 403);
        return DB::transaction(function () use ($business) {
            Business::whereKey($business->id)->lockForUpdate()->firstOrFail();
            $connection = StudioCloudConnection::where('business_id', $business->id)->first();
            if ($connection) return $connection;
            $legacy = $business->id === config('photohub.business_id') ? config('photohub.studio_token') : null;
            return StudioCloudConnection::create(['business_id' => $business->id,
                'identity_uuid' => (string) Str::uuid(), 'registration_secret' => Str::random(64),
                'api_token' => $legacy ?: null, 'base_url' => config('photohub.cloud_url')]);
        });
    }

    public function validateUrl(?string $url): string
    {
        $url = rtrim((string) $url, '/');
        $parts = parse_url($url);
        if (! filter_var($url, FILTER_VALIDATE_URL) || ! $parts || isset($parts['user']) || isset($parts['pass']) || isset($parts['query']) || isset($parts['fragment'])) {
            throw new CloudConnectionException('This studio is not connected to PhotoHub Cloud yet. Open Cloud Connection and set the cloud address.');
        }
        if (($parts['scheme'] ?? '') !== 'https' && ! (config('photohub.allow_http') && ($parts['scheme'] ?? '') === 'http')) {
            throw new CloudConnectionException('Cloud connections require HTTPS.');
        }
        return $url;
    }

    public function ensure(Business $business, bool $forceCheck = false): StudioCloudConnection
    {
        if (! PhotoStorage::isLocal() || ! config('photohub.cloud_enabled')) {
            throw new CloudConnectionException('Online sharing is disabled. Your local studio and photos remain available.');
        }
        $connection = $this->connection($business);
        if ($connection->status === 'connected' && $connection->api_token && ! $forceCheck) return $connection;
        try {
            return Cache::lock('photohub-studio-connection-'.$business->id, 90)->block(5, function () use ($business, $connection) {
                $connection->refresh();
                $url = $this->validateUrl($connection->base_url);
                if (! $connection->api_token) {
                    // UUID + persistent secret make a lost registration response safely retryable.
                    // Only public studio branding is sent, never customers or internal data.
                    $result = Http::baseUrl($url)->acceptJson()->connectTimeout(10)->timeout(30)->withoutRedirecting()
                        ->post('/api/share/v1/studios/register', ['studio_uuid' => $connection->identity_uuid,
                            'registration_secret' => $connection->registration_secret, 'name' => $business->name,
                            'currency' => $business->currency, 'app_version' => config('photohub.app_version')])->throw()->json();
                    if (! is_array($result) || ! is_numeric($result['cloud_studio_id'] ?? null) || ! is_string($result['studio_token'] ?? null) || strlen($result['studio_token']) < 48) {
                        throw new CloudConnectionException('Cloud registration returned an incomplete response. Please retry.');
                    }
                    $connection->update(['cloud_studio_id' => (int) $result['cloud_studio_id'], 'api_token' => $result['studio_token'], 'registered_at' => now()]);
                }
                $health = Http::baseUrl($url.'/api/sync/v1')->withToken($connection->api_token)->acceptJson()
                    ->connectTimeout(10)->timeout(30)->withoutRedirecting()->get('health')->throw()->json();
                $this->acceptHealth($connection, $health);
                return $connection->fresh();
            });
        } catch (Throwable $error) {
            $message = $this->safeError($error);
            $connection->update(['status' => 'failed', 'last_error' => $message]);
            throw new CloudConnectionException($message);
        }
    }

    public function acceptHealth(StudioCloudConnection $connection, mixed $health): void
    {
        if (! is_array($health) || ($health['connected'] ?? false) !== true) {
            throw new CloudConnectionException('The cloud did not confirm the studio connection. Check the cloud address and try again.');
        }
        $id = $health['cloud_studio_id'] ?? $health['business_id'] ?? null;
        if ($id !== null && (filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id < 1)) {
            throw new CloudConnectionException('The cloud returned an invalid studio identity. Please retry.');
        }
        if ($connection->cloud_studio_id && $id !== null && (int) $id !== (int) $connection->cloud_studio_id) {
            throw new CloudConnectionException('These credentials belong to a different cloud studio. Existing gallery links have been preserved.');
        }
        // Older servers return only connected/storage. Keep their valid token;
        // discover the cloud ID when the backwards-compatible health API is upgraded.
        $connection->update(['cloud_studio_id' => $id ?? $connection->cloud_studio_id,
            'status' => 'connected', 'last_connected_at' => now(), 'last_error' => null]);
        if (isset($health['storage'])) Cache::put('photohub-cloud-storage-'.$connection->business_id, $health['storage'], 86400);
    }

    public function safeError(Throwable $error): string
    {
        if ($error instanceof CloudConnectionException) return $error->getMessage();
        if ($error instanceof \Illuminate\Http\Client\RequestException) {
            return match ($error->response->status()) {
                401, 403 => 'The cloud refused this studio connection. Its token may be revoked or cloud access suspended. Check Cloud Connection or contact the cloud administrator.',
                404 => 'The cloud server needs the studio-registration update. Existing connected studios can keep sharing; deploy the cloud update to connect this studio automatically.',
                409 => 'This studio identity could not be recovered securely. Check Cloud Connection; existing links have not been changed.',
                429 => 'Too many connection attempts. Please wait a moment and try again.',
                default => 'Could not connect to PhotoHub Cloud. Please retry. Your local work is safe.',
            };
        }
        return 'Internet connection is required to connect this studio or create an online customer link. Your local gallery and photos are safe.';
    }
}
