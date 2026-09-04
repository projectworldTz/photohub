<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use Illuminate\View\View;

class ActivityController extends Controller
{
    public function __invoke(): View
    {
        return view('activity.index', ['logs' => ActivityLog::with('user')->where('business_id', app('currentBusiness')->id)->latest()->paginate(40)]);
    }
}
