<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateUserRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Models\DeletedAccount;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    public function me(): JsonResponse
    {
        $user = auth('api')->user()->load('orders');

        return response()->json([
            'id'             => $user->id,
            'name'           => $user->name,
            'email'          => $user->email,
            'phone'          => $user->phone,
            'address'        => $user->address,
            'erp_contact_id' => $user->erp_contact_id,
            'orders_count'   => $user->orders->count(),
            'approval_status' => $user->approval_status,
        ]);
    }

    public function update(UpdateUserRequest $request, int $id): JsonResponse
    {
        $authUser = auth('api')->user();

        // Users can only update their own profile
        if ($authUser->id !== $id) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $authUser->update($request->validated());

        return response()->json([
            'message' => 'Profile updated successfully',
            'user'    => [
                'id'      => $authUser->id,
                'name'    => $authUser->name,
                'email'   => $authUser->email,
                'phone'   => $authUser->phone,
                'address' => $authUser->address,
                'approval_status' => $authUser->approval_status,
            ],
        ]);
    }

    /**
     * Deletes the signed-in account (App Store requirement for apps with sign-up).
     *
     * Addresses and wishlist go with it; orders stay, detached from the user,
     * because they are business records. The email is remembered as a hash so
     * the ERP's contact sync does not recreate the account.
     */
    public function destroyMe(Request $request): JsonResponse
    {
        $request->validate(['password' => ['required', 'string']]);

        $user = auth('api')->user();

        // 422, not 401/403: the app reads 401 as an expired session and 403 as
        // pending approval.
        if (!Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'The password is incorrect.',
                'errors'  => ['password' => ['The password is incorrect.']],
            ], 422);
        }

        DB::transaction(function () use ($user) {
            DeletedAccount::remember($user->email);
            $user->delete();
        });

        auth('api')->logout();

        return response()->json(['message' => 'Your account has been deleted.']);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password'      => ['required', 'string'],
            'password'              => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
            'password_confirmation' => ['required', 'string'],
        ]);

        $user = auth('api')->user();

        if (!Hash::check($request->current_password, $user->password)) {
            return response()->json([
                'message' => 'The current password is incorrect.',
                'errors'  => ['current_password' => ['The current password is incorrect.']],
            ], 422);
        }

        $user->forceFill(['password' => Hash::make($request->password)])->save();

        // Invalidate the current token and issue a fresh one so the app stays logged in
        auth('api')->logout();
        $token = auth('api')->login($user);

        return response()->json([
            'message'      => 'Password changed successfully.',
            'access_token' => $token,
            'token_type'   => 'bearer',
            'expires_in'   => config('jwt.ttl') * 60,
        ]);
    }
}