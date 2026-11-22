<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Map;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class SetMapShareToken
{
    /**
     * Old links still pass the token in the query string. A matching token is
     * stored in the session and, on GET, the browser is sent to the same URL
     * without the token so it does not stay in the address bar.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $shareToken = $request->query('share_token');
        $map = $request->route('map');

        if (! is_string($shareToken) || $shareToken === '' || ! $map instanceof Map) {
            return $next($request);
        }

        $expected = $map->share_token;

        if (! is_string($expected) || $expected === '' || ! hash_equals($expected, $shareToken)) {
            return $next($request);
        }

        $request->session()->put("map_share_token_{$map->id}", $shareToken);

        if (! $request->isMethod('GET')) {
            return $next($request);
        }

        $query = $request->query();
        unset($query['share_token']);

        $target = $request->url();

        if ($query !== []) {
            $target .= '?'.http_build_query($query);
        }

        return redirect()->to($target);
    }
}
