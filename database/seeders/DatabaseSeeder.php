<?php

namespace Database\Seeders;

use App\Models\ActivityLog;
use App\Models\Booking;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Contract;
use App\Models\ContractTemplate;
use App\Models\Customer;
use App\Models\Equipment;
use App\Models\EquipmentAssignment;
use App\Models\EquipmentMaintenance;
use App\Models\Expense;
use App\Models\Gallery;
use App\Models\Invoice;
use App\Models\Lead;
use App\Models\Message;
use App\Models\Order;
use App\Models\Package;
use App\Models\Payment;
use App\Models\Permission;
use App\Models\Photo;
use App\Models\PortfolioCategory;
use App\Models\PrintOrder;
use App\Models\Quotation;
use App\Models\Receipt;
use App\Models\Review;
use App\Models\Role;
use App\Models\Shoot;
use App\Models\Subscription;
use App\Models\SubscriptionPlan;
use App\Models\SupportRequest;
use App\Models\Task;
use App\Models\User;
use App\Services\GalleryAccessService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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
        Lead::firstOrCreate(['business_id' => $business->id, 'email' => 'zawadi@example.test'], ['name' => 'Zawadi Mushi', 'phone' => '+255 755 100 200', 'event_type' => 'Corporate portraits', 'event_date' => today()->addMonth(), 'estimated_budget' => 900000, 'message' => 'Team portraits for our company website.', 'source' => 'Website', 'assigned_user_id' => $owner->id, 'status' => 'contacted']);
        $quotation = Quotation::firstOrCreate(['business_id' => $business->id, 'quotation_number' => 'QT-'.now()->format('Y').'-000001'], ['customer_id' => $customer->id, 'booking_id' => $booking->id, 'date' => today(), 'expiry_date' => today()->addDays(14), 'subtotal' => 2500000, 'discount' => 0, 'tax' => 0, 'total' => 2500000, 'notes' => 'Sample wedding quotation', 'terms' => 'A deposit confirms the booking.', 'status' => 'sent']);
        $quotation->items()->firstOrCreate(['description' => 'Signature Wedding package'], ['quantity' => 1, 'unit_price' => 2500000, 'total' => 2500000]);
        $shoot = Shoot::where('business_id', $business->id)->where('booking_id', $booking->id)->firstOrFail();
        $photographer = BusinessUser::where('business_id', $business->id)->whereHas('user', fn ($query) => $query->where('email', 'photographer@example.com'))->first();
        $equipment = Equipment::firstOrCreate(['business_id' => $business->id, 'equipment_code' => 'CAM-000001'], ['name' => 'Primary Mirrorless Camera', 'type' => 'Camera', 'brand' => 'Sony', 'model' => 'A7 IV', 'serial_number' => 'DEMO-A7IV-001', 'purchase_date' => today()->subYear(), 'cost' => 6500000, 'condition' => 'excellent', 'status' => 'assigned']);
        EquipmentAssignment::firstOrCreate(['business_id' => $business->id, 'equipment_id' => $equipment->id, 'shoot_id' => $shoot->id], ['business_user_id' => $photographer?->id, 'assigned_at' => now(), 'notes' => 'Assigned for the upcoming wedding.']);
        EquipmentMaintenance::firstOrCreate(['business_id' => $business->id, 'equipment_id' => $equipment->id, 'problem' => 'Routine sensor cleaning'], ['maintenance_date' => today()->subMonth(), 'repair_company' => 'Camera Care Tanzania', 'cost' => 50000, 'next_maintenance_date' => today()->addMonths(5)]);
        Task::firstOrCreate(['business_id' => $business->id, 'title' => 'Confirm wedding shot list'], ['assigned_user_id' => $photographer?->user_id, 'booking_id' => $booking->id, 'due_at' => now()->addDays(5), 'priority' => 'high', 'status' => 'todo']);
        Message::firstOrCreate(['business_id' => $business->id, 'customer_id' => $customer->id, 'body' => 'We have received your booking and will share the final timeline shortly.'], ['sender_id' => $owner->id, 'booking_id' => $booking->id]);
        Review::firstOrCreate(['business_id' => $business->id, 'customer_id' => $customer->id, 'booking_id' => $booking->id], ['rating' => 5, 'comment' => 'Professional service and a wonderful client experience.', 'is_public' => true]);
        $template = ContractTemplate::firstOrCreate(['business_id' => $business->id, 'name' => 'Standard Photography Agreement'], ['body' => 'Photography services, delivery schedule, usage rights and payment terms.', 'is_active' => true]);
        Contract::firstOrCreate(['business_id' => $business->id, 'booking_id' => $booking->id, 'customer_id' => $customer->id], ['content' => $template->body, 'status' => 'pending']);
        $photoPath = "businesses/{$business->id}/galleries/{$gallery->id}/originals/demo-photo.jpg";
        if (! Storage::disk('local')->exists($photoPath)) {
            $image = imagecreatetruecolor(1200, 800);
            imagefill($image, 0, 0, imagecolorallocate($image, 50, 35, 80));
            imagestring($image, 5, 475, 385, 'PhotoHub Demo Photo', imagecolorallocate($image, 255, 255, 255));
            ob_start();
            imagejpeg($image, null, 90);
            Storage::disk('local')->put($photoPath, ob_get_clean());
            imagedestroy($image);
        }
        $photo = Photo::firstOrCreate(['business_id' => $business->id, 'gallery_id' => $gallery->id, 'filename' => 'demo-photo.jpg'], ['uuid' => (string) Str::uuid(), 'original_path' => $photoPath, 'preview_path' => $photoPath, 'thumbnail_path' => $photoPath, 'file_size' => Storage::disk('local')->size($photoPath), 'width' => 1200, 'height' => 800, 'mime_type' => 'image/jpeg', 'status' => 'ready', 'is_proof' => true, 'watermarked' => false]);
        $order = Order::firstOrCreate(['business_id' => $business->id, 'order_number' => 'ORD-'.now()->format('Y').'-000001'], ['customer_id' => $customer->id, 'gallery_id' => $gallery->id, 'subtotal' => 15000, 'discount' => 0, 'total' => 15000, 'payment_status' => 'unpaid', 'status' => 'pending']);
        $order->items()->firstOrCreate(['photo_id' => $photo->id], ['price' => 15000]);
        PrintOrder::firstOrCreate(['business_id' => $business->id, 'customer_id' => $customer->id, 'product' => 'Framed print'], ['order_id' => $order->id, 'size' => '12x18', 'quantity' => 1, 'total' => 65000, 'status' => 'pending']);
        $portfolio = PortfolioCategory::firstOrCreate(['business_id' => $business->id, 'slug' => 'weddings'], ['name' => 'Weddings']);
        $portfolio->photos()->syncWithoutDetaching([$photo->id => ['position' => 1]]);
        $starter = SubscriptionPlan::where('slug', 'starter')->firstOrFail();
        Subscription::firstOrCreate(['business_id' => $business->id, 'subscription_plan_id' => $starter->id], ['starts_at' => now(), 'ends_at' => now()->addMonth(), 'status' => 'active', 'complimentary' => true]);
        SupportRequest::firstOrCreate(['business_id' => $business->id, 'user_id' => $owner->id, 'subject' => 'Welcome to PhotoHub'], ['message' => 'This sample request demonstrates the platform support workflow.', 'status' => 'open']);
        ActivityLog::firstOrCreate(['business_id' => $business->id, 'action' => 'business.created'], ['user_id' => $admin->id, 'subject_type' => Business::class, 'subject_id' => $business->id]);
    }
}
