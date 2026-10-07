<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\DeletedAccount;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class ContactSyncController extends Controller
{
    public function sync(Request $request): JsonResponse
    {
        $request->validate([
            'contacts'                  => ['required', 'array'],
            'contacts.*.erp_contact_id' => ['required', 'integer'],
            'contacts.*.email'          => ['required', 'email'],
            'contacts.*.name'           => ['required', 'string'],
            'contacts.*.phone'          => ['sometimes', 'nullable', 'string'],
            'contacts.*.address'        => ['sometimes', 'nullable', 'string'],
        ]);

        $synced = 0;
        $errors = [];

        foreach ($request->contacts as $data) {
            // The customer deleted their app account; the ERP still knows them,
            // but the account stays deleted until they sign up again.
            if (DeletedAccount::isDeleted($data['email'])) {
                continue;
            }

            try {
                $existing = User::where('email', $data['email'])->first();

                if ($existing) {
                    // Update existing user — never overwrite password
                    $existing->update([
                        'name'            => $data['name'],
                        'phone'           => $data['phone']   ?? $existing->phone,
                        'address'         => $data['address'] ?? $existing->address,
                        'erp_contact_id'  => $data['erp_contact_id'],
                        'approval_status' => 'approved',
                        'approved_by'     => 'erp_sync',
                    ]);
                } else {
                    // Create new user with a random password — pre-approved as ERP contact
                    // They must use forgot password flow to set their own password
                    User::create([
                        'name'            => $data['name'],
                        'email'           => $data['email'],
                        'password'        => Hash::make(Str::random(32)),
                        'phone'           => $data['phone']   ?? null,
                        'address'         => $data['address'] ?? null,
                        'erp_contact_id'  => $data['erp_contact_id'],
                        'approval_status' => 'approved',
                        'approved_by'     => 'erp_sync',
                    ]);
                }

                $synced++;

            } catch (\Throwable $e) {
                $errors[] = [
                    'erp_contact_id' => $data['erp_contact_id'],
                    'error'          => $e->getMessage(),
                ];
            }
        }

        return response()->json([
            'message' => "Synced {$synced} contacts",
            'errors'  => $errors,
        ]);
    }
}
