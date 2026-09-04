<?php

namespace App\Services;

use App\Models\Business;
use App\Notifications\BusinessAlert;

class NotificationService
{
    public function business(Business $business, string $title, string $message, ?string $url = null): void
    {
        $business->users()->wherePivot('status', 'active')->each(fn ($user) => $user->notify(new BusinessAlert($title, $message, $url)));
    }
}
