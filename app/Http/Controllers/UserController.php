<?php

namespace App\Http\Controllers;

use App\Http\Requests\UpdateUserRequest;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

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
     * Account deletion, for now a placeholder: it always answers success and
     * ends the session, so the app signs the user out, but nothing is deleted.
     * How a deletion should reach the ERP (the customer, their orders and
     * invoices) isn't decided yet, so each request is logged to be acted on
     * by hand. The real deletion (DeletedAccount keeps the ERP contact sync
     * from recreating an account) is in commit 9c5be45.
     */
    public function destroyMe(Request $request): JsonResponse
    {
        $user = auth('api')->user();

        Log::notice('Account deletion requested (not deleted yet)', [
            'user_id'        => $user->id,
            'email'          => $user->email,
            'erp_contact_id' => $user->erp_contact_id,
        ]);

        auth('api')->logout();

        return response()->json(['message' => 'Your account deletion request has been received.']);
    }

    public function changePassword(Request $request): JsonResponse
    {
        $request->validate([
            'current_password'      => ['required', 'string'],
            'password'              => ['required', 'string', 'min:8', 'confirmed', 'different:current_password'],
            'password_confirmation' => ['required', 'string'],
        ]);

        $user = auth('api')->user();

        if ($user->hasWooLogin()) {
            return response()->json(['message' => User::WOO_PASSWORD_MESSAGE], 403);
        }

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