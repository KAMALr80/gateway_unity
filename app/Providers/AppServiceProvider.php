<?php

namespace App\Providers;

use App\Auth\JwtGuard;
use App\Events\HomepageImported;
use App\Events\HomepageRestored;
use App\Models\HomepageConfig;
use App\Models\HomepageSection;
use App\Models\HomepageSectionItem;
use App\Services\FrontendRevalidator;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FrontendRevalidator::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiting();
        $this->configureJwtGuard();
        $this->configurePasswordResetLinks();
        $this->configureStorefrontRefresh();
    }

    /**
     * Any change to the homepage (admin edits, imports, restores) makes the storefront drop its cached copy,
     * so edits show up at once instead of after the storefront's 5 minute cache.
     */
    protected function configureStorefrontRefresh(): void
    {
        $refresh = fn () => app(FrontendRevalidator::class)->queue();

        foreach ([HomepageConfig::class, HomepageSection::class, HomepageSectionItem::class] as $model) {
            $model::saved($refresh);
            $model::deleted($refresh);
        }

        Event::listen([HomepageImported::class, HomepageRestored::class], $refresh);
    }

    /**
     * Customers reset their password on the storefront (it calls POST /api/auth/reset-password);
     * admins, or every user while FRONTEND_URL is unset, keep the admin panel's reset page.
     */
    protected function configurePasswordResetLinks(): void
    {
        ResetPassword::createUrlUsing(function ($user, string $token) {
            $query = ['token' => $token, 'email' => $user->getEmailForPasswordReset()];
            $frontend = rtrim((string) config('app.frontend_url'), '/');

            if ($frontend !== '' && ! $user->is_admin) {
                return $frontend.'/reset-password?'.http_build_query($query);
            }

            return url(route('password.reset', $query, false));
        });
    }

    /**
     * The "jwt" auth driver, built as the package builds it, with App\Auth\JwtGuard so that a token
     * for a user who no longer exists is no login instead of a 500.
     */
    protected function configureJwtGuard(): void
    {
        Auth::extend('jwt', function ($app, $name, array $config) {
            $guard = new JwtGuard(
                $app['tymon.jwt'],
                $app['auth']->createUserProvider($config['provider']),
                $app['request'],
                $app['events']
            );

            $guard->setTTL(Arr::get($config, 'ttl', $app->make('config')->get('jwt.ttl')));

            $app->refresh('request', $guard, 'setRequest');

            return $guard;
        });
    }

    /**
     * Rate limits for the public storefront API. The admin panel login is throttled by Fortify;
     * these protect the JWT endpoints the storefront uses.
     */
    protected function configureRateLimiting(): void
    {
        // Brute-force protection: per account+IP, plus a looser per-IP cap against credential stuffing.
        RateLimiter::for('api-login', fn (Request $request) => [
            Limit::perMinute(5)->by(Str::lower((string) $request->input('email')).'|'.$request->ip()),
            Limit::perMinute(30)->by($request->ip()),
        ]);

        // Registration, password reset e-mails and reset attempts.
        RateLimiter::for('api-auth-sensitive', fn (Request $request) => [
            Limit::perMinute(5)->by($request->ip()),
            Limit::perHour(30)->by($request->ip()),
        ]);

        // Homepage import/export is admin-only and heavy: a few operations a minute per administrator.
        RateLimiter::for('homepage-transfer', fn (Request $request) => Limit::perMinute((int) config('homepage_transfer.rate_limit_per_minute'))->by((string) ($request->user()?->id ?? $request->ip())));

        RateLimiter::for('api-token-refresh', fn (Request $request) => Limit::perMinute(30)->by($request->ip()));

        // Cart / wishlist / address writes by a signed-in user.
        RateLimiter::for('api-user-writes', fn (Request $request) => Limit::perMinute(120)->by(
            (string) (auth('api')->id() ?? $request->ip())
        ));
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
