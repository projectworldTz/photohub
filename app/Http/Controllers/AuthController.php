<?php

namespace App\Http\Controllers;

use App\Http\Requests\RegisterBusinessRequest;
use App\Models\ActivityLog;
use App\Models\Business;
use App\Models\BusinessUser;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class AuthController extends Controller
{
    public function loginForm(): View
    {
        return view('auth.login');
    }

    public function registerForm(): View
    {
        return view('auth.register');
    }

    public function login(Request $request): RedirectResponse
    {
        $credentials = $request->validate(['email' => ['required', 'email'], 'password' => ['required', 'string']]);
        if (! Auth::attempt($credentials, $request->boolean('remember'))) {
            return back()->withErrors(['email' => 'The provided credentials do not match our records.'])->onlyInput('email');
        }
        $request->session()->regenerate();
        abort_unless($request->user()->is_active, 403, 'Your account is inactive.');
        if ($request->user()->is_super_admin) {
            return redirect()->intended(route('admin.index'));
        }
        $businessId = $request->user()->memberships()->where('status', 'active')->value('business_id');
        if ($businessId) {
            $request->session()->put('business_id', $businessId);
        }
        if (! $businessId && Customer::where('user_id', $request->user()->id)->exists()) {
            return redirect()->route('portal.index');
        }

        return redirect()->intended(route('dashboard'));
    }

    public function register(RegisterBusinessRequest $request): RedirectResponse
    {
        [$user, $business] = DB::transaction(function () use ($request) {
            $data = $request->validated();
            $user = User::create(['name' => $data['owner_name'], 'email' => $data['email'], 'phone' => $data['phone'], 'password' => $data['password']]);
            $business = Business::create([
                'name' => $data['business_name'], 'slug' => $this->uniqueSlug($data['business_name']), 'email' => $data['email'],
                'phone' => $data['phone'], 'address' => $data['address'] ?? null, 'city' => $data['city'], 'country' => $data['country'],
                'description' => $data['description'] ?? null, 'category' => $data['category'], 'currency' => strtoupper($data['currency']),
                'timezone' => $data['timezone'], 'trial_ends_at' => now()->addDays(14),
            ]);
            $membership = BusinessUser::create(['business_id' => $business->id, 'user_id' => $user->id, 'employee_number' => 'EMP-000001', 'job_title' => 'Business Owner', 'joined_at' => today()]);
            $membership->roles()->attach(Role::where('slug', 'owner')->firstOrFail());
            ActivityLog::create(['business_id' => $business->id, 'user_id' => $user->id, 'action' => 'business.created', 'subject_type' => Business::class, 'subject_id' => $business->id, 'ip_address' => $request->ip()]);

            return [$user, $business];
        });
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('business_id', $business->id);

        return redirect()->route('dashboard')->with('success', 'Welcome to PhotoHub. Your studio is ready.');
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'studio';
        $slug = $base;
        $i = 2;
        while (Business::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }
}
