<?php

declare(strict_types=1);

return [
    // Your API key. The free allowance is counted per source address and a server is
    // one source address, so this is what makes the middleware usable in production.
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
];
