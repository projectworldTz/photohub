<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PackageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('settings.manage') ?? false;
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:150'], 'category' => ['required', 'string', 'max:100'], 'description' => ['nullable', 'string', 'max:3000'], 'price' => ['required', 'numeric', 'min:0'], 'deposit_amount' => ['required', 'numeric', 'min:0', 'lte:price'], 'duration_minutes' => ['required', 'integer', 'min:15', 'max:10080'], 'photographers_count' => ['required', 'integer', 'min:1', 'max:50'], 'photos_count' => ['nullable', 'integer', 'min:0'], 'edited_photos_count' => ['nullable', 'integer', 'min:0'], 'delivery_days' => ['required', 'integer', 'min:0', 'max:365'], 'album_included' => ['boolean'], 'video_included' => ['boolean'], 'drone_included' => ['boolean'], 'prints_included' => ['boolean'], 'is_active' => ['boolean']];
    }

    protected function prepareForValidation(): void
    {
        foreach (['album_included', 'video_included', 'drone_included', 'prints_included', 'is_active'] as $key) {
            $this->merge([$key => $this->boolean($key)]);
        }
    }
}
