<?php

namespace App\Auth;

use Illuminate\Contracts\Auth\Authenticatable;
use PHPOpenSourceSaver\JWTAuth\JWTGuard as BaseJwtGuard;

/**
 * The package guard, except that a valid token whose user no longer exists (deleted account) is no login.
 *
 * JWTGuard::user() passes the user provider's result straight to setUser(), which only accepts a user, so
 * a missing user was a TypeError (HTTP 500) on every route that looked at the user, public ones included.
 * The token's signature, expiry and blacklist are still checked by the package before this point.
 */
class JwtGuard extends BaseJwtGuard
{
    public function setUser(?Authenticatable $user)
    {
        if ($user === null) {
            return $this;
        }

        return parent::setUser($user);
    }
}
