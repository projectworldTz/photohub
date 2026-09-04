<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

class RegisterBusinessRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'business_name' => ['required', 'string', 'max:150'],
            'owner_name' => ['required', 'string', 'max:120'],
            'phone' => ['required', 'string', 'max:30'],
            'email' => ['required', 'email', 'max:150', 'unique:users,email'],
            'address' => ['nullable', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:100'],
            'country' => ['required', 'string', 'max:100'],
            'description' => ['nullable', 'string', 'max:2000'],
            'category' => ['required', 'string', 'max:100'],
            'currency' => ['required', 'string', 'size:3'],
            'timezone' => ['required', 'timezone'],
            'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()],
        ];
    }
}
