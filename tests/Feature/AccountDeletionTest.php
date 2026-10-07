<?php

use App\Models\DeletedAccount;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

function accountWithToken(array $attributes = []): array
{
    $user = User::factory()->create(array_merge(['approval_status' => 'approved', 'password' => 'secret-pass'], $attributes));

    return [$user, auth('api')->login($user)];
}

it('deletes the account, keeps its orders detached and stops the token working', function () {
    Queue::fake();
    [$user, $token] = accountWithToken();
    $product = Product::create(['name' => 'Cable', 'regular_price' => 5, 'is_active' => true, 'in_stock' => true]);
    DB::table('wishlist_items')->insert(['user_id' => $user->id, 'product_id' => $product->id, 'created_at' => now(), 'updated_at' => now()]);

    $address = ['first_name' => 'Jane', 'last_name' => 'Doe', 'address_1' => '1 Main St', 'city' => 'Centerville', 'postcode' => '31028', 'country' => 'US'];
    $order = ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'billing_email' => 'jane@example.com', 'billing_phone' => '4785550100'];
    foreach ($address as $field => $value) {
        $order["billing_{$field}"] = $order["shipping_{$field}"] = $value;
    }
    $orderId = $this->withToken($token)->postJson('/api/orders', $order)->assertCreated()->json('data.id') ?? Order::value('id');

    $this->withToken($token)->deleteJson('/api/users/me', ['password' => 'secret-pass'])->assertOk();

    expect(User::find($user->id))->toBeNull()
        ->and(DB::table('wishlist_items')->where('user_id', $user->id)->exists())->toBeFalse()
        ->and(Order::find($orderId))->not->toBeNull()
        ->and(Order::find($orderId)->user_id)->toBeNull()
        ->and(DeletedAccount::isDeleted($user->email))->toBeTrue();

    app('auth')->forgetGuards();
    $this->withToken($token)->getJson('/api/users/me')->assertUnauthorized();
});

it('deletes an account that has no orders', function () {
    [$user, $token] = accountWithToken();

    $this->withToken($token)->deleteJson('/api/users/me', ['password' => 'secret-pass'])->assertOk();

    expect(User::find($user->id))->toBeNull();
});

it('refuses a wrong or missing password with 422, never 401 or 403', function (array $body) {
    [$user, $token] = accountWithToken();

    $this->withToken($token)->deleteJson('/api/users/me', $body)
        ->assertStatus(422)
        ->assertJsonValidationErrors('password');

    expect(User::find($user->id))->not->toBeNull();
})->with([
    'wrong password'   => [['password' => 'nope']],
    'missing password' => [[]],
]);

it('lets a pending account delete itself', function () {
    [$user, $token] = accountWithToken(['approval_status' => 'pending']);

    $this->withToken($token)->deleteJson('/api/users/me', ['password' => 'secret-pass'])->assertOk();

    expect(User::find($user->id))->toBeNull();
});

it('keeps a deleted account deleted when the ERP syncs the customer again, until they sign up again', function () {
    config(['services.erp.internal_key' => 'test-key']);
    [$user, $token] = accountWithToken(['email' => 'Gone@Example.com']);
    $this->withToken($token)->deleteJson('/api/users/me', ['password' => 'secret-pass'])->assertOk();

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
