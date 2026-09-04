<?php

namespace App\Enums;

enum BookingStatus: string
{
    case Inquiry = 'inquiry';
    case Pending = 'pending';
    case Confirmed = 'confirmed';
    case DepositPaid = 'deposit_paid';
    case Scheduled = 'scheduled';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
    case Rescheduled = 'rescheduled';
}
