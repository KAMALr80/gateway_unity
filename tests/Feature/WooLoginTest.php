<?php

use App\Models\User;
use App\Notifications\WooLoginDetails;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

// ERP customers sign in with their WooCommerce login, which the ERP's contact sync sends.

beforeEach(function () {
    config(['services.erp.internal_key' => 'test-key']);
});

function syncContact(array $overrides = []): TestResponse
{
    $contact = array_merge([
        'erp_contact_id' => 301,
        'email' => 'buyer@example.test',
        'name' => 'Corner Store',
        'contact_code' => 'CO0301',
        'woo_username' => 'CO0301',
        'woo_password' => 'CO0301$Uwd@123',
    ], $overrides);

    return test()->withHeader('X-Internal-Key', 'test-key')
        ->postJson('/internal/contacts/sync', ['contacts' => [$contact]])
        ->assertOk();
}

function loginAs(string $login, string $password): TestResponse
{
    return test()->postJson('/api/auth/login', ['email' => $login, 'password' => $password]);
}

it('signs in an ERP customer with the Woo password by email, Woo username or contact code', function () {
    syncContact(['woo_username' => 'cornerstore']);

    foreach (['buyer@example.test', 'cornerstore', 'CO0301'] as $login) {
        loginAs($login, 'CO0301$Uwd@123')->assertOk()
            ->assertJsonPath('user.email', 'buyer@example.test')
            ->assertJsonStructure(['access_token']);
    }

    loginAs('cornerstore', 'wrong-password')->assertStatus(401);
    loginAs('nobody', 'CO0301$Uwd@123')->assertStatus(401);
});

it('makes a changed Woo password the only one, and leaves an unchanged one un-hashed', function () {
    syncContact();
    $hash = User::where('erp_contact_id', 301)->value('password');

    syncContact();
    expect(User::where('erp_contact_id', 301)->value('password'))->toBe($hash);

    syncContact(['woo_password' => 'Fresh$Pass1']);
    loginAs('CO0301', 'CO0301$Uwd@123')->assertStatus(401);
    loginAs('CO0301', 'Fresh$Pass1')->assertOk();
});

it('replaces a password the customer set in the app with the Woo password', function () {
    $user = User::factory()->create(['email' => 'buyer@example.test', 'password' => 'my-own-password']);

    syncContact();

    expect($user->fresh()->erp_contact_id)->toBe(301);
    loginAs('buyer@example.test', 'my-own-password')->assertStatus(401);
    loginAs('buyer@example.test', 'CO0301$Uwd@123')->assertOk();
});

it('keeps the Woo password exactly as the ERP sent it', function () {
    syncContact(['woo_password' => ' spaced pass ']);

    loginAs('CO0301', ' spaced pass ')->assertOk();
});

it('gives a contact without a Woo account the usual reset link', function () {
    Notification::fake();
    syncContact(['woo_username' => null, 'woo_password' => null]);
    $user = User::where('erp_contact_id', 301)->first();
    expect($user->hasWooLogin())->toBeFalse();

    test()->postJson('/api/auth/forgot-password', ['email' => 'buyer@example.test'])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class);
    Notification::assertNotSentTo($user, WooLoginDetails::class);
});

it('leaves the login alone when an older ERP module sends no Woo fields', function () {
    syncContact();

    test()->withHeader('X-Internal-Key', 'test-key')->postJson('/internal/contacts/sync', ['contacts' => [[
        'erp_contact_id' => 301, 'email' => 'buyer@example.test', 'name' => 'Corner Store LLC',
    ]]])->assertOk();

    $user = User::where('erp_contact_id', 301)->first();
    expect($user->name)->toBe('Corner Store LLC')
        ->and($user->woo_username)->toBe('CO0301')
        ->and($user->hasWooLogin())->toBeTrue();
});

it('moves the account when the ERP contact\'s email changes, unless another account has that email', function () {
    syncContact();

    syncContact(['email' => 'new@example.test']);
    expect(User::where('erp_contact_id', 301)->value('email'))->toBe('new@example.test')
        ->and(User::count())->toBe(1);

    User::factory()->create(['email' => 'taken@example.test']);
    syncContact(['email' => 'taken@example.test'])
        ->assertJsonPath('errors.0.erp_contact_id', 301);
    expect(User::where('erp_contact_id', 301)->value('email'))->toBe('new@example.test');
});

it('emails a Woo customer their login details instead of a reset link', function () {
    Notification::fake();
    syncContact(['woo_password' => 'a*b_c*`d`']);
    $user = User::where('erp_contact_id', 301)->first();

    test()->postJson('/api/auth/forgot-password', ['email' => 'CO0301'])->assertOk();

    Notification::assertNotSentTo($user, ResetPassword::class);
    Notification::assertSentTo($user, WooLoginDetails::class, function (WooLoginDetails $notification) use ($user) {
        $html = (string) $notification->toMail($user)->render();

        // Shown exactly: Markdown must not turn * or _ into emphasis or eat the backticks.
        return str_contains($html, '<code>a*b_c*`d`</code>') && str_contains($html, '<code>CO0301</code>');
    });
});

it('does not let a Woo customer set another password', function () {
    syncContact();
    $user = User::where('erp_contact_id', 301)->first();
    $token = Password::broker()->createToken($user);

    test()->postJson('/api/auth/reset-password', [
        'token' => $token, 'email' => $user->email, 'password' => 'other-secret-1', 'password_confirmation' => 'other-secret-1',
    ])->assertStatus(422)->assertJsonPath('message', User::WOO_PASSWORD_MESSAGE);

    test()->post(route('password.update'), [
        'token' => $token, 'email' => $user->email, 'password' => 'other-secret-1', 'password_confirmation' => 'other-secret-1',
    ])->assertSessionHasErrors('email');

    $jwt = loginAs('CO0301', 'CO0301$Uwd@123')->json('access_token');
    test()->withToken($jwt)->patchJson('/api/users/me/password', [
        'current_password' => 'CO0301$Uwd@123', 'password' => 'other-secret-1', 'password_confirmation' => 'other-secret-1',
    ])->assertStatus(403);

    expect(Hash::check('CO0301$Uwd@123', $user->fresh()->password))->toBeTrue();
});

it('marks Woo customers in the admin users list and filters to them', function () {
    syncContact();
    User::factory()->create(['email' => 'app-only@example.test']);
    $admin = User::factory()->create(['is_admin' => true]);

    test()->actingAs($admin)->get('/admin/users')->assertOk()
        ->assertInertia(fn ($page) => $page->component('admin/users/index')
            ->where('users.meta.total', 3)
            ->where('users.data', fn ($users) => collect($users)->firstWhere('email', 'buyer@example.test')['has_woo_login'] === true
                && collect($users)->firstWhere('email', 'app-only@example.test')['has_woo_login'] === false
                && ! collect($users)->contains(fn ($user) => array_key_exists('woo_password', $user))));

    test()->actingAs($admin)->get('/admin/users?woo=1')->assertOk()
        ->assertInertia(fn ($page) => $page->where('users.meta.total', 1)
            ->where('users.data.0.email', 'buyer@example.test')
            ->where('filters.woo', '1'));

    test()->actingAs($admin)->get('/admin/users/'.User::where('email', 'buyer@example.test')->value('id'))->assertOk()
        ->assertInertia(fn ($page) => $page->where('user.has_woo_login', true)
            ->where('user.woo_username', 'CO0301')
            ->missing('user.woo_password'));
});
