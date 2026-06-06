<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Password;

class ForgotPasswordController extends Controller
{
    public function showLinkRequestForm(): RedirectResponse
    {
        return redirect()->route('login')
            ->with('error', 'Password reset is handled by an administrator. Please contact IT support.');
    }

    public function sendResetLinkEmail(Request $request): RedirectResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        Password::sendResetLink($request->only('email'));

        return redirect()->route('login')
            ->with('success', 'If the account exists, password reset instructions have been sent.');
    }

    public function showResetForm(): RedirectResponse
    {
        return redirect()->route('login')
            ->with('error', 'Password reset is handled by an administrator. Please contact IT support.');
    }

    public function reset(): RedirectResponse
    {
        return redirect()->route('login')
            ->with('error', 'Password reset is handled by an administrator. Please contact IT support.');
    }
}
