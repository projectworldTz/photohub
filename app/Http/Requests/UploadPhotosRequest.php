<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UploadPhotosRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('galleries.manage') ?? false;
    }

    public function rules(): array
    {
        return ['photos' => 'required|array|min:1|max:100', 'photos.*' => 'required|image|mimes:jpg,jpeg,png,webp|max:20480', 'is_final' => 'boolean'];
    }
}
