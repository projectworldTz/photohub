<?php

namespace App\Providers;

use App\Events\GalleryPublished;
use App\Events\OrderPaid;
use App\Events\PaymentReceived;
use App\Events\PhotoSelectionCompleted;
use App\Listeners\SendBusinessEventNotification;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Gallery;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Photo;
use App\Models\Shoot;
use App\Observers\ActivityObserver;
use App\Services\AI\DisabledPhotoAnalysisService;
use App\Services\AI\PhotoAnalysisInterface;
use App\Services\Payments\ManualPaymentGateway;
use App\Services\Payments\PaymentGatewayInterface;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(PhotoAnalysisInterface::class, DisabledPhotoAnalysisService::class);
        $this->app->bind(PaymentGatewayInterface::class, ManualPaymentGateway::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        foreach ([PaymentReceived::class, OrderPaid::class, GalleryPublished::class, PhotoSelectionCompleted::class] as $event) {
            Event::listen($event, SendBusinessEventNotification::class);
        }
        foreach ([Customer::class, Booking::class, Shoot::class, Invoice::class, Payment::class, Gallery::class, Photo::class, Order::class] as $model) {
            $model::observe(ActivityObserver::class);
        }
    }
}
