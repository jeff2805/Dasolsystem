<?php

namespace App\Http\Middleware;

use App\Enums\PaymentProvider;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Security headers on every web page.
 *
 * Map tile hosts are allowed through img-src and routing services
 * through connect-src. Both are read from config/visitdasol.php, so a
 * provider is changed in one place and the policy follows.
 *
 * Signed-in pages also get Cache-Control: no-store.
 */
class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Map tile providers are read from config/visitdasol.php.
        $tiles = $this->hosts([
            config('visitdasol.map.tile_url'),
            config('visitdasol.map.satellite_url'),
            config('visitdasol.map.satellite_labels_url'),
            config('visitdasol.map.satellite_roads_url'),

            /*
             * api.mapbox.com was named here by hand while the street
             * and satellite layers came from Mapbox. They come from
             * OpenStreetMap and Esri now, and both are read from the
             * config above, so a hardcoded host would only permit a
             * provider this application no longer calls.
             *
             * Nothing replaces it: every tile host belongs in
             * config/visitdasol.php, where changing a provider updates
             * the policy with it.
             */
        ], 'tile.openstreetmap.org');

        $routing = $this->hosts([
            config('visitdasol.map.routing_url'),
            config('visitdasol.map.routing_table_url'),
        ]);

        /*
         * Payment gateway permissions.
         */
        $checkout = $this->hosts(array_merge(
            PaymentProvider::PayMongo->isConfigured()
                ? ['https://checkout.paymongo.com']
                : [],
            PaymentProvider::PayPal->isConfigured()
                ? ['https://www.paypal.com', 'https://www.sandbox.paypal.com']
                : [],
        ));

        $policy = implode('; ', [
            "default-src 'self'",
            "script-src 'self'",
            "style-src 'self' 'unsafe-inline'",
            'img-src '.trim("'self' data: ".$tiles),
            "font-src 'self'",
            // The routing service, and nothing else. Mapbox was named
            // here too and is no longer called.
            'connect-src '.trim("'self' ".$routing),
            "object-src 'none'",
            "base-uri 'self'",
            'form-action '.trim("'self' ".$checkout),
            "frame-ancestors 'self'",
        ]);

        $headers = [
            'Content-Security-Policy' => $policy,
            'X-Content-Type-Options' => 'nosniff',
            'X-Frame-Options' => 'SAMEORIGIN',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
            'Permissions-Policy' => 'camera=(), microphone=(), payment=(), geolocation=(self)',
        ];

        foreach ($headers as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        // Prevent caching of authenticated pages.
        if ($request->user() !== null) {
            $response->headers->set('Cache-Control', 'no-store, private, max-age=0');
            $response->headers->set('Pragma', 'no-cache');
            $response->headers->set('Expires', '0');
        }

        return $response;
    }

    /**
     * Extract HTTPS origins from configured URLs.
     *
     * @param list<string|null> $urls
     */
    private function hosts(array $urls, ?string $fallback = null): string
    {
        $hosts = [];

        foreach ($urls as $url) {
            $host = is_string($url) && $url !== ''
                ? parse_url($url, PHP_URL_HOST)
                : null;

            if (is_string($host) && $host !== '') {
                $hosts[] = 'https://'.$host;
            }
        }

        if ($hosts === [] && $fallback !== null) {
            $hosts[] = 'https://'.$fallback;
        }

        return implode(' ', array_unique($hosts));
    }
}
