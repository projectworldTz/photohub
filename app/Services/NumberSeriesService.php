<?php

namespace App\Services;

class NumberSeriesService
{
    public function next(string $modelClass, int $businessId, string $column, string $prefix, bool $withYear = false): string
    {
        $stem = $prefix.'-'.($withYear ? now()->format('Y').'-' : '');
        $last = $modelClass::withTrashed()->forBusiness($businessId)->where($column, 'like', $stem.'%')->orderByDesc('id')->value($column);
        $number = $last ? (int) str($last)->afterLast('-')->value() + 1 : 1;

        return $stem.str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }
}
