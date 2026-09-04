<?php

namespace App\Http\Controllers;

use App\Models\Shoot;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShootController extends Controller
{
    public function index(): View
    {
        return view('shoots.index', ['shoots' => Shoot::with('customer')->forBusiness(app('currentBusiness')->id)->orderBy('shoot_date')->paginate(20)]);
    }

    public function show(Shoot $shoot): View
    {
        $this->guard($shoot);

        return view('shoots.show', ['shoot' => $shoot->load(['customer', 'booking', 'staff.user'])]);
    }

    public function update(Request $r, Shoot $shoot): RedirectResponse
    {
        $this->guard($shoot);
        $shoot->update($r->validate(['status' => 'required|in:planned,on_the_way,shooting,completed,photos_uploaded,editing,ready,delivered', 'expected_delivery_date' => 'nullable|date|after_or_equal:shoot_date', 'notes' => 'nullable|string|max:3000']));

        return back()->with('success', 'Shoot updated.');
    }

    private function guard(Shoot $s): void
    {
        abort_unless($s->business_id === app('currentBusiness')->id, 404);
    }
}
