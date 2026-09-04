<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LeadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('customers.manage') ?? false;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:150'], 'phone' => ['required', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:150'], 'event_type' => ['nullable', 'string', 'max:100'], 'event_date' => ['nullable', 'date'], 'start_time' => ['nullable', 'date_format:H:i'], 'end_time' => ['nullable', 'date_format:H:i', 'after:start_time'], 'estimated_budget' => ['nullable', 'numeric', 'min:0'], 'message' => ['nullable', 'string', 'max:3000'], 'source' => ['nullable', 'string', 'max:100'], 'status' => ['required', 'in:new,contacted,interested,quotation_sent,negotiating,won,lost']];
    }
}
