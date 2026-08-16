<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\View;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Str::random(40);
        View::share('cspNonce', $nonce);
        app()->instance('mass_assignment.untrusted_request', true);

        try {
            /** @var Response $response */
            $response = $next($request);
        } finally {
            app()->forgetInstance('mass_assignment.untrusted_request');
        }

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Content-Security-Policy', $this->contentSecurityPolicy($nonce));

        if ((bool) config('security.hsts_enabled') && in_array((string) config('app.env'), ['staging', 'production'], true) && $this->isHttpsRequest($request)) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
        }

        return $response;
    }

    private function contentSecurityPolicy(string $nonce): string
    {
        return implode('; ', [
            "default-src 'self'",
            "base-uri 'self'",
            "form-action 'self'",
            "frame-ancestors 'self'",
            "object-src 'none'",
            "img-src 'self' data: https:",
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net",
            "script-src 'self' 'nonce-{$nonce}' https://cdn.jsdelivr.net",
            "script-src-attr 'none'",
            "font-src 'self' data: https://cdn.jsdelivr.net",
            "connect-src 'self'",
        ]);
    }

    private function isHttpsRequest(Request $request): bool
    {
        if ($request->isSecure()) {
            return true;
        }

        return in_array(strtolower((string) $request->server('HTTPS')), ['on', '1'], true)
            || (string) $request->server('SERVER_PORT') === '443';
    }
}
