<?php

namespace App\Services;

use Illuminate\Database\Eloquent\SoftDeletes;

class NumberSeriesService
{
    public function next(string $modelClass, int $businessId, string $column, string $prefix, bool $withYear = false): string
    {
        $stem = $prefix.'-'.($withYear ? now()->format('Y').'-' : '');
        $query = $modelClass::query();
        if (in_array(SoftDeletes::class, class_uses_recursive($modelClass), true)) {
            $query->withTrashed();
        }
        $last = $query->forBusiness($businessId)->where($column, 'like', $stem.'%')->orderByDesc('id')->value($column);
        $number = $last ? (int) str($last)->afterLast('-')->value() + 1 : 1;

        return $stem.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
