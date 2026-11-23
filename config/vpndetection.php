<?php

declare(strict_types=1);

return [
    // Your API key. Without one a lookup leaves out the hosting, CDN and proxy
    // flags, so this is what makes the middleware usable in production.
    'api_key' => env('VPNDETECTION_API_KEY'),

    // What to block on, in the shape of a Result. Leave it null to only enrich the
    // request and decide in your own controllers.
    //
    //   'block_condition' => ['isVpn' => true],
    //   'block_condition' => ['isResproxy' => true, 'resproxy' => ['hits' => ['gte' => 5]]],
    'block_condition' => null,

    // Seconds a lookup may hold the request, and how many times to retry. Both are set
    // for a request path rather than a script: failing open quickly beats holding a
    // visitor while we try again.
    'timeout' => 2.5,
    'retries' => 0,

    // Block when the lookup itself fails. Our outage should not become yours.
    'fail_closed' => false,

    // How the client address is decided. Null uses $request->ip(), which honours your
    // TrustProxies middleware - configure that first if you are behind a proxy.
    'ip_selector' => null,

    'on_missing_field' => 'warn',

    // Send each request's own User-Agent with its lookup, and never refuse a request
    // whose answer says a crawler's operator vouches for this address (a userAgent
    // verdict of 'verified'), whatever block_condition matches. Only an answer with
    // every field carries the verdict, so without a paid key nothing is let through
    // by it. Off by default: on, answers are cached per address and user agent.
    'allow_bots' => false,

    // The store from config/cache.php that answers are kept in between requests: under
    // PHP-FPM the client, and a cache of its own, lasts one request. Null is your
    // default store; false keeps answers in the client alone.
    'cache_store' => null,
];
