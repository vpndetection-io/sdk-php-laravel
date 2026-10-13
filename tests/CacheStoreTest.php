<?php

declare(strict_types=1);

namespace VPNDetection\Laravel\Tests;

use Illuminate\Http\Request;
use VPNDetection\Middleware\Lookup;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Response;
use VPNDetection\Client;
use VPNDetection\Laravel\VPNDetectionMiddleware;
use VPNDetection\Laravel\VPNDetectionServiceProvider;
use VPNDetection\Options;

/**
 * Under PHP-FPM the application, and the client in it, is built for every request, so
 * only the app's cache store carries an answer from one request to the next. Another
 * client sharing the store writes the answer, and the provider's own client reaches
 * only an origin that refuses it: it can answer from the store alone.
 */
final class CacheStoreTest extends TestCase
{
    private const PUBLIC_IP = '45.83.91.1';

    private const REFUSED = 'http://127.0.0.1:9';

    protected function getPackageProviders($app): array
    {
        return [VPNDetectionServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('vpndetection.api_key', 'k');
        $app['config']->set('vpndetection.base_url', self::REFUSED);
        $app['config']->set('vpndetection.ip_selector', static fn (Request $r): string => self::PUBLIC_IP);
    }

    public function testTheDefaultStoreCarriesAnAnswerBetweenRequests(): void
    {
        $stub = $this->answerOnce();

        $lookup = $this->visit();

        self::assertTrue($lookup?->result?->isVpn);
        self::assertCount(1, $stub->calls);
    }

    public function testCacheStoreFalseKeepsAnswersInTheClient(): void
    {
        $this->answerOnce();
        $this->app['config']->set('vpndetection.cache_store', false);

        $lookup = $this->visit();

        self::assertNull($lookup?->result);
        self::assertNotNull($lookup?->error);
    }

    private function answerOnce(): Stub
    {
        $stub = new Stub(Stub::lookups([
            self::PUBLIC_IP => ['status' => 200, 'body' => ['ip' => self::PUBLIC_IP, 'is_vpn' => true]],
        ]));
        (new Client(new Options(
            apiKey: 'k',
            baseUrl: self::REFUSED,
            cacheStore: $this->app['cache']->store(),
            httpClient: $stub->client,
        )))->lookup(self::PUBLIC_IP);
        return $stub;
    }

    private function visit(): ?Lookup
    {
        $request = Request::create('/');
        $this->app->make(VPNDetectionMiddleware::class)->handle($request, static fn (): Response => new Response('ok'));
        return VPNDetectionMiddleware::lookup($request);
    }
}
