<?php

namespace App\Services;

use App\Exceptions\SelectionSyncConflict;
use App\Models\Gallery;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class SelectionSyncService
{
    public function apply(Gallery $gallery, array $data): void
    {
        Validator::make($data, ['gallery_uuid' => 'required|in:'.$gallery->sync_uuid, 'submitted_at' => 'nullable|date', 'photo_uuids' => 'present|array|max:10000', 'photo_uuids.*' => 'uuid|distinct'])->validate();
        if (empty($data['submitted_at'])) {
            return;
        }
        DB::transaction(function () use ($gallery, $data) {
            $gallery = Gallery::lockForUpdate()->findOrFail($gallery->id);
            $photos = $gallery->photos()->where('business_id', $gallery->business_id)->whereIn('uuid', $data['photo_uuids'])->pluck('id');
            if ($photos->count() !== count($data['photo_uuids']) || ! $gallery->customer_id) {
                throw new SelectionSyncConflict('Selection contains unknown photos.');
            }
            $existing = DB::table('photo_selections')->whereIn('photo_id', $gallery->photos()->select('id'))->where('customer_id', $gallery->customer_id)->pluck('photo_id');
            if ($gallery->selection_completed_at && $existing->sort()->values()->all() !== $photos->sort()->values()->all()) {
                throw new SelectionSyncConflict('Selection conflict: local submission differs.');
            }
            DB::table('photo_selections')->whereIn('photo_id', $gallery->photos()->select('id'))->where('customer_id', $gallery->customer_id)->delete();
            foreach ($photos as $id) {
                DB::table('photo_selections')->insert(['photo_id' => $id, 'customer_id' => $gallery->customer_id, 'selected_at' => $data['submitted_at'] ? Carbon::parse($data['submitted_at']) : now()]);
            }
            if (! $gallery->selection_completed_at) {
                app(PhotoSelectionService::class)->complete($gallery, $gallery->customer_id);
            }
            $gallery->forceFill(['selection_synced_at' => now()])->save();
        });
    }
}
