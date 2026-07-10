<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ResetPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Checkpoint 44 — guest-only. token/email are passed through as page
 * props exactly as received (from the emailed link's URL) — never
 * looked up or validated here; ResetPasswordRequest::reset() is the only
 * place that actually resolves and checks them.
 */
class NewPasswordController extends Controller
{
    public function create(Request $request, string $token): Response
    {
        return Inertia::render('Auth/ResetPassword', [
            'token' => $token,
            'email' => $request->query('email', ''),
        ]);
    }

    /**
     * A single generic failure message either way — never distinguishes
     * "no such user", "wrong tenant", "expired token", or "invalid
     * token", the same non-enumerating posture
     * PasswordResetLinkController::store() already applies to the send
     * step.
     */
    public function store(ResetPasswordRequest $request): RedirectResponse
    {
        if (! $request->reset()) {
            throw ValidationException::withMessages([
                'email' => 'This password reset link is invalid or has expired.',
            ]);
        }

        return redirect()->route('login')->with('status', 'Your password has been reset. Please sign in.');
    }
}
