<?php

namespace App\Listeners;

use App\Events\GalleryPublished;
use App\Events\OrderPaid;
use App\Events\PaymentReceived;
use App\Events\PhotoSelectionCompleted;
use App\Models\Business;
use App\Services\NotificationService;

class SendBusinessEventNotification
{
    public function __construct(private NotificationService $notifications) {}

    public function handle(object $event): void
    {
        [$businessId, $title, $message, $url] = match (true) {
            $event instanceof PaymentReceived => [$event->payment->business_id, 'Payment received', 'Payment of '.$event->payment->amount.' was recorded.', route('invoices.index')],
            $event instanceof OrderPaid => [$event->order->business_id, 'Photo order paid', $event->order->order_number.' was paid.', route('orders.index')],
            $event instanceof GalleryPublished => [$event->gallery->business_id, 'Gallery published', $event->gallery->name.' is now available.', route('galleries.show', $event->gallery)],
            $event instanceof PhotoSelectionCompleted => [$event->gallery->business_id, 'New Photo Selection Received', ($event->gallery->customer?->full_name ?? 'Customer').' selected '.$event->gallery->submitted_selection_count.' of '.($event->gallery->selection_limit ?: $event->gallery->submitted_selection_count).' photos in '.$event->gallery->name.'.', route('galleries.workflow', $event->gallery)],
        };
        $business = Business::find($businessId);
        if ($business) {
            $this->notifications->business($business, $title, $message, $url);
        }
    }
}
