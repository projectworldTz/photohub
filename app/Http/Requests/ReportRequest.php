<?php

namespace App\Http\Requests;

use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class ReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Existing reports.view route middleware handles authorization.
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'from' => $this->input('from', now()->startOfYear()->toDateString()),
            'to' => $this->input('to', now()->toDateString()),
            'interval' => $this->input('interval', 'auto'),
            'compare' => $this->input('compare', '1'),
        ]);
    }

    public function rules(): array
    {
        return ['from' => 'required|date_format:Y-m-d|before_or_equal:to', 'to' => 'required|date_format:Y-m-d', 'interval' => 'required|in:auto,day,week,month', 'compare' => 'required|boolean'];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            $days = Carbon::parse($this->input('from'))->diffInDays(Carbon::parse($this->input('to'))) + 1;
            if ($days > 3660) {
                $validator->errors()->add('to', 'Choose a date range of ten years or less.');
            } elseif ($this->input('interval') === 'day' && $days > 366) {
                $validator->errors()->add('interval', 'For more than one year, choose weekly, monthly or automatic grouping.');
            }
        });
    }

    public function period(): array
    {
        return [Carbon::parse($this->validated('from'))->startOfDay(), Carbon::parse($this->validated('to'))->endOfDay()];
    }
}
