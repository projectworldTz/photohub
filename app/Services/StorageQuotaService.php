<?php

namespace App\Services;

use App\Models\Business;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use RuntimeException;

class StorageQuotaService
{
    public const GB = 1073741824;

    public function locked(Business $business, callable $callback): mixed
    {
        return DB::transaction(function () use ($business, $callback) {
            $locked = Business::withTrashed()->lockForUpdate()->findOrFail($business->id);
            if ($locked->storage_limit_bytes === null) {
                $this->reconcileLocked($locked);
            }

            return $callback($locked);
        });
    }

    public function usage(Business $business): array
    {
        if (PhotoStorage::isLocal()) {
            return Cache::get('photohub-cloud-storage-'.$business->id) ?: ['used' => 0, 'limit' => (int) $business->storage_limit_bytes, 'remaining' => (int) $business->storage_limit_bytes, 'percent' => 0, 'status' => 'Cloud usage not checked', 'color' => 'secondary'];
        }
        if ($business->storage_limit_bytes === null) {
            $this->locked($business, fn () => null);
            $business->refresh();
        }
        $used = (int) DB::table('storage_files')->where('business_id', $business->id)->sum('size_bytes');
        $limit = (int) $business->storage_limit_bytes;
        $percent = $limit > 0 ? round($used / $limit * 100, 1) : 100;

        return ['used' => $used, 'limit' => $limit, 'remaining' => max(0, $limit - $used), 'percent' => $percent,
            'status' => $used >= $limit ? 'Storage full' : ($percent >= 90 ? 'Almost full' : ($percent >= 70 ? 'Warning' : 'Normal')),
            'color' => $percent >= 90 ? 'danger' : ($percent >= 70 ? 'warning' : 'primary')];
    }

    public function assertFits(Business $business, int $bytes): void
    {
        if (PhotoStorage::isLocal()) {
            return;
        }
        $usage = $this->usage($business);
        if ($bytes < 0 || $bytes > $usage['limit'] - $usage['used']) {
            throw ValidationException::withMessages(['photos' => 'Storage limit reached. You have used '.self::format($usage['used']).' of your '.self::format($usage['limit']).' storage. Please contact the administrator for additional storage or arrange safe removal of old files.']);
        }
    }

    public static function format(int $bytes): string
    {
        foreach (['GB' => self::GB, 'MB' => 1048576, 'KB' => 1024] as $unit => $divisor) {
            if ($bytes >= $divisor) {
                return rtrim(rtrim(number_format($bytes / $divisor, 2, '.', ''), '0'), '.').' '.$unit;
            }
        }

        return $bytes.' B';
    }

    public function record(int $businessId, string $path, int $bytes): void
    {
        if (str_starts_with($path, 'studios/')) {
            return;
        }
        $hash = hash('sha256', $path);
        $existing = DB::table('storage_files')->where('path_hash', $hash)->first();
        if ($existing && (int) $existing->business_id !== $businessId) {
            throw new RuntimeException('Storage ownership conflict: '.$path);
        }
        DB::table('storage_files')->updateOrInsert(['path_hash' => $hash], ['business_id' => $businessId, 'path' => $path, 'size_bytes' => $bytes]);
    }

    public function reconcile(Business $business): void
    {
        DB::transaction(function () use ($business) {
            $this->reconcileLocked(Business::withTrashed()->lockForUpdate()->findOrFail($business->id));
        });
    }

    private function reconcileLocked(Business $business): void
    {
        $disk = Storage::disk('local');
        $paths = $disk->allFiles('businesses/'.$business->id);
        // Include legacy paths and soft-deleted records. Deduplicate shared variants.
        foreach (['photos', 'final_photos'] as $table) {
            DB::table($table)->where('business_id', $business->id)->orderBy('id')->chunkById(200, function ($rows) use (&$paths) {
                foreach ($rows as $row) {
                    foreach (['original_path', 'preview_path', 'thumbnail_path'] as $field) {
                        if ($row->{$field}) {
                            $paths[] = $row->{$field};
                        }
                    }
                }
            });
        }
        $paths = array_unique(array_merge($paths, DB::table('storage_files')->where('business_id', $business->id)->pluck('path')->all()));
        foreach ($paths as $path) {
            if (str_starts_with($path, 'studios/')) {
                continue;
            }
            $this->assertOwnedPath($business->id, $path);
            if ($disk->exists($path)) {
                $this->record($business->id, $path, $disk->size($path));
            } else {
                DB::table('storage_files')->where('business_id', $business->id)->where('path_hash', hash('sha256', $path))->delete();
            }
        }
        if ($business->storage_limit_bytes === null) {
            $used = (int) DB::table('storage_files')->where('business_id', $business->id)->sum('size_bytes');
            $business->storage_limit_bytes = max(3, (int) ceil($used / self::GB)) * self::GB;
            DB::table('businesses')->where('id', $business->id)->update(['storage_limit_bytes' => $business->storage_limit_bytes]);
        }
    }

    public function assertOwnedPath(int $businessId, string $path): void
    {
        if (str_contains($path, '..') || str_contains($path, '\\') || str_starts_with($path, '/') || str_contains($path, ':') ||
            (preg_match('#^businesses/(\d+)/#', $path, $matches) && (int) $matches[1] !== $businessId)) {
            throw new RuntimeException('Unsafe or foreign storage path.');
        }
        foreach (['photos', 'final_photos'] as $table) {
            if (DB::table($table)->where('business_id', '!=', $businessId)->where(fn ($q) => $q->where('original_path', $path)->orWhere('preview_path', $path)->orWhere('thumbnail_path', $path))->exists()) {
                throw new RuntimeException('Storage path is referenced by another studio.');
            }
        }
    }

    // Only explicit deletion calls this method; expiry and archival never do.
    // Each successful physical deletion releases its own bytes. Failures retain inventory.
    public function deleteFile(Business $business, string $path): void
    {
        $this->locked($business, function ($locked) use ($path) {
            $this->assertOwnedPath($locked->id, $path);
            $entry = DB::table('storage_files')->where('business_id', $locked->id)->where('path_hash', hash('sha256', $path))->first();
            if (! $entry) {
                throw new RuntimeException('File is not in this studio storage inventory.');
            }
            $disk = Storage::disk('local');
            if ($disk->exists($path) && ! $disk->delete($path)) {
                throw new RuntimeException('File could not be deleted; storage remains allocated.');
            }
            DB::table('storage_files')->where('id', $entry->id)->delete();
        });
    }
}
