<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function refreshTestToken(User $user): string
{
    return auth('api')->login($user);
}

/**
 * Feature tests share one app instance, and the JWT singletons remember the last token they parsed.
 * Reset them so the next request is read from its own Authorization header, as in production.
 */
function freshAuthState(): void
{
    app('auth')->forgetGuards();
    app('tymon.jwt.auth')->unsetToken();
    app('tymon.jwt')->unsetToken();
}

test('refresh returns 200 with a new working token and the user', function () {
    $user     = User::factory()->create(['approval_status' => 'approved']);
    $oldToken = refreshTestToken($user);
    freshAuthState();

    $response = $this->withHeader('Authorization', "Bearer {$oldToken}")
        ->postJson('/api/auth/refresh')
        ->assertOk()
        ->assertJsonStructure(['access_token', 'token_type', 'expires_in', 'user' => ['id', 'email', 'approval_status']])
        ->assertJsonPath('user.id', $user->id)
        ->assertJsonPath('user.email', $user->email);

    $newToken = $response->json('access_token');
    expect($newToken)->toBeString()->not->toBe($oldToken);

    freshAuthState();
    $this->withHeader('Authorization', "Bearer {$newToken}")
        ->getJson('/api/users/me')
        ->assertOk()
        ->assertJsonPath('id', $user->id);
});

test('the old token stops working after a refresh (blacklist)', function () {
    $user     = User::factory()->create(['approval_status' => 'approved']);
    $oldToken = refreshTestToken($user);
    freshAuthState();

    $this->withHeader('Authorization', "Bearer {$oldToken}")->postJson('/api/auth/refresh')->assertOk();

    freshAuthState();
    $this->withHeader('Authorization', "Bearer {$oldToken}")->getJson('/api/users/me')->assertUnauthorized();
});

test('refresh without a token or with garbage is a 401, never a 500', function () {
    $this->postJson('/api/auth/refresh')->assertUnauthorized();
    $this->withHeader('Authorization', 'Bearer not.a.jwt')->postJson('/api/auth/refresh')->assertUnauthorized();
});
