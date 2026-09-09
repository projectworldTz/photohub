<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Gallery;
use App\Models\Invoice;
use App\Models\Payment;
use App\Models\Photo;
use App\Models\Shoot;
use App\Services\DashboardAnalyticsService;
use App\Services\StorageQuotaService;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function __invoke(DashboardAnalyticsService $analyticsService): View|RedirectResponse
    {
        if (auth()->user()->is_super_admin) {
            return redirect()->route('admin.index');
        }
        $business = app('currentBusiness');

        return view('dashboard', [
            'staffCount' => $business->memberships()->where('status', 'active')->count(),
            'todayShoots' => Shoot::forBusiness($business->id)->whereDate('shoot_date', today())->count(),
            'upcomingBookings' => Booking::forBusiness($business->id)->whereBetween('event_date', [today(), today()->addDays(30)])->count(),
            'customersCount' => Customer::forBusiness($business->id)->count(),
            'galleriesCount' => Gallery::forBusiness($business->id)->count(),
            'photosCount' => Photo::forBusiness($business->id)->count(),
            'storageMb' => round(app(StorageQuotaService::class)->usage($business)['used'] / 1048576, 1),
            'pendingEditing' => Shoot::forBusiness($business->id)->whereIn('status', ['photos_uploaded', 'editing'])->count(),
            'awaitingDelivery' => Gallery::forBusiness($business->id)->whereIn('status', ['final', 'selection_completed'])->count(),
            'unpaidInvoices' => Invoice::forBusiness($business->id)->whereIn('status', ['unpaid', 'partially_paid', 'overdue'])->count(),
            'outstanding' => Invoice::forBusiness($business->id)->sum('balance'),
            'monthlyRevenue' => Payment::forBusiness($business->id)->whereMonth('payment_date', now()->month)->whereYear('payment_date', now()->year)->sum('amount'),
            'monthlyExpenses' => Expense::forBusiness($business->id)->whereMonth('date', now()->month)->whereYear('date', now()->year)->sum('amount'),
            'activity' => ActivityLog::with('user')->where('business_id', $business->id)->latest()->limit(8)->get(),
            'analytics' => $analyticsService->get($business->id),
        ]);
    }
}
