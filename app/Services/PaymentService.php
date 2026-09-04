<?php

namespace App\Services;

use App\Events\OrderPaid;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Receipt;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function payOrder(Order $order, array $data): Payment
    {
        return DB::transaction(function () use ($order, $data) {
            if ($order->payment_status === 'paid' || (float) $data['amount'] !== (float) $order->total) {
                throw ValidationException::withMessages(['amount' => 'The order payment must equal the outstanding order total.']);
            }
            $payment = Payment::create($data + ['business_id' => $order->business_id, 'customer_id' => $order->customer_id, 'order_id' => $order->id, 'status' => 'completed']);
            $receipt = 'RCPT-'.now()->format('Y').'-'.str_pad((string) ((Receipt::forBusiness($order->business_id)->max('id') ?? 0) + 1), 6, '0', STR_PAD_LEFT);
            Receipt::create(['business_id' => $order->business_id, 'receipt_number' => $receipt, 'payment_id' => $payment->id, 'customer_id' => $order->customer_id, 'order_id' => $order->id, 'amount' => $payment->amount]);
            $order->update(['payment_status' => 'paid', 'status' => 'paid']);
            OrderPaid::dispatch($order);

            return $payment;
        });
    }

    public function reverse(Payment $payment, string $reason, int $userId): void
    {
        DB::transaction(function () use ($payment, $reason, $userId) {
            if ($payment->status !== 'completed') {
                throw ValidationException::withMessages(['payment' => 'Only completed payments can be reversed.']);
            }
            $payment->update(['status' => 'reversed', 'reversed_at' => now(), 'reversed_by' => $userId, 'reversal_reason' => $reason]);
            if ($payment->invoice_id) {
                $invoice = Invoice::lockForUpdate()->findOrFail($payment->invoice_id);
                $paid = max(0, (float) $invoice->paid - (float) $payment->amount);
                $balance = (float) $invoice->total - $paid;
                $invoice->update(['paid' => $paid, 'balance' => $balance, 'status' => $paid > 0 ? 'partially_paid' : 'unpaid']);
            }
            if ($payment->order_id) {
                Order::whereKey($payment->order_id)->update(['payment_status' => 'unpaid', 'status' => 'awaiting_payment']);
            }
        });
    }
}
