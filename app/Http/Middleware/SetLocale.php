<?php

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\App;
use Symfony\Component\HttpFoundation\Response;

class SetLocale
{
    /**
     * Supported application locales.
     */
    public const SUPPORTED_LOCALES = ['id', 'en'];

    /**
     * Default application fallback locale.
     */
    public const DEFAULT_LOCALE = 'id';

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $locale = session()->get('locale', self::DEFAULT_LOCALE);

        if (!in_array($locale, self::SUPPORTED_LOCALES, true)) {
            $locale = self::DEFAULT_LOCALE;
            session()->put('locale', self::DEFAULT_LOCALE);
        }

        App::setLocale($locale);
        Carbon::setLocale($locale);

        $response = $next($request);

        // Sanitize redirect responses on locale routes to prevent open redirects
        if ($response instanceof RedirectResponse && $request->is('lang/*')) {
            $targetUrl = $response->getTargetUrl();
            if (!$this->isSafeInternalUrl($targetUrl, $request)) {
                $response->setTargetUrl(url('/'));
            }
        }

        return $response;
    }

    /**
     * Validate that the redirect destination is a safe internal URL.
     */
    protected function isSafeInternalUrl(string $url, Request $request): bool
    {
        // Block protocol-relative URLs (e.g. //evil.com)
        if (str_starts_with($url, '//')) {
            return false;
        }

        // Relative path starting with single slash (e.g. /backoffice/dashboard)
        if (str_starts_with($url, '/') && !str_starts_with($url, '//')) {
            return true;
        }

        $scheme = parse_url($url, PHP_URL_SCHEME);
        $host = parse_url($url, PHP_URL_HOST);

        // Fully qualified URL must match application request host with http or https
        if ($host && in_array(strtolower((string) $scheme), ['http', 'https'], true)) {
            return strcasecmp($host, $request->getHost()) === 0;
        }

        return false;
    }
}
