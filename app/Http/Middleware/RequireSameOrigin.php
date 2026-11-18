<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * CSRF protection without tokens.
 *
 * Browsers resend cached Basic-auth credentials on cross-site requests, so a
 * hostile page could submit a form to a state-changing endpoint. A plain HTML
 * form can't send a JSON body or a custom header without a CORS preflight
 * (which we never grant), so requiring one of those, plus a same-origin check
 * when the browser tells us, blocks that.
 */
class RequireSameOrigin
{
    private const SAFE_METHODS = ['GET', 'HEAD', 'OPTIONS'];

    public function handle(Request $request, Closure $next): Response
    {
        if (in_array($request->getMethod(), self::SAFE_METHODS, true)) {
            return $next($request);
        }

        if (! $this->isSameOrigin($request) || ! $this->isScriptRequest($request)) {
            return response()->json(['message' => 'Cross-site request refused.'], 403);
        }

        return $next($request);
    }

    private function isSameOrigin(Request $request): bool
    {
        $fetchSite = $request->headers->get('Sec-Fetch-Site');
        if ($fetchSite !== null) {
            return $fetchSite === 'same-origin';
        }

        $origin = $request->headers->get('Origin');
        if ($origin !== null) {
            return rtrim($origin, '/') === $request->getSchemeAndHttpHost();
        }

        // Neither header: not a modern browser (e.g. curl), so not CSRF.
        return true;
    }

    private function isScriptRequest(Request $request): bool
    {
        return $request->isJson()
            || $request->headers->get('X-Requested-With') === 'XMLHttpRequest';
    }
}
