<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Notifications\WooLoginDetails;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use PHPOpenSourceSaver\JWTAuth\Contracts\JWTSubject;

#[Fillable(['name', 'email', 'password', 'phone', 'address', 'erp_contact_id', 'approval_status', 'approved_by', 'is_admin'])]
#[Hidden(['password', 'woo_password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements JWTSubject
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, TwoFactorAuthenticatable;

    /** Why a WooCommerce customer can't set a password here. */
    public const WOO_PASSWORD_MESSAGE = 'Your password is the same as on our website and can\'t be changed here. Use "Forgot password" to have it emailed to you.';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
            'is_admin' => 'boolean',
            'catalog_hidden_product_ids' => 'array',
            'woo_password' => 'encrypted',
        ];
    }

    /**
     * The account a login name belongs to: an email, the WooCommerce username, or the ERP
     * contact code (e.g. "CO0123"), tried in that order. The app sends any of them as "email".
     */
    public static function findForLogin(string $login): ?self
    {
        $login = trim($login);

        if ($login === '') {
            return null;
        }

        return static::where('email', $login)->first()
            ?? static::where('woo_username', $login)->first()
            ?? static::where('erp_contact_code', $login)->first();
    }

    /** An ERP customer whose only password is their WooCommerce one, set by the contact sync. */
    public function hasWooLogin(): bool
    {
        return $this->woo_password !== null && $this->woo_password !== '';
    }

    /** hasWooLogin() as an attribute the admin pages append (woo_password itself stays hidden). */
    protected function getHasWooLoginAttribute(): bool
    {
        return $this->hasWooLogin();
    }

    /** The username their WooCommerce login emails show: the Woo username, else the contact code. */
    public function wooLoginName(): string
    {
        return $this->woo_username ?: (string) $this->erp_contact_code;
    }

    /** "Forgot password" emails a Woo customer their login details; nobody can reset that password here. */
    public function sendPasswordResetNotification($token): void
    {
        if ($this->hasWooLogin()) {
            $this->notify(new WooLoginDetails);

            return;
        }

        parent::sendPasswordResetNotification($token);
    }

    public function getJWTIdentifier(): mixed
    {
        return $this->getKey();
    }

    public function getJWTCustomClaims(): array
    {
        return [];
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }
}
