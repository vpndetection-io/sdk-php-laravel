# [<img src="https://s3.vpndetection.io/vpndetection-public/brand/mark.svg" alt="VPNDetection" width="24"/>](https://vpndetection.io/) VPNDetection Laravel Middleware

[![Packagist](https://img.shields.io/packagist/v/vpndetection/laravel.svg)](https://packagist.org/packages/vpndetection/laravel)
[![license](https://img.shields.io/packagist/l/vpndetection/laravel.svg)](LICENSE)

The official Laravel Middleware for the [VPNDetection](https://vpndetection.io) API.

It classifies the visitor behind each request — VPN, residential proxy, Tor, hosting, CDN, relay — and hands the answer to your code. Blocking is opt-in.

## Getting Started

```bash
composer require vpndetection/laravel
```

Requires PHP 8.2 or newer.

You need an API key. Create one in the [console](https://app.vpndetection.io); the free tier's allowance is counted per source address, and a server is a single source address, so a key is what makes this usable in production rather than optional.

The service provider is auto-discovered. Publish the config and register the middleware:

```bash
php artisan vendor:publish --tag=vpndetection-config
```

```php
// bootstrap/app.php (Laravel 11+)
->withMiddleware(function (Middleware $middleware) {
    $middleware->append(\VPNDetection\Laravel\VPNDetectionMiddleware::class);
})
```

Set `VPNDETECTION_API_KEY` in your `.env`.

```php
use VPNDetection\Laravel\VPNDetectionMiddleware;

Route::get("/", function (Request $request) {
    $lookup = VPNDetectionMiddleware::lookup($request);
    return $lookup?->result?->isVpn ? "Hello, VPN user" : "Hello";
});
```

By default nothing is blocked. Every request gets an answer and your own code decides what that means — which is usually what you want, because whether a VPN visitor is a problem depends entirely on what they are doing.

## Blocking

Set a `block_condition` and a matching request is answered with `403` and never reaches your code.

```php
'block_condition' => ['isVpn' => true],
```

A condition is written in the shape of a `Result`, and only the members you name are considered. That lets it reach the evidence, not just the flags:

```php
['isVpn' => true, 'vpn' => ['provider' => 'nordvpn']]        // one provider
['isResproxy' => true, 'resproxy' => ['hits' => ['gte' => 5]]]  // a numeric threshold
['vpn' => ['confidence' => ['high', 'medium']]]              // any of these
[['isTor' => true], ['isResproxy' => true]]                  // a list is OR
```

Values are matched by equality, strings without regard to case. A LIST means any-of. A map of `gte`/`gt`/`lte`/`lt` compares numbers, and every bound you give must hold, so two of them are a range. Members set to `false` or `null` are ignored, so a condition states the signals you act on; one that constrains nothing would match every request, and is refused when the middleware is built rather than silently blocking all your traffic.

## Where the client address comes from

This is the setting that decides whether any of the above works, and it is the one thing only you can get right.

By default the middleware uses `$request->ip()`, which honours Laravel's own `TrustProxies` middleware. That is the right fix behind a load balancer: set `TrustProxies` to the proxies actually in front of you and `ip()` walks the chain for you. Without it, and behind one, every visitor looks like the load balancer — a datacenter address, so a hosting rule would block all of them.

For an edge that writes the address into its own header, name the header:

```php
// config/vpndetection.php
'ip_selector' => \VPNDetection\Laravel\Selectors::header('CF-Connecting-IP'),
```

`\VPNDetection\Laravel\Selectors::xff()` reads `X-Forwarded-For` directly. Be aware that the left-most entry is whatever the caller sent, because proxies append to that header — it is only trustworthy when an edge you control overwrites it. If you know how many proxies sit in front, count from the right instead: `Selectors::xff(1)` is the address your nearest proxy saw.

Anything else, pass your own callable. It receives the request and returns an address.

If the address resolves to a private one, the middleware says so once. That is expected locally and is the signal to fix your configuration anywhere else.

## When a lookup fails

The request is let through, and the reason is on the answer's `error`. Our outage should not become yours, so a network failure, an exhausted quota or a rejected key all fail open. Set `fail_closed` to block instead. Private addresses are answered locally and never fail, so this will not lock you out in development.

## Cost and latency

Answers are cached for an hour, so a returning visitor costs nothing, and private addresses never leave the process. A cache miss is one request to our API, bounded at 2.5 seconds by default and not retried — on a request path, failing open quickly beats holding a visitor while we try again.

Beyond a few million distinct visitors a day, stop calling the API per request: [download the dataset](https://vpndetection.io/databases) and look addresses up locally instead.

## Absent is not false

Only `ip` and `isVpn` come back on every plan. A property your plan does not include is `null`, which means "not in your plan" rather than "checked, and no".

```php
$lookup->result->isHosting ?? false   // when you only want the flag
```

A `block_condition` naming a member your plan does not serve can never match, so the middleware warns once instead of failing silently. Set `on_missing_field` to `throw` to make it an error.

## Other Libraries

There are official VPNDetection client libraries available for many languages including PHP, Python, Go, Java, Ruby, and many popular frameworks such as Django, Rails, and Laravel. See our GitHub at https://github.com/vpndetection-io for more.

## About VPNDetection

VPN Detection API: Accurate anonymity detection identifying VPNs, residential proxies, hosting servers, Tor nodes, CDNs, relays and more.

[<img src="https://s3.vpndetection.io/vpndetection-public/brand/mark.svg" alt="VPNDetection" width="96"/>](https://vpndetection.io/)

## License

This project is licensed under the [MIT License](LICENSE).
