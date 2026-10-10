<?php

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

/** Where the password-reset email sends a user, and that the storefront's reset call completes it. */
function resetLinkFor(User $user): string
{
    Notification::fake();
    test()->postJson('/api/auth/forgot-password', ['email' => $user->email])->assertOk();
    $url = null;
    Notification::assertSentTo($user, ResetPassword::class, function (ResetPassword $notification) use ($user, &$url) {
        $url = $notification->toMail($user)->actionUrl;

        return true;
    });

    return $url;
}

it('sends customers to the storefront reset page when FRONTEND_URL is set', function () {
    config(['app.frontend_url' => 'https://shop.example/']);
    $user = User::factory()->create(['email' => 'buyer@example.test']);

    expect(resetLinkFor($user))->toStartWith('https://shop.example/reset-password?token=')
        ->toContain('email=buyer%40example.test');
});

it('keeps admins, and everyone while FRONTEND_URL is unset, on the admin reset page', function () {
    config(['app.frontend_url' => 'https://shop.example']);
    $admin = User::factory()->create(['is_admin' => true]);
    expect(resetLinkFor($admin))->not->toContain('shop.example');

    config(['app.frontend_url' => null]);
    $customer = User::factory()->create();
    expect(resetLinkFor($customer))->not->toContain('shop.example')->toContain('/reset-password/');
});

it('resets the password through the API with the emailed token', function () {
    config(['app.frontend_url' => 'https://shop.example']);
    $user = User::factory()->create(['email' => 'buyer@example.test']);
    parse_str(parse_url(resetLinkFor($user), PHP_URL_QUERY), $query);

    $this->postJson('/api/auth/reset-password', [
        'token' => $query['token'], 'email' => $query['email'], 'password' => 'new-secret-123', 'password_confirmation' => 'new-secret-123',
    ])->assertOk();

    expect(Hash::check('new-secret-123', $user->fresh()->password))->toBeTrue();
    $this->postJson('/api/auth/reset-password', [
        'token' => $query['token'], 'email' => $query['email'], 'password' => 'again-secret-123', 'password_confirmation' => 'again-secret-123',
    ])->assertStatus(422);
});
