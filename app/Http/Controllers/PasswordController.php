<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

class PasswordController extends Controller
{
    public function request(): View
    {
        return view('auth.forgot-password');
    }

    public function email(Request $r): RedirectResponse
    {
        $r->validate(['email' => 'required|email']);
        $status = Password::sendResetLink($r->only('email'));

        return $status === Password::RESET_LINK_SENT ? back()->with('success', __($status)) : back()->withErrors(['email' => __($status)]);
    }

    public function reset(Request $r, string $token): View
    {
        return view('auth.reset-password', ['token' => $token, 'email' => $r->email]);
    }

    public function update(Request $r): RedirectResponse
    {
        $d = $r->validate(['token' => 'required', 'email' => 'required|email', 'password' => 'required|confirmed|min:8']);
        $status = Password::reset($d, function (User $u, string $password) {
            $u->forceFill(['password' => $password, 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($u));
        });

        return $status === Password::PASSWORD_RESET ? redirect()->route('login')->with('success', __($status)) : back()->withErrors(['email' => __($status)]);
    }

    public function change(Request $request): RedirectResponse
    {
        $data = $request->validate(['current_password' => 'required|current_password', 'password' => 'required|string|min:8|confirmed']);
        $request->user()->update(['password' => $data['password']]);
        $request->session()->regenerate();

        return back()->with('success', 'Password changed successfully.');
    }
}
