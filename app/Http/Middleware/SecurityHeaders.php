<?php

namespace App\Http\Middleware;

use App\Support\AppUrl;
use App\Support\GoogleAnalytics;
use App\Support\GoogleCustomerReviews;
use App\Support\MetaPixel;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Vite;
use Symfony\Component\HttpFoundation\Response;

class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $nonce = Vite::useCspNonce();
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('X-Frame-Options', 'SAMEORIGIN');
        $response->headers->set('Referrer-Policy', 'strict-origin-when-cross-origin');
        $response->headers->set('Cross-Origin-Opener-Policy', 'same-origin-allow-popups');
        $response->headers->set('Permissions-Policy', 'camera=(), microphone=(), geolocation=(), payment=()');

        if ($request->isSecure()) {
            $response->headers->set('Strict-Transport-Security', 'max-age=31536000; includeSubDomains; preload');
        }

        if (str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            $content = $response->getContent();

            if (is_string($content) && str_contains($content, 'localhost')) {
                $response->setContent(AppUrl::sanitizeLocalhostHtmlUrls($content));
            }
        }

        if (! app()->environment('local') && str_contains((string) $response->headers->get('Content-Type'), 'text/html')) {
            $scriptHosts = [];
            $connectSrc = "'self'";
            $frameSrc = "'self'";
            $csp = [
                "default-src 'self'",
            ];
            $allowGoogleCustomerReviews = GoogleCustomerReviews::enabled()
                && $this->isCheckoutSuccessPage($request);

            if (GoogleAnalytics::enabled()) {
                $scriptHosts[] = 'https://www.googletagmanager.com';
                $connectSrc .= ' https://www.google-analytics.com https://*.google-analytics.com https://analytics.google.com https://www.google.com https://www.googletagmanager.com';
            }

            if (MetaPixel::enabled()) {
                $scriptHosts[] = 'https://connect.facebook.net';
                $connectSrc .= ' https://connect.facebook.net https://www.facebook.com https://capig.datah04.com';
            }

            if ($allowGoogleCustomerReviews) {
                $scriptHosts[] = 'https://apis.google.com';
                $scriptHosts[] = 'https://www.gstatic.com';
                $connectSrc .= ' https://www.google.com https://apis.google.com';
                $frameSrc .= ' https://www.google.com https://apis.google.com';
            }

            $scriptHostList = $scriptHosts === [] ? '' : ' '.implode(' ', array_unique($scriptHosts));

            // gapi surveyoptin runs javascript: URLs. Nonces in script-src / script-src-elem
            // make browsers ignore 'unsafe-inline' and block those URLs, so the thank-you
            // page uses host allowlists without a nonce. All other pages stay nonce-based.
            if ($allowGoogleCustomerReviews) {
                $csp[] = "script-src 'self' 'unsafe-inline'{$scriptHostList}";
            } else {
                $csp[] = "script-src 'self' 'nonce-{$nonce}'{$scriptHostList}";
            }

            $csp = array_merge($csp, [
                "style-src 'self' 'unsafe-inline'",
                "img-src 'self' data: https:",
                "font-src 'self' data:",
                "connect-src {$connectSrc}",
                "frame-src {$frameSrc}",
                "object-src 'none'",
                "base-uri 'self'",
                "form-action 'self' https://www.liqpay.ua https://pay.monobank.ua",
                "frame-ancestors 'self'",
                'upgrade-insecure-requests',
            ]);

            $response->headers->set('Content-Security-Policy', implode('; ', $csp));
        }

        return $response;
    }

    private function isCheckoutSuccessPage(Request $request): bool
    {
        $routeName = (string) $request->route()?->getName();

        return $routeName === 'checkout.success'
            || str_ends_with($routeName, '.checkout.success')
            || preg_replace('/\.ru$/', '', $routeName) === 'checkout.success';
    }
}
