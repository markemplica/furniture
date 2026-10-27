<?php

namespace App\Support;

use Illuminate\Http\Request;

class SafeRedirectTarget
{
    public static function resolve(string $redirect, Request $request, string $fallback): string
    {
        if (str_contains($redirect, '\\') || preg_match('/[\x00-\x1F\x7F]/', $redirect)) {
            return $fallback;
        }

        if (str_starts_with($redirect, '/') && ! str_starts_with($redirect, '//')) {
            return $redirect;
        }

        $parts = parse_url($redirect);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = $parts['host'] ?? null;

        if (in_array($scheme, ['http', 'https'], true) && is_string($host) && $host === $request->getHost()) {
            return $redirect;
        }

        return $fallback;
    }
}
