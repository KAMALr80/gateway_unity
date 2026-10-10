<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\DeletedAccount;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;

class AuthController extends Controller
{
    public function register(RegisterRequest $request): JsonResponse
    {
        User::create([
            'name'            => $request->name,
            'email'           => $request->email,
            'password'        => $request->password,
            'approval_status' => 'pending',
        ]);

        DeletedAccount::forget($request->email);

        return response()->json([
            'message' => 'Registration successful. Your account is pending approval before you can log in.',
        ], 201);
    }

    public function login(LoginRequest $request): JsonResponse
    {
        $token = auth('api')->attempt($request->only('email', 'password'));

        if (!$token) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $user = auth('api')->user();

        if ($user->approval_status !== 'approved') {
            auth('api')->logout();
            return response()->json(['message' => 'Your account is pending approval.'], 403);
        }

        return $this->tokenResponse($token);
    }

    public function validate(): JsonResponse
    {
        return response()->json([
            'valid' => true,
            'user'  => $this->formatUser(auth('api')->user()),
        ]);
    }

    public function refresh(): JsonResponse
    {
        try {
            $token = auth('api')->refresh();

            // refresh() blacklists the request's token, so auth('api')->user() would read that old token and
            // return null (formatUser(null) was a 500). Resolve the user from the newly issued token instead.
            $user = auth('api')->setToken($token)->user();
        } catch (\Exception $e) {
            return response()->json(['message' => 'Token cannot be refreshed'], 401);
        }

        if (! $user) {
            return response()->json(['message' => 'Token cannot be refreshed'], 401);
        }

        return $this->tokenResponse($token, $user);
    }

    public function logout(): JsonResponse
    {
        auth('api')->logout();
        return response()->json(['message' => 'Logged out successfully']);
    }

    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate(['email' => ['required', 'email']]);

        // Always return 200 to prevent user enumeration
        Password::broker()->sendResetLink($request->only('email'));

        return response()->json(['message' => 'If that email address is registered, a password reset link has been sent.']);
    }

    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token'                 => ['required', 'string'],
            'email'                 => ['required', 'email'],
            'password'              => ['required', 'string', 'min:8', 'confirmed'],
            'password_confirmation' => ['required', 'string'],
        ]);

        $status = Password::broker()->reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password) {
                $user->forceFill(['password' => Hash::make($password)])->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json([
                'message' => 'This password reset link is invalid or has expired.',
            ], 422);
        }

        return response()->json(['message' => 'Password has been reset successfully.']);
    }

    private function tokenResponse(string $token, ?User $user = null): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type'   => 'bearer',
            'expires_in'   => config('jwt.ttl') * 60,
            'user'         => $this->formatUser($user ?? auth('api')->user()),
        ]);
    }

    private function formatUser(User $user): array
    {
        return [
            'id'             => $user->id,
            'name'           => $user->name,
            'email'          => $user->email,
            'phone'          => $user->phone,
            'address'        => $user->address,
            'erp_contact_id' => $user->erp_contact_id,
            'approval_status' => $user->approval_status,
        ];
    }
}
