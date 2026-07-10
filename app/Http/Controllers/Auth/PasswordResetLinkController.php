<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Checkpoint 44 — guest-only (see routes/auth.php), same posture as
 * AuthenticatedSessionController::create() for /login.
 */
class PasswordResetLinkController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('Auth/ForgotPassword');
    }

    /**
     * Always the same generic response, regardless of whether the email
     * exists, belongs to another tenant, or a real link was actually
     * sent — see ForgotPasswordRequest::sendResetLinkIfEligible() for
     * where that decision (and its audit log) actually happens.
     */
    public function store(ForgotPasswordRequest $request): RedirectResponse
    {
        $request->sendResetLinkIfEligible();

        return back()->with('status', "If an account exists for that email, we've sent a password reset link.");
    }
}
