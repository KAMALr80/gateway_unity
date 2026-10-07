<?php

use App\Models\DeletedAccount;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

function accountWithToken(array $attributes = []): array
{
    $user = User::factory()->create(array_merge(['approval_status' => 'approved', 'password' => 'secret-pass'], $attributes));

    return [$user, auth('api')->login($user)];
}

// DELETE /api/users/me is a placeholder for now: success and sign-out, nothing deleted.

it('answers success and signs the user out, whatever the password', function (array $body) {
    [$user, $token] = accountWithToken();

    $this->withToken($token)->deleteJson('/api/users/me', $body)->assertOk();

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/users/me')->assertUnauthorized();
})->with([
    'right password' => [['password' => 'secret-pass']],
    'wrong password' => [['password' => 'nope']],
    'no password'    => [[]],
]);

it('deletes nothing for now and logs the request', function () {
    Log::spy();
    [$user, $token] = accountWithToken(['erp_contact_id' => 301]);
    $product = Product::create(['name' => 'Cable', 'regular_price' => 5, 'is_active' => true, 'in_stock' => true]);
    DB::table('wishlist_items')->insert(['user_id' => $user->id, 'product_id' => $product->id, 'created_at' => now(), 'updated_at' => now()]);

    $this->withToken($token)->deleteJson('/api/users/me', ['password' => 'secret-pass'])->assertOk();

    expect(User::find($user->id))->not->toBeNull()
        ->and(DB::table('wishlist_items')->where('user_id', $user->id)->exists())->toBeTrue()
        ->and(DeletedAccount::isDeleted($user->email))->toBeFalse();

    Log::shouldHaveReceived('notice')->withArgs(fn ($message, $context) => str_contains($message, 'Account deletion requested')
        && $context['user_id'] === $user->id && $context['erp_contact_id'] === 301);
});

it('answers a pending account the same way', function () {
    [, $token] = accountWithToken(['approval_status' => 'pending']);

    $this->withToken($token)->deleteJson('/api/users/me')->assertOk();
});

it('still requires a signed-in user', function () {
    $this->deleteJson('/api/users/me')->assertUnauthorized();
});

// Kept for when deletion is real: a deleted email stays deleted through the ERP contact sync.
it('keeps a deleted account deleted when the ERP syncs the customer again, until they sign up again', function () {
    config(['services.erp.internal_key' => 'test-key']);
    DeletedAccount::remember('Gone@Example.com');

    $contact = ['contacts' => [['erp_contact_id' => 301, 'email' => 'gone@example.com', 'name' => 'Gone']]];
    $this->withHeader('X-Internal-Key', 'test-key')->postJson('/internal/contacts/sync', $contact)->assertOk();
    expect(User::where('email', 'gone@example.com')->exists())->toBeFalse();

    $this->postJson('/api/auth/register', [
        'name' => 'Back Again', 'email' => 'gone@example.com',
        'password' => 'new-secret-1', 'password_confirmation' => 'new-secret-1',
    ])->assertCreated();
    expect(DeletedAccount::isDeleted('gone@example.com'))->toBeFalse();

    $this->withHeader('X-Internal-Key', 'test-key')->postJson('/internal/contacts/sync', $contact)->assertOk();
    expect(User::where('email', 'gone@example.com')->value('erp_contact_id'))->toBe(301);
});
