<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthenticatedSessionController extends Controller
{
    public function store(LoginRequest $request): JsonResponse
    {
        $user = $request->authenticate();

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

    public function destroy(Request $request): JsonResponse
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

        return response()->json(['message' => 'Logged out.']);
    }
}
