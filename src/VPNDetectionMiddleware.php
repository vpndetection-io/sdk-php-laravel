<?php

declare(strict_types=1);

namespace VPNDetection\Laravel;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use VPNDetection\Middleware\Core;
use VPNDetection\Middleware\Lookup;

/**
 * Classify the visitor, and optionally refuse the request.
 *
 * Register it in `bootstrap/app.php` (Laravel 11+) or `app/Http/Kernel.php`, and
 * configure it in `config/vpndetection.php`.
 *
 * Without a `block_condition` this only enriches the request and never refuses one,
 * leaving the decision to your own controllers: the answer is on
 * `$request->attributes->get('vpndetection')`, or `VPNDetection::lookup($request)`.
 */
final class VPNDetectionMiddleware
{
    public const ATTRIBUTE = 'vpndetection';

    public function __construct(private readonly Core $core, private readonly mixed $onBlocked)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        $lookup = $this->core->evaluate($request);
        if ($lookup === null) {
            return $next($request);
        }
        $request->attributes->set(self::ATTRIBUTE, $lookup);
        if ($lookup->blocked) {
            return ($this->onBlocked)($request, $lookup);
        }
        return $next($request);
    }

    /** What the middleware found out about this visitor, or null when it did not run. */
    public static function lookup(Request $request): ?Lookup
    {
        $found = $request->attributes->get(self::ATTRIBUTE);
        return $found instanceof Lookup ? $found : null;
    }

    public static function refuse(Request $request, Lookup $lookup): Response
    {
        return new JsonResponse(['error' => 'access denied'], 403);
    }
}
