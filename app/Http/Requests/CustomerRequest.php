<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('customers.manage') ?? false;
    }

    public function rules(): array
    {
        return ['first_name' => ['required', 'string', 'max:80'], 'last_name' => ['required', 'string', 'max:80'], 'phone' => ['required', 'string', 'max:30'], 'whatsapp' => ['nullable', 'string', 'max:30'], 'email' => ['nullable', 'email', 'max:150'], 'gender' => ['nullable', 'in:male,female,other'], 'address' => ['nullable', 'string', 'max:255'], 'city' => ['nullable', 'string', 'max:100'], 'country' => ['nullable', 'string', 'max:100'], 'date_of_birth' => ['nullable', 'date', 'before:today'], 'notes' => ['nullable', 'string', 'max:3000'], 'status' => ['required', 'in:active,inactive,archived']];
    }
}
