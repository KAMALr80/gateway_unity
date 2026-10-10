<?php

use App\Models\Product;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/**
 * A signed, unexpired token whose user no longer exists (deleted account, deleted by an admin)
 * must be treated as no login: 401 on protected routes, guest on public ones, never a 500.
 */
beforeEach(function () {
    Http::preventStrayRequests();
    Product::create(['name' => 'Stale token product', 'regular_price' => 5, 'is_active' => true, 'in_stock' => true]);
});

/** Each request reads its own Authorization header, as a fresh server process would. */
function staleTokenReset(): void
{
    app('auth')->forgetGuards();
    app('tymon.jwt.auth')->unsetToken();
    app('tymon.jwt')->unsetToken();
}

function tokenForDeletedUser(): string
{
    $user = User::factory()->create(['approval_status' => 'approved']);
    $token = auth('api')->login($user);
    $user->forceDelete();
    staleTokenReset();

    return $token;
}

test('a valid token for an existing user still works', function () {
    $user = User::factory()->create(['approval_status' => 'approved']);
    $token = auth('api')->login($user);
    staleTokenReset();

    $this->withToken($token)->getJson('/api/users/me')->assertOk()->assertJsonPath('id', $user->id);
    staleTokenReset();
    $this->withToken($token)->getJson('/api/products')->assertOk()->assertJsonPath('data.0.prices_visible', true);
});

test('invalid, tampered and expired tokens are 401 on protected routes and guest on public ones', function () {
    $user = User::factory()->create(['approval_status' => 'approved']);
    $good = auth('api')->login($user);
    [$h, $p, $s] = explode('.', $good);
    $tampered = "$h.$p." . strrev($s);
    staleTokenReset();

    foreach (['garbage' => 'not.a.token', 'tampered' => $tampered] as $label => $token) {
        $this->withToken($token)->getJson('/api/users/me')->assertUnauthorized();
        staleTokenReset();
        $this->withToken($token)->getJson('/api/products')->assertOk()->assertJsonPath('data.0.prices_visible', false);
        staleTokenReset();
    }

    $this->travel(config('jwt.ttl') + 5)->minutes();
    $this->withToken($good)->getJson('/api/users/me')->assertUnauthorized();
    staleTokenReset();
    $this->withToken($good)->getJson('/api/products')->assertOk()->assertJsonPath('data.0.prices_visible', false);
});

test('a token for a deleted user is a guest on public product routes', function () {
    $token = tokenForDeletedUser();

    $this->getJson('/api/products')->assertOk();
    staleTokenReset();

    $this->withToken($token)->getJson('/api/products')
        ->assertOk()
        ->assertJsonPath('data.0.prices_visible', false)
        ->assertJsonPath('data.0.current_price', null);
    staleTokenReset();

    $id = Product::first()->id;
    $this->withToken($token)->getJson("/api/products/{$id}")->assertOk()->assertJsonPath('prices_visible', false);
    staleTokenReset();
    $this->withToken($token)->getJson('/api/categories')->assertOk();
});

test('a token for a deleted user is 401 (never 500) on protected routes, without leaking details', function () {
    $token = tokenForDeletedUser();

    foreach (['/api/users/me', '/api/cart', '/api/orders', '/api/wishlist', '/api/addresses'] as $path) {
        $response = $this->withToken($token)->getJson($path);
        $response->assertUnauthorized();
        expect($response->json())->not->toHaveKeys(['exception', 'trace', 'file']);
        staleTokenReset();
    }
});

test('a token for a deleted user can be neither refreshed nor used to log out', function () {
    $token = tokenForDeletedUser();

    $this->withToken($token)->postJson('/api/auth/refresh')->assertUnauthorized();
    staleTokenReset();
    $this->withToken($token)->postJson('/api/auth/logout')->assertUnauthorized();
});
