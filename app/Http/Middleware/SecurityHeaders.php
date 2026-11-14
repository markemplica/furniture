<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = base64_encode(random_bytes(18));
        Vite::useCspNonce($nonce);
        $scripts = "'self' 'nonce-{$nonce}'";
        $connections = "'self'";

        // Allow only the configured Vite development origin when a local hot file exists.
        if (app()->isLocal() && Vite::isRunningHot()) {
            $url = parse_url(trim(file_get_contents(Vite::hotFile())));
            if (in_array($url['scheme'] ?? '', ['http', 'https'], true) && isset($url['host'])) {
                $host = $url['host'].(isset($url['port']) ? ':'.$url['port'] : '');
                $origin = $url['scheme'].'://'.$host;
                $scripts .= ' '.$origin;
                $connections .= ' '.$origin.' '.($url['scheme'] === 'https' ? 'wss://' : 'ws://').$host;
            }
        }

        $response = $next($request);
        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'DENY');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=()');
        $response->headers->set('Content-Security-Policy', implode('; ', [
            "default-src 'self'", "script-src {$scripts}", "script-src-attr 'none'",
            // Existing layouts and preview positioning use inline styles, but scripts require a nonce.
            "style-src 'self' 'unsafe-inline'", "img-src 'self' data: blob:",
            "font-src 'self' data:", "connect-src {$connections}",
            "object-src 'none'", "base-uri 'self'", "form-action 'self'", "frame-ancestors 'none'",
        ]));
        if (app()->isProduction() && $request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=15552000');
        }

        return $response;
    }
}
