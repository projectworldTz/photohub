<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\Quotation;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class QuotationService
{
    public function create(array $data, array $items): Quotation
    {
        return DB::transaction(function () use ($data, $items) {
            $subtotal = collect($items)->sum(fn ($i) => (float) $i['quantity'] * (float) $i['unit_price']);
            $total = max(0, $subtotal - (float) ($data['discount'] ?? 0) + (float) ($data['tax'] ?? 0));
            $q = Quotation::create($data + ['quotation_number' => 'QT-'.now()->format('Y').'-'.str_pad((string) ((Quotation::forBusiness($data['business_id'])->max('id') ?? 0) + 1), 6, '0', STR_PAD_LEFT), 'subtotal' => $subtotal, 'total' => $total, 'status' => 'draft']);
            $q->items()->createMany(collect($items)->map(fn ($i) => $i + ['total' => (float) $i['quantity'] * (float) $i['unit_price']])->all());

            return $q;
        });
    }

    public function convert(Quotation $q): Invoice
    {
        if ($q->invoice_id) {
            throw ValidationException::withMessages(['quotation' => 'This quotation has already been converted.']);
        }
        if ($q->status !== 'accepted') {
            throw ValidationException::withMessages(['quotation' => 'Accept the quotation before converting it to an invoice.']);
        }

        return DB::transaction(function () use ($q) {
            $invoice = app(InvoiceService::class)->create(['business_id' => $q->business_id, 'customer_id' => $q->customer_id, 'booking_id' => $q->booking_id, 'discount' => $q->discount, 'tax' => $q->tax, 'due_date' => now()->addDays(14), 'notes' => 'Converted from '.$q->quotation_number], $q->items->map->only(['description', 'quantity', 'unit_price'])->all());
            $q->update(['invoice_id' => $invoice->id, 'status' => 'converted']);

            return $invoice;
        });
    }
}
