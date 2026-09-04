<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class BookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('bookings.manage') ?? false;
    }

    public function rules(): array
    {
        $bid = (int) session('business_id');

        return ['customer_id' => ['required', Rule::exists('customers', 'id')->where('business_id', $bid)], 'package_id' => ['nullable', Rule::exists('packages', 'id')->where('business_id', $bid)], 'event_type' => 'required|string|max:100', 'event_date' => 'required|date', 'start_time' => 'required|date_format:H:i', 'end_time' => 'required|date_format:H:i|after:start_time', 'location' => 'required|string|max:255', 'guests' => 'nullable|integer|min:0', 'notes' => 'nullable|string|max:3000', 'total_cost' => 'required|numeric|min:0', 'deposit' => 'required|numeric|min:0|lte:total_cost', 'status' => 'required|in:inquiry,pending,confirmed,deposit_paid,scheduled,in_progress,completed,cancelled,rescheduled', 'expected_delivery_date' => 'nullable|date|after_or_equal:event_date', 'staff_ids' => 'required|array|min:1', 'staff_ids.*' => [Rule::exists('business_user', 'id')->where('business_id', $bid)]];
    }
}
