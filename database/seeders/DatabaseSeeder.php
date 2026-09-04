<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Customer;
use App\Models\Expense;
use App\Models\Gallery;
use App\Models\Invoice;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Receipt;
use App\Models\Role;
use App\Models\Shoot;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Services\GalleryAccessService;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = collect(['dashboard.view' => 'Dashboard', 'customers.view' => 'CRM', 'customers.manage' => 'CRM', 'bookings.view' => 'Bookings', 'bookings.manage' => 'Bookings', 'galleries.view' => 'Galleries', 'galleries.manage' => 'Galleries', 'finance.view' => 'Finance', 'finance.manage' => 'Finance', 'staff.view' => 'Staff', 'staff.manage' => 'Staff', 'reports.view' => 'Reports', 'settings.manage' => 'Settings'])->map(fn ($group, $slug) => Permission::firstOrCreate(['slug' => $slug], ['name' => str($slug)->replace('.', ' ')->title(), 'group' => $group]));
        $map = ['owner' => $permissions->pluck('id'), 'manager' => $permissions->reject(fn ($p) => $p->slug === 'settings.manage')->pluck('id'), 'photographer' => $permissions->whereIn('slug', ['dashboard.view', 'bookings.view', 'galleries.view', 'galleries.manage'])->pluck('id'), 'editor' => $permissions->whereIn('slug', ['dashboard.view', 'galleries.view', 'galleries.manage'])->pluck('id'), 'receptionist' => $permissions->whereIn('slug', ['dashboard.view', 'customers.view', 'customers.manage', 'bookings.view', 'bookings.manage'])->pluck('id'), 'accountant' => $permissions->whereIn('slug', ['dashboard.view', 'finance.view', 'finance.manage', 'reports.view'])->pluck('id'), 'customer' => collect()];
        foreach ($map as $slug => $ids) {
            $role = Role::firstOrCreate(['slug' => $slug], ['name' => str($slug)->title(), 'is_system' => true]);
            $role->permissions()->sync($ids);
        }
        SubscriptionPlan::firstOrCreate(['slug' => 'starter'], ['name' => 'Starter', 'price' => 25000, 'storage_limit_mb' => 10240, 'gallery_limit' => 20, 'is_active' => true]);
        SubscriptionPlan::firstOrCreate(['slug' => 'professional'], ['name' => 'Professional', 'price' => 75000, 'storage_limit_mb' => 102400, 'gallery_limit' => 200, 'is_active' => true]);

        // Production needs system roles and plans, but must never receive demo accounts.
        if ($this->command?->getLaravel()->environment('production')) {
            return;
        }

        $admin = User::firstOrCreate(['email' => 'admin@example.com'], ['name' => 'PhotoHub Admin', 'password' => 'PhotoHub2026!', 'is_super_admin' => true]);
        $business = Business::firstOrCreate(['slug' => 'lenscraft-studio'], ['name' => 'LensCraft Studio', 'email' => 'owner@example.com', 'phone' => '+255 712 345 678', 'city' => 'Dar es Salaam', 'country' => 'Tanzania', 'category' => 'Wedding & Events', 'currency' => 'TZS', 'timezone' => 'Africa/Dar_es_Salaam', 'trial_ends_at' => now()->addDays(14)]);
        foreach ([['LensCraft Owner', 'owner@example.com', 'owner', 'Business Owner'], ['Amina Photographer', 'photographer@example.com', 'photographer', 'Lead Photographer'], ['Neema Manager', 'manager@example.com', 'manager', 'Studio Manager'], ['Baraka Accountant', 'accountant@example.com', 'accountant', 'Accountant'], ['Rehema Editor', 'editor@example.com', 'editor', 'Photo Editor'], ['Juma Receptionist', 'receptionist@example.com', 'receptionist', 'Receptionist']] as $index => [$name,$email,$role,$title]) {
            $user = User::firstOrCreate(['email' => $email], ['name' => $name, 'password' => 'PhotoHub2026!']);
            $membership = BusinessUser::firstOrCreate(['business_id' => $business->id, 'user_id' => $user->id], ['employee_number' => 'EMP-'.str_pad((string) $index + 1, 6, '0', STR_PAD_LEFT), 'job_title' => $title, 'joined_at' => today()]);
            $membership->roles()->syncWithoutDetaching(Role::where('slug', $role)->pluck('id'));
        }
        $customerUser = User::firstOrCreate(['email' => 'customer@example.com'], ['name' => 'Kelvin Customer', 'password' => 'PhotoHub2026!']);
        $customer = Customer::firstOrCreate(['business_id' => $business->id, 'email' => 'customer@example.com'], ['user_id' => $customerUser->id, 'customer_number' => 'CUS-000001', 'first_name' => 'Kelvin', 'last_name' => 'Mushi', 'phone' => '+255 700 123 456', 'city' => 'Dar es Salaam', 'country' => 'Tanzania']);
        $customer->update(['user_id' => $customerUser->id]);
        Customer::firstOrCreate(['business_id' => $business->id, 'customer_number' => 'CUS-000100'], ['first_name' => 'Asha', 'last_name' => 'Mrema', 'phone' => '+255 754 222 333', 'email' => 'asha@example.test', 'city' => 'Arusha', 'country' => 'Tanzania']);
        $package = Package::firstOrCreate(['business_id' => $business->id, 'name' => 'Signature Wedding'], ['category' => 'Wedding', 'description' => 'Full-day wedding coverage with two photographers.', 'price' => 2500000, 'deposit_amount' => 500000, 'duration_minutes' => 600, 'photographers_count' => 2, 'photos_count' => 500, 'edited_photos_count' => 100, 'album_included' => true, 'delivery_days' => 21, 'is_active' => true]);
        Package::firstOrCreate(['business_id' => $business->id, 'name' => 'Graduation Portraits'], ['category' => 'Graduation', 'description' => 'Outdoor graduation portrait session.', 'price' => 350000, 'deposit_amount' => 100000, 'duration_minutes' => 90, 'photographers_count' => 1, 'photos_count' => 60, 'edited_photos_count' => 15, 'delivery_days' => 7, 'is_active' => true]);
        $booking = Booking::firstOrCreate(['business_id' => $business->id, 'booking_number' => 'BK-'.now()->format('Y').'-000001'], ['customer_id' => $customer->id, 'package_id' => $package->id, 'event_type' => 'Wedding', 'event_date' => today()->addDays(12), 'start_time' => '09:00', 'end_time' => '18:00', 'location' => 'Dar es Salaam', 'total_cost' => 2500000, 'deposit' => 500000, 'balance' => 2000000, 'payment_status' => 'partially_paid', 'status' => 'confirmed', 'expected_delivery_date' => today()->addDays(33)]);
        Shoot::firstOrCreate(['business_id' => $business->id, 'shoot_number' => 'SHT-'.now()->format('Y').'-000001'], ['customer_id' => $customer->id, 'booking_id' => $booking->id, 'event' => 'Kelvin Wedding', 'location' => $booking->location, 'shoot_date' => $booking->event_date, 'start_time' => $booking->start_time, 'end_time' => $booking->end_time, 'status' => 'planned', 'expected_delivery_date' => $booking->expected_delivery_date]);
        $gallery = Gallery::firstOrCreate(['business_id' => $business->id, 'gallery_number' => 'GAL-'.now()->format('Y').'-000001'], ['name' => 'Kelvin & Anna Wedding Proofs', 'code' => 'DEMO-WEDDING', 'customer_id' => $customer->id, 'booking_id' => $booking->id, 'event' => 'Wedding', 'event_date' => $booking->event_date, 'type' => 'selection', 'privacy' => 'private', 'selection_limit' => 20, 'status' => 'selection_link_ready']);
        if (! $gallery->accessTokens()->where('purpose', 'selection')->whereNull('revoked_at')->exists()) {
            app(GalleryAccessService::class)->generate($gallery, 'selection');
        }
        $invoice = Invoice::firstOrCreate(['business_id' => $business->id, 'invoice_number' => 'INV-'.now()->format('Y').'-000001'], ['customer_id' => $customer->id, 'booking_id' => $booking->id, 'subtotal' => 2500000, 'total' => 2500000, 'paid' => 500000, 'balance' => 2000000, 'due_date' => today()->addDays(10), 'status' => 'partially_paid']);
        $invoice->items()->firstOrCreate(['description' => 'Signature Wedding package'], ['quantity' => 1, 'unit_price' => 2500000, 'total' => 2500000]);
        $owner = User::where('email', 'owner@example.com')->first();
        $payment = Payment::firstOrCreate(['business_id' => $business->id, 'transaction_reference' => 'DEMO-DEPOSIT'], ['customer_id' => $customer->id, 'booking_id' => $booking->id, 'invoice_id' => $invoice->id, 'amount' => 500000, 'method' => 'mpesa', 'payment_date' => now()->subDays(2), 'received_by' => $owner->id, 'status' => 'completed']);
        Receipt::firstOrCreate(['business_id' => $business->id, 'payment_id' => $payment->id], ['receipt_number' => 'RCPT-'.now()->format('Y').'-000001', 'customer_id' => $customer->id, 'invoice_id' => $invoice->id, 'amount' => $payment->amount]);
        Expense::firstOrCreate(['business_id' => $business->id, 'description' => 'Wedding venue scouting transport'], ['date' => today()->subDay(), 'category' => 'transport', 'amount' => 75000, 'booking_id' => $booking->id, 'recorded_by' => $owner->id]);
        ActivityLog::firstOrCreate(['business_id' => $business->id, 'action' => 'business.created'], ['user_id' => $admin->id, 'subject_type' => Business::class, 'subject_id' => $business->id]);
    }
}
