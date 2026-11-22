<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bearer tokens must include the named ability. A first-party session has no
 * access token, so the map policies stay in charge of those requests.
 */
final class RequireTokenAbility
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string ...$abilities): Response
    {
        $token = $request->user()?->currentAccessToken();

        if (! $token instanceof PersonalAccessToken) {
            return $next($request);
        }

        foreach ($abilities as $ability) {
            if (! $token->can($ability)) {
                abort(Response::HTTP_FORBIDDEN, 'This token does not have the required ability.');
            }
        }

        return $next($request);
    }
}
