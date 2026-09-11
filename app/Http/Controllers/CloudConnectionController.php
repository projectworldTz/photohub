<?php

namespace App\Http\Controllers;

use App\Services\CloudStudioService;
use App\Exceptions\CloudConnectionException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class CloudConnectionController extends Controller
{
    public function test(Request $request, CloudStudioService $cloud)
    {
        $data = $request->validate(['base_url' => 'required|url|max:255', 'cloud_api_token' => 'nullable|string|min:48|max:512']);
        $business = app('currentBusiness');
        $connection = $cloud->connection($business);
        try {
            if (! config('photohub.cloud_enabled')) {
                throw new CloudConnectionException('Online sharing is disabled. Your local studio and photos remain available.');
            }
            $url = $cloud->validateUrl($data['base_url']);
            if ($connection->api_token && $url !== rtrim($connection->base_url, '/')) {
                throw new CloudConnectionException('This studio already has a cloud connection. Keep its cloud address to preserve existing customer links.');
            }
            if (! empty($data['cloud_api_token'])) {
                if ($connection->api_token && ! $connection->cloud_studio_id) {
                    throw new CloudConnectionException('Test the existing connection after updating the cloud server before replacing this token. The cloud studio ID must be verified first.');
                }
                // Verify a replacement before overwriting working credentials.
                $health = Http::baseUrl($url.'/api/sync/v1')->withToken($data['cloud_api_token'])->acceptJson()
                    ->withoutRedirecting()->connectTimeout(10)->timeout(30)->get('health')->throw()->json();
                if (! is_array($health) || empty($health['cloud_studio_id'])) {
                    throw new CloudConnectionException('Update the cloud server before replacing credentials so studio ownership can be verified.');
                }
                $cloud->acceptHealth($connection, $health);
                $connection->update(['api_token' => $data['cloud_api_token'], 'base_url' => $url]);
            } else {
                $connection->update(['base_url' => $url]);
                $cloud->ensure($business, true);
            }
            return back()->with('success', 'Connected to PhotoHub Cloud. All galleries in this studio use this connection.');
        } catch (\Throwable $error) {
            return back()->withErrors(['cloud_connection' => $cloud->safeError($error)]);
        }
    }
}
