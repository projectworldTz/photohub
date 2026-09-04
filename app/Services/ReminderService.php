<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\Business;
use App\Models\Gallery;
use App\Models\Invoice;
use App\Models\Shoot;

class ReminderService
{
    public function send(): void
    {
        Business::where('status', 'active')->each(function (Business $business) {
            $tomorrow = Booking::forBusiness($business->id)->whereDate('event_date', today()->addDay())->count();
            $overdue = Invoice::forBusiness($business->id)->whereIn('status', ['unpaid', 'partially_paid', 'overdue'])->whereDate('due_date', '<=', today())->count();
            $deliveries = Shoot::forBusiness($business->id)->whereNotIn('status', ['delivered'])->whereBetween('expected_delivery_date', [today(), today()->addDays(2)])->count();
            $expiring = Gallery::forBusiness($business->id)->whereNotIn('status', ['archived'])->whereBetween('expires_at', [now(), now()->addDays(2)])->count();
            $service = app(NotificationService::class);
            if ($tomorrow) {
                $service->business($business, 'Shoots tomorrow', "{$tomorrow} booking(s) are scheduled tomorrow.", route('calendar'));
            }
            if ($overdue) {
                $service->business($business, 'Payment follow-up', "{$overdue} invoice(s) require payment follow-up.", route('invoices.index'));
            }
            if ($deliveries) {
                $service->business($business, 'Delivery deadlines', "{$deliveries} shoot delivery deadline(s) are due soon.", route('shoots.index'));
            }
            if ($expiring) {
                $service->business($business, 'Galleries expiring soon', "{$expiring} gallery link(s) expire within two days.", route('galleries.index'));
            }
        });
    }
}
