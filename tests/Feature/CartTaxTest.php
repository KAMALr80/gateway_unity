<?php

use App\Models\Address;
use App\Models\Order;
use App\Models\Product;
use App\Models\User;
use App\Services\ErpOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

beforeEach(function () {
    config([
        'services.erp.order_webhook_url' => 'https://erp.test/gateway/orders',
        'services.erp.webhook_secret'    => 'secret',
    ]);
});

function taxProduct(string $erpId, float $price = 10.0, array $extra = []): Product
{
    return Product::create(array_merge([
        'erp_product_id' => $erpId, 'name' => "Item {$erpId}", 'type' => 'simple', 'regular_price' => $price,
        'is_active' => true, 'in_stock' => true,
    ], $extra));
}

function taxUser(array $extra = []): User
{
    return User::factory()->create(array_merge(['approval_status' => 'approved', 'erp_contact_id' => 77], $extra));
}

/** An ERP that taxes VAR#1 at 0.50 a unit for Georgia customers. */
function fakeErpTax(): void
{
    Http::fake(['erp.test/gateway/tax' => function (HttpRequest $request) {
        $lines = collect($request->data()['lines'])->map(fn ($line) => $line['product_id'] === 'VAR#1'
            ? ['product_id' => 'VAR#1', 'quantity' => $line['quantity'], 'tax_id' => 5, 'tax_name' => 'GA vape tax', 'tax' => 0.5 * $line['quantity']]
            : ['product_id' => $line['product_id'], 'quantity' => $line['quantity'], 'tax_id' => null, 'tax_name' => null, 'tax' => 0]);

        return Http::response([
            'state'        => 'Georgia',
            'state_source' => 'customer',
            'lines'        => $lines->all(),
            'total_tax'    => $lines->sum('tax'),
        ]);
    }]);
}

it('quotes the tax for the items the app sends, signed for the ERP', function () {
    fakeErpTax();
    $user  = taxUser(['state' => 'Georgia']);
    $vape  = taxProduct('VAR#1', 12.00);
    $plain = taxProduct('2', 3.00);

    $this->actingAs($user, 'api')->postJson('/api/cart/tax', ['items' => [
        ['product_id' => $vape->id, 'quantity' => 3],
        ['product_id' => $plain->id, 'quantity' => 2],
    ]])
        ->assertOk()
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.state', 'Georgia')
        ->assertJsonPath('data.state_source', 'account')
        ->assertJsonPath('data.state_required', false)
        ->assertJsonPath('data.items.0.tax', 1.5)
        ->assertJsonPath('data.items.0.tax_name', 'GA vape tax')
        ->assertJsonPath('data.items.1.tax', 0)
        ->assertJsonPath('data.subtotal', 42)
        ->assertJsonPath('data.total_tax', 1.5)
        ->assertJsonPath('data.total', 43.5)
        ->assertJsonMissingPath('message');

    Http::assertSent(function (HttpRequest $request) use ($user) {
        return $request->url() === 'https://erp.test/gateway/tax'
            && hash_equals(hash_hmac('sha256', $request->body(), 'secret'), $request->header('X-Webhook-Secret')[0])
            && $request['contact_id'] === 77
            && $request['email'] === $user->email
            && $request['lines'] === [['product_id' => 'VAR#1', 'quantity' => 3], ['product_id' => '2', 'quantity' => 2]];
    });
});

it('quotes the server cart when no items are sent, leaving out what can\'t be ordered', function () {
    fakeErpTax();
    $user = taxUser();
    $this->actingAs($user, 'api')->postJson('/api/cart/items', ['product_id' => taxProduct('VAR#1')->id, 'quantity' => 2]);
    $gone = taxProduct('9');
    $this->actingAs($user, 'api')->postJson('/api/cart/items', ['product_id' => $gone->id, 'quantity' => 1]);
    $gone->update(['in_stock' => false]);

    $this->actingAs($user, 'api')->postJson('/api/cart/tax')
        ->assertOk()
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.total_tax', 1);
});

it('sends the chosen address\'s state for the ERP to use when the account has none', function () {
    fakeErpTax();
    $user    = taxUser(['erp_contact_id' => null]);
    $address = Address::create(['user_id' => $user->id, 'first_name' => 'A', 'last_name' => 'B', 'address_1' => '1', 'city' => 'C', 'state' => 'GA', 'postcode' => '1', 'country' => 'US']);
    $item    = ['product_id' => taxProduct('VAR#1')->id, 'quantity' => 1];

    $this->actingAs($user, 'api')->postJson('/api/cart/tax', ['items' => [$item], 'address_id' => $address->id])->assertOk();
    Http::assertSent(fn (HttpRequest $request) => $request['state'] === 'GA' && $request['contact_id'] === null);

    $this->actingAs($user, 'api')->postJson('/api/cart/tax', ['items' => [$item], 'state' => 'Florida'])->assertOk();
    Http::assertSent(fn (HttpRequest $request) => $request['state'] === 'Florida');
});

