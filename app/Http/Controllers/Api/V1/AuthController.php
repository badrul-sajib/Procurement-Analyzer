<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;
use Laravel\Sanctum\PersonalAccessToken;

class AuthController extends Controller
{
    /** SPA login: establishes a session cookie (request must come from a stateful domain). */
    public function login(LoginRequest $request): UserResource
    {
        $user = $this->verifiedUser($request);

        Auth::guard('web')->login($user);
        $request->session()->regenerate();
        $user->forceFill(['last_login_at' => now()])->save();
        AuditLogger::log('auth.login', 'auth', $user->id, 'Signed in');

        return new UserResource($user->load('roles'));
    }

    /** Mobile / API-client login: returns a personal access token. */
    public function issueToken(LoginRequest $request): JsonResponse
    {
        $request->validate(['device_name' => ['required']]);
        $user = $this->verifiedUser($request);
        $minutes = config('sanctum.expiration');
        $token = $user->createToken((string) $request->input('device_name'), ['*'], $minutes ? now()->addMinutes((int) $minutes) : null);
        $user->forceFill(['last_login_at' => now()])->save();
        AuditLogger::log('auth.token_issued', 'auth', $user->id, 'Token issued for '.$request->string('device_name'));

        return response()->json(['data' => ['token' => $token->plainTextToken, 'expires_at' => $token->accessToken->expires_at?->toIso8601String()]], 201);
    }

    public function me(Request $request): UserResource
    {
        return new UserResource($request->user()->load('roles'));
    }

    public function logout(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        } else {
            Auth::guard('web')->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        return response()->json(null, 204);
    }

    public function revokeToken(Request $request): JsonResponse
    {
        $token = $request->user()->currentAccessToken();
        abort_unless($token instanceof PersonalAccessToken, 422, 'Not a token-authenticated request.');
        $token->delete();

        return response()->json(null, 204);
    }

    private function verifiedUser(LoginRequest $request): User
    {
        $user = User::where('email', $request->input('email'))->first();

        // Same message for unknown email, wrong password and inactive account: no user enumeration.
        if (! $user || ! Hash::check((string) $request->input('password'), $user->password) || ! $user->is_active) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }

        return $user;
    }
}
