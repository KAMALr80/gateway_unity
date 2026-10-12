<?php

namespace App\Http\Controllers\Internal;

use App\Http\Controllers\Controller;
use App\Models\DeletedAccount;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
            'contacts.*.contact_code'   => ['sometimes', 'nullable', 'string', 'max:191'],
            'contacts.*.woo_username'   => ['sometimes', 'nullable', 'string', 'max:191'],
            'contacts.*.woo_password'   => ['sometimes', 'nullable', 'string', 'max:255'],
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
                DB::transaction(function () use ($data) {
                    $this->upsert($data);
                });

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

    /**
     * The account is the one with this email; failing that, the one already linked to the contact,
     * whose email then moves (the contact's email changed in the ERP). When both exist and differ,
     * the email's account takes the contact, the way the ERP gives an email to one contact, and the
     * other lets go of it so a contact never has two accounts.
     */
    private function upsert(array $data): void
    {
        $byEmail   = User::where('email', $data['email'])->first();
        $byContact = User::where('erp_contact_id', $data['erp_contact_id'])->first();

        if ($byEmail && $byContact && ! $byEmail->is($byContact)) {
            $byContact->forceFill([
                'erp_contact_id'   => null,
                'erp_contact_code' => null,
                'woo_username'     => null,
                'woo_password'     => null,
            ])->save();
        }

        $existing = $byEmail ?? $byContact;

        if ($existing) {
            // Update existing user — the password only ever changes to the Woo password
            $existing->fill([
                'name'            => $data['name'],
                'email'           => $data['email'],
                'phone'           => $data['phone']   ?? $existing->phone,
                'address'         => $data['address'] ?? $existing->address,
                'erp_contact_id'  => $data['erp_contact_id'],
                'approval_status' => 'approved',
                'approved_by'     => 'erp_sync',
            ]);
            $user = $existing;
        } else {
            // Create new user — pre-approved as ERP contact. Without a Woo password the
            // random one means they set their own through forgot password.
            $user = new User([
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

        $this->applyWooLogin($user, $data);
        $user->save();
    }

    /**
     * The customer's WooCommerce login. Their Woo password becomes their only password here;
     * it is hashed again only when it changed, so a full sync stays cheap. A field the ERP
     * didn't send (an older module) leaves the account as it is.
     */
    private function applyWooLogin(User $user, array $data): void
    {
        if (array_key_exists('contact_code', $data)) {
            $user->erp_contact_code = $data['contact_code'];
        }

        if (array_key_exists('woo_username', $data)) {
            $user->woo_username = $data['woo_username'];
        }

        if (! array_key_exists('woo_password', $data)) {
            return;
        }

        $password = $data['woo_password'];

        if ($password === null || $password === '') {
            // No Woo account: the customer manages their password here, as any app user does.
            $user->woo_password = null;

            return;
        }

        if ($user->woo_password !== $password) {
            $user->woo_password = $password;
            $user->password = Hash::make($password);
        }
    }
}
