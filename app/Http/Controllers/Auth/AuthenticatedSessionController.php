<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;

class AuthenticatedSessionController extends Controller
{
    /**
     * Guest-only — an already-authenticated user hitting /login is
     * redirected to the dashboard, never shown the form again.
     */
    public function create(Request $request): Response|RedirectResponse
    {
        if ($request->user()) {
            return redirect()->route('dashboard');
        }

        return Inertia::render('Auth/Login');
    }

    /**
     * One endpoint, content-negotiated (Checkpoint 16) — not two
     * separate login systems. A caller that actually wants JSON
     * (postJson() in every existing test, or a genuine API client)
     * keeps getting exactly the same response as before. A real
     * browser/Inertia form post (no explicit JSON Accept header) gets a
     * redirect instead — the shape Inertia's client expects after a
     * successful form submission.
     */
    public function store(LoginRequest $request): JsonResponse|RedirectResponse
    {
        $user = $request->authenticate();

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Logged in.',
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'is_platform_admin' => $user->is_platform_admin,
                ],
            ]);
        }

        return redirect()->intended(route('dashboard'));
    }

    public function destroy(Request $request): JsonResponse|RedirectResponse
    {
        $user = $request->user();

        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        if ($user) {
            AuditLogger::logFor(
                actor: $user,
                action: 'logout',
                module: 'auth',
                targetUserId: $user->id,
                description: 'User logged out.',
                ipAddress: $request->ip(),
                userAgent: $request->userAgent(),
            );
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => 'Logged out.']);
        }

        return redirect()->route('login');
    }
}
