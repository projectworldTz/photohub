<?php

namespace App\Http\Controllers;

use App\Models\BusinessUser;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Illuminate\View\View;

class StaffController extends Controller
{
    public function index(): View
    {
        return view('staff.index', ['members' => BusinessUser::with(['user', 'roles'])->where('business_id', app('currentBusiness')->id)->paginate(20)]);
    }

    public function create(): View
    {
        return view('staff.form', ['member' => new BusinessUser, 'roles' => Role::whereNotIn('slug', ['owner', 'customer'])->get()]);
    }

    public function store(Request $r): RedirectResponse
    {
        $this->authorizeManage();
        $data = $r->validate(['name' => 'required|string|max:120', 'email' => 'required|email|max:150', 'phone' => 'nullable|string|max:30', 'job_title' => 'nullable|string|max:100', 'roles' => 'required|array|min:1', 'roles.*' => 'exists:roles,id', 'password' => ['required', 'confirmed', Password::min(8)->mixedCase()->numbers()]]);
        DB::transaction(function () use ($data) {
            $user = User::firstOrCreate(['email' => $data['email']], ['name' => $data['name'], 'phone' => $data['phone'] ?? null, 'password' => $data['password']]);
            abort_if(BusinessUser::where('business_id', app('currentBusiness')->id)->where('user_id', $user->id)->exists(), 422, 'This user already belongs to the business.');
            $next = (BusinessUser::where('business_id', app('currentBusiness')->id)->max('id') ?? 0) + 1;
            $m = BusinessUser::create(['business_id' => app('currentBusiness')->id, 'user_id' => $user->id, 'employee_number' => 'EMP-'.str_pad((string) $next, 6, '0', STR_PAD_LEFT), 'job_title' => $data['job_title'] ?? null, 'joined_at' => today()]);
            $m->roles()->sync($data['roles']);
        });

        return redirect()->route('staff.index')->with('success', 'Staff member added.');
    }

    public function edit(BusinessUser $staff): View
    {
        $this->guard($staff);

        return view('staff.form', ['member' => $staff->load('user', 'roles'), 'roles' => Role::whereNotIn('slug', ['owner', 'customer'])->get()]);
    }

    public function update(Request $r, BusinessUser $staff): RedirectResponse
    {
        $this->authorizeManage();
        $this->guard($staff);
        $data = $r->validate(['name' => 'required|string|max:120', 'phone' => 'nullable|string|max:30', 'job_title' => 'nullable|string|max:100', 'status' => 'required|in:active,inactive', 'roles' => 'required|array|min:1', 'roles.*' => 'exists:roles,id']);
        DB::transaction(function () use ($staff, $data) {
            $staff->user->update(['name' => $data['name'], 'phone' => $data['phone'] ?? null]);
            $staff->update(['job_title' => $data['job_title'] ?? null, 'status' => $data['status']]);
            $staff->roles()->sync($data['roles']);
        });

        return redirect()->route('staff.index')->with('success', 'Staff member updated.');
    }

    private function authorizeManage(): void
    {
        abort_unless(auth()->user()->hasPermission('staff.manage'), 403);
    }

    private function guard(BusinessUser $m): void
    {
        abort_unless($m->business_id === app('currentBusiness')->id,404);
    }
}
