<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class InternalApiKey
{
    public function handle(Request $request, Closure $next)
    {
        $key      = (string) $request->header('X-Internal-Key', '');
        $expected = (string) config('services.erp.internal_key', '');

        // Constant-time comparison; an unset key on the server never authorises anything.
        if ($expected === '' || $key === '' || ! hash_equals($expected, $key)) {
            return response()->json(['message' => 'Unauthorized'], 401);
        }

        return $next($request);
    }
}