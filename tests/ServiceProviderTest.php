<?php

declare(strict_types=1);

namespace VPNDetection\Laravel\Tests;

use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Response;
use VPNDetection\Client;
use VPNDetection\Laravel\VPNDetectionMiddleware;
use VPNDetection\Laravel\VPNDetectionServiceProvider;
use VPNDetection\Options;

/**
 * The middleware as an app gets it: from the service provider, configured through
 * `config/vpndetection.php`. Every other test builds the core by hand, so a key the
 * provider dropped on its way to the core would pass them all.
 */
final class ServiceProviderTest extends TestCase
{
    private const PUBLIC_IP = '45.83.91.1';

    private Stub $stub;

    protected function getPackageProviders($app): array
    {
        return [VPNDetectionServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $this->stub = new Stub(Stub::lookups([
            self::PUBLIC_IP => ['status' => 200, 'body' => ['ip' => self::PUBLIC_IP, 'is_vpn' => true]],
        ]));
        $app['config']->set('vpndetection.client', new Client(
            new Options(cache: false, retries: 0, httpClient: $this->stub->client),
        ));
        $app['config']->set('vpndetection.ip_selector', static fn (Request $r): string => self::PUBLIC_IP);
        $app['config']->set('vpndetection.block_condition', ['isVpn' => true]);
    }

    public function testAConfiguredConditionRefusesTheRequest(): void
    {
        $middleware = $this->app->make(VPNDetectionMiddleware::class);
        $reached = false;
        $response = $middleware->handle(Request::create('/'), function () use (&$reached): Response {
            $reached = true;
            return new Response('ok');
        });

        self::assertSame(403, $response->getStatusCode());
        self::assertFalse($reached, 'the route answered a blocked request');
        self::assertCount(1, $this->stub->calls);
    }
}
