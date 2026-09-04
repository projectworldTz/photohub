<?php

namespace App\Services;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\Lead;
use Illuminate\Support\Facades\DB;

class LeadService
{
    public function __construct(private NumberSeriesService $numbers) {}

    public function convert(Lead $lead, int $userId, ?string $firstName = null, ?string $lastName = null): Customer
    {
        return DB::transaction(function () use ($lead, $userId, $firstName, $lastName) {
            abort_if($lead->converted_customer_id, 422, 'This lead has already been converted.');
            $parts = preg_split('/\s+/', trim($lead->name), 2);
            $customer = Customer::create(['business_id' => $lead->business_id, 'customer_number' => $this->numbers->next(Customer::class, $lead->business_id, 'customer_number', 'CUS'), 'first_name' => $firstName ?: $parts[0], 'last_name' => $lastName ?: ($parts[1] ?? '-'), 'phone' => $lead->phone, 'email' => $lead->email, 'status' => 'active', 'notes' => 'Converted from lead #'.$lead->id]);
            $lead->update(['status' => 'won', 'converted_customer_id' => $customer->id, 'converted_at' => now()]);
            ActivityLog::create(['business_id' => $lead->business_id, 'user_id' => $userId, 'action' => 'lead.converted', 'subject_type' => Lead::class, 'subject_id' => $lead->id]);

            return $customer;
        });
    }
}
