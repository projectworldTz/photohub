<?php

namespace App\Services;

use App\Events\PaymentReceived;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function create(array $data, array $items): Invoice
    {
        return DB::transaction(function () use ($data, $items) {
            $subtotal = collect($items)->sum(fn ($i) => (float) $i['quantity'] * (float) $i['unit_price']);
            $total = max(0, $subtotal - (float) ($data['discount'] ?? 0) + (float) ($data['tax'] ?? 0));
            $invoice = Invoice::create($data + ['invoice_number' => app(NumberSeriesService::class)->next(Invoice::class, $data['business_id'], 'invoice_number', 'INV', true), 'subtotal' => $subtotal, 'total' => $total, 'paid' => 0, 'balance' => $total, 'status' => 'unpaid']);
            $invoice->items()->createMany(collect($items)->map(fn ($i) => $i + ['total' => (float) $i['quantity'] * (float) $i['unit_price']])->all());

            return $invoice;
        });
    }

    public function pay(Invoice $invoice, array $data): Payment
    {
        return DB::transaction(function () use ($invoice, $data) {
            if ((float) $data['amount'] <= 0 || (float) $data['amount'] > (float) $invoice->balance) {
                throw ValidationException::withMessages(['amount' => 'Payment must be greater than zero and cannot exceed the invoice balance.']);
            }$payment = Payment::create($data + ['business_id' => $invoice->business_id, 'customer_id' => $invoice->customer_id, 'booking_id' => $invoice->booking_id, 'invoice_id' => $invoice->id, 'status' => 'completed']);
            $paid = (float) $invoice->paid + (float) $payment->amount;
            $balance = max(0, (float) $invoice->total - $paid);
            $invoice->update(['paid' => $paid, 'balance' => $balance, 'status' => $balance == 0 ? 'paid' : 'partially_paid']);
            Receipt::create(['business_id' => $invoice->business_id, 'receipt_number' => 'RCPT-'.now()->format('Y').'-'.str_pad((string) $payment->id, 6, '0', STR_PAD_LEFT), 'payment_id' => $payment->id, 'customer_id' => $invoice->customer_id, 'invoice_id' => $invoice->id, 'amount' => $payment->amount]);
            PaymentReceived::dispatch($payment);

            return $payment;
        });
    }
}
