<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GalleryRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->hasPermission('galleries.manage') ?? false;
    }

    public function rules(): array
    {
        $b = (int) session('business_id');

        return ['name' => 'required|string|max:150', 'customer_id' => ['nullable', Rule::requiredIf(fn () => ! in_array($this->input('type'), ['public_event', 'portfolio'], true)), Rule::exists('customers', 'id')->where('business_id', $b)], 'booking_id' => ['nullable', Rule::exists('bookings', 'id')->where('business_id', $b)], 'shoot_id' => ['nullable', Rule::exists('shoots', 'id')->where('business_id', $b)], 'event' => 'nullable|string|max:150', 'event_date' => 'nullable|date', 'description' => 'nullable|string|max:3000', 'type' => 'required|in:selection,final_delivery,proof,final,public_event,private_event,portfolio', 'privacy' => 'required|in:public,private,pin,customer', 'pin' => ['nullable', Rule::requiredIf(fn () => $this->input('privacy') === 'pin' && ! $this->route('gallery')?->pin_hash), 'string', 'min:4', 'max:20'], 'expires_at' => 'nullable|date|after:now', 'downloads_enabled' => 'boolean', 'payment_required' => 'boolean', 'watermark_enabled' => 'boolean', 'selection_limit' => 'nullable|integer|min:1', 'require_exact_selection' => 'boolean', 'extra_photo_price' => 'nullable|numeric|min:0', 'photo_price' => 'nullable|numeric|min:0', 'status' => 'required|in:draft,proofs_uploading,proofs_ready,selection_link_ready,selection_sent,customer_selecting,selection_submitted,selection_reopened,editing,final_upload_in_progress,final_ready,final_published,delivered,expired,archived,processing,published,awaiting_selection,selection_completed,final'];
    }

    protected function prepareForValidation(): void
    {
        foreach (['downloads_enabled', 'payment_required', 'watermark_enabled', 'require_exact_selection'] as $x) {
            $this->merge([$x => $this->boolean($x)]);
        }
    }
}