it('says a state is required when neither the account nor the address has one', function () {
    Http::fake(['erp.test/gateway/tax' => Http::response(['state' => null, 'state_source' => null, 'lines' => [['product_id' => '2', 'quantity' => 1, 'tax' => 0]], 'total_tax' => 0])]);
    $user = taxUser(['erp_contact_id' => null]);

    $this->actingAs($user, 'api')->postJson('/api/cart/tax', ['items' => [['product_id' => taxProduct('2')->id, 'quantity' => 1]]])
        ->assertOk()
        ->assertJsonPath('data.state', null)
        ->assertJsonPath('data.state_required', true)
        ->assertJsonPath('message', 'Add your state to your address. Tax depends on it, and an order needs one.');
});

it('reports the tax as unavailable when the ERP can\'t be reached, without failing the cart', function () {
    Http::fake(['erp.test/*' => Http::response('down', 503)]);
    $user = taxUser(['state' => 'Georgia']);

    $this->actingAs($user, 'api')->postJson('/api/cart/tax', ['items' => [['product_id' => taxProduct('VAR#1', 5)->id, 'quantity' => 2]]])
        ->assertOk()
        ->assertJsonPath('data.available', false)
        ->assertJsonPath('data.state', 'Georgia')
        ->assertJsonPath('data.state_required', false)
        ->assertJsonPath('data.items.0.tax', null)
        ->assertJsonPath('data.subtotal', 10)
        ->assertJsonPath('data.total_tax', null)
        ->assertJsonPath('data.total', null);
});

it('does not call the ERP for an empty cart', function () {
    Http::fake();

    $this->actingAs(taxUser(['state' => 'Georgia']), 'api')->postJson('/api/cart/tax', ['items' => []])
        ->assertOk()
        ->assertJsonPath('data.available', true)
        ->assertJsonPath('data.total_tax', 0);

    Http::assertNothingSent();
});

it('is for approved accounts only', function () {
    $this->postJson('/api/cart/tax')->assertUnauthorized();
    $this->actingAs(taxUser(['approval_status' => 'pending']), 'api')->postJson('/api/cart/tax')->assertForbidden();
});

function stateOrderBody(User $user, Product $product, ?string $state): array
{
    $party = ['first_name' => 'Jane', 'last_name' => 'Doe', 'address_1' => '1 Main St', 'city' => 'Macon', 'postcode' => '31201', 'country' => 'US'];
    if ($state !== null) {
        $party['state'] = $state;
    }

    $body = ['items' => [['product_id' => $product->id, 'quantity' => 1]], 'billing_email' => $user->email, 'billing_phone' => '4785550100'];
    foreach ($party as $k => $v) {
        $body["billing_{$k}"]  = $v;
        $body["shipping_{$k}"] = $v;
    }

    return $body;
}

it('refuses an order without any state, and takes one with the account\'s or the address\'s', function () {
    Queue::fake();
    $product = taxProduct('2');

    $nobody = taxUser(['erp_contact_id' => null]);
    $this->actingAs($nobody, 'api')->postJson('/api/orders', stateOrderBody($nobody, $product, null))
        ->assertStatus(422)
        ->assertJsonValidationErrors('billing_state');
    $this->actingAs($nobody, 'api')->postJson('/api/orders', stateOrderBody($nobody, $product, '  '))->assertStatus(422);

    $this->actingAs($nobody, 'api')->postJson('/api/orders', stateOrderBody($nobody, $product, 'GA'))->assertCreated();

    $synced = taxUser(['state' => 'Georgia']);
    $this->actingAs($synced, 'api')->postJson('/api/orders', stateOrderBody($synced, $product, null))->assertCreated();

    expect(Order::count())->toBe(2);
});

it('can be told not to require a state', function () {
    Queue::fake();
    config(['services.erp.require_state' => false]);
    $user = taxUser(['erp_contact_id' => null]);

    $this->actingAs($user, 'api')->postJson('/api/orders', stateOrderBody($user, taxProduct('2'), null))->assertCreated();
});

it('keeps the customer\'s ERP state from the contact sync and shows it on the account', function () {
    config(['services.erp.internal_key' => 'test-key']);
    $sync = fn (array $contact) => $this->withHeader('X-Internal-Key', 'test-key')
        ->postJson('/internal/contacts/sync', ['contacts' => [array_merge(['erp_contact_id' => 501, 'email' => 'shop@example.test', 'name' => 'Shop'], $contact)]])
        ->assertOk();

    $sync(['state' => ' Georgia ']);
    $user = User::where('email', 'shop@example.test')->first();
    expect($user->state)->toBe('Georgia');

    $sync([]);   // an older ERP module: unchanged
    expect($user->fresh()->state)->toBe('Georgia');

    $this->actingAs($user, 'api')->getJson('/api/users/me')->assertJsonPath('state', 'Georgia');

    $sync(['state' => '']);
    expect($user->fresh()->state)->toBeNull();
});

it('takes the ERP\'s taxed totals once the order is in the ERP', function () {
    Queue::fake();
    $user = taxUser(['state' => 'Georgia']);
    $this->actingAs($user, 'api')->postJson('/api/orders', stateOrderBody($user, taxProduct('VAR#1', 12.00), null))->assertCreated();
    $order = Order::firstOrFail();

    Http::fake(['erp.test/gateway/orders' => Http::response(['erp_order_id' => 900, 'invoice_no' => '0042', 'total_tax' => 0.5, 'total' => 12.5], 201)]);
    app(ErpOrderService::class)->sendOrder($order);

    expect($order->fresh())
        ->erp_order_id->toBe(900)
        ->total_tax->toBe('0.50')
        ->total->toBe('12.50');
});
