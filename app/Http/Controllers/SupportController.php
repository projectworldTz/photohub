<?php

namespace App\Http\Controllers;

use App\Models\SupportRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class SupportController extends Controller
{
    public function store(Request $r): RedirectResponse
    {
        $d = $r->validate(['subject' => 'required|string|max:200', 'message' => 'required|string|max:5000']);
        SupportRequest::create($d + ['business_id' => app('currentBusiness')->id, 'user_id' => auth()->id()]);

        return back()->with('success', 'Support request submitted.');
    }

    public function index(): View
    {
        abort_unless(auth()->user()->is_super_admin, 403);

        return view('admin.support', ['requests' => SupportRequest::latest()->paginate(30)]);
    }

    public function update(Request $r, SupportRequest $support): RedirectResponse
    {
        abort_unless(auth()->user()->is_super_admin, 403);
        $support->update($r->validate(['status' => 'required|in:open,in_progress,resolved,closed']));

        return back();
    }
}
