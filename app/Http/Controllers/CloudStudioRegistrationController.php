<?php

namespace App\Http\Controllers;

use App\Models\Business;
use App\Services\StorageQuotaService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class CloudStudioRegistrationController extends Controller
{
    public function register(Request $request)
    {
        abort_unless(config('photohub.mode') === 'cloud', 404);
        abort_unless($request->isSecure() || config('photohub.allow_http'), 403);
        abort_unless(config('photohub.registration_enabled'), 503, 'Studio registration is currently unavailable.');
        $data = $request->validate(['studio_uuid' => 'required|uuid', 'registration_secret' => 'required|string|min:64|max:128',
            'name' => 'required|string|max:150', 'currency' => 'nullable|string|size:3', 'app_version' => 'nullable|string|max:50']);
        return DB::transaction(function () use ($data) {
            $business = Business::withTrashed()->where('local_studio_uuid', $data['studio_uuid'])->lockForUpdate()->first();
            $fresh = ! $business;
            if ($business) {
                abort_unless(! $business->trashed() && $business->status === 'active', 403);
                abort_unless(hash_equals((string) $business->registration_key_hash, hash('sha256', $data['registration_secret'])), 403);
            } else {
                // Identity ownership is proven by a secret, never by a supplied business
                // ID or email address. No users, staff or full business setup is created.
                $business = new Business(['name' => $data['name'], 'slug' => 'studio-'.$data['studio_uuid'],
                    'email' => $data['studio_uuid'].'@studios.photohub.invalid', 'status' => 'active',
                    'currency' => strtoupper($data['currency'] ?? 'TZS')]);
                $business->forceFill(['local_studio_uuid' => $data['studio_uuid'], 'cloud_identity_only' => true,
                    'credential_generation' => 1, 'registration_key_hash' => hash('sha256', $data['registration_secret'])])->save();
            }
            // Deterministic credential recovery handles a lost first response. Only
            // hashes are stored remotely. Revocation cannot be undone by registering again.
            $token = hash_hmac('sha512', $data['studio_uuid'].'|'.$business->credential_generation.'|'.$data['registration_secret'], config('app.key'));
            $hash = hash('sha256', $token);
            if ($fresh) {
                DB::table('studio_api_tokens')->insert(['business_id' => $business->id, 'token_hash' => $hash, 'created_at' => now(), 'updated_at' => now()]);
            } else {
                abort_unless(DB::table('studio_api_tokens')->where('business_id', $business->id)->where('token_hash', $hash)->whereNull('revoked_at')->exists(), 403);
            }
            $business->forceFill(['cloud_last_seen_at' => now(), 'app_version' => $data['app_version'] ?? $business->app_version])->save();
            return response()->json(['cloud_studio_id' => $business->id, 'studio_token' => $token,
                'storage_quota' => $business->storage_limit_bytes])->header('Cache-Control', 'no-store');
        });
    }
}
