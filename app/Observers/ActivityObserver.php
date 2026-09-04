<?php

namespace App\Observers;

use App\Models\ActivityLog;
use Illuminate\Database\Eloquent\Model;

class ActivityObserver
{
    public function created(Model $model): void
    {
        $this->record($model, 'created');
    }

    public function updated(Model $model): void
    {
        $this->record($model, 'updated');
    }

    public function deleted(Model $model): void
    {
        $this->record($model, 'deleted');
    }

    private function record(Model $model, string $verb): void
    {
        $businessId = $model->getAttribute('business_id');
        if (! $businessId) {
            return;
        }
        ActivityLog::create([
            'business_id' => $businessId,
            'user_id' => auth()->id(),
            'action' => str(class_basename($model))->snake().'.'.$verb,
            'subject_type' => $model::class,
            'subject_id' => $model->getKey(),
            'properties' => $verb === 'updated' ? ['changes' => $model->getChanges()] : null,
            'ip_address' => app()->runningInConsole() ? null : request()->ip(),
        ]);
    }
}
