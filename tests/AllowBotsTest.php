<?php

declare(strict_types=1);

namespace VPNDetection\Laravel\Tests;

use Orchestra\Testbench\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\SimpleCache\CacheInterface;
use VPNDetection\Client;
use VPNDetection\Laravel\VPNDetectionMiddleware;
use VPNDetection\Laravel\VPNDetectionServiceProvider;
use VPNDetection\Options;

/**
 * `allow_bots` as an app gets it: set in `config/vpndetection.php`, read by the
 * service provider, and run through the app's own HTTP kernel on a route, from a
 * public peer read by the default selector.
 */
final class AllowBotsTest extends TestCase
{
    private const PEER = '66.249.66.1';

    private const GOOGLEBOT = 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)';

    private Stub $stub;

    protected function getPackageProviders($app): array
    {
        return [VPNDetectionServiceProvider::class];
    }

    protected function defineRoutes($router): void
    {
        $router->get('/', static fn (): string => 'ok')->middleware(VPNDetectionMiddleware::class);
    }

    /**
     * @param array<string, mixed> $config
     * @param ?CacheInterface $pool The store the client keeps its answers in, where the app's own cache is one
     */
    private function serve(?string $verdict, array $config, ?CacheInterface $pool = null): void
    {
        $body = ['ip' => self::PEER, 'is_vpn' => false, 'is_hosting' => true];
        if ($verdict !== null) {
            $body['user_agent'] = ['agent' => 'Googlebot', 'kind' => 'bot', 'verdict' => $verdict];
        }
        $this->stub = new Stub(Stub::lookups([self::PEER => ['status' => 200, 'body' => $body]]));
        $this->app['config']->set('vpndetection.client', new Client($pool === null
            ? new Options(cache: false, retries: 0, httpClient: $this->stub->client)
            : new Options(retries: 0, cacheStore: $pool, httpClient: $this->stub->client)));
        foreach ($config as $key => $value) {
            $this->app['config']->set("vpndetection.{$key}", $value);
        }
    }

    private function visit(string $agent = self::GOOGLEBOT): int
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::PEER])
            ->get('/', ['User-Agent' => $agent])
            ->getStatusCode();
    }

    /** @return array<string, mixed> */
    private function sent(): array
    {
        self::assertSame(['/' . self::PEER], $this->stub->calls, 'the public peer, looked up once');
        parse_str($this->stub->requests[0]->getUri()->getQuery(), $query);
        return $query;
    }

    public function testTheRequestsOwnUserAgentReachesTheOrigin(): void
    {
        $this->serve('verified', ['allow_bots' => true]);

        self::assertSame(200, $this->visit());
        self::assertSame(['user_agent' => self::GOOGLEBOT], $this->sent());
    }

    /**
     * Under PHP-FPM each request builds the application and the middleware in it, so the four
     * agents an address may be looked up with have to be remembered in the client's store.
     */
    public function testTheBoundOnAnAddressesAgentsHoldsAcrossRequestsInTheClientsStore(): void
    {
        $pool = $this->app['cache']->store();
        $agent = static fn (int $n): string => "Mozilla/5.0 (compatible; Examplebot/{$n}.0)";
        $statuses = [];
        for ($n = 1; $n <= 5; $n++) {
            $this->serve('verified', ['allow_bots' => true, 'block_condition' => ['isHosting' => true]], $pool);
            $this->app->forgetInstance(VPNDetectionMiddleware::class);
            $statuses[] = $this->visit($agent($n));
            $sent = array_map(static function ($request): array {
                parse_str($request->getUri()->getQuery(), $query);
                return $query;
            }, $this->stub->requests);
            self::assertSame($n <= 4 ? [['user_agent' => $agent($n)]] : [[]], $sent, "agent {$n}");
        }
        self::assertSame([200, 200, 200, 200, 403], $statuses, 'the fifth agent is past the bound, and none is sent');
    }

    /** @return array<string, array{0: string, 1: int}> */
    public static function verdicts(): array
    {
        return ['verified' => ['verified', 200], 'spoofed' => ['spoofed', 403], 'not_bot' => ['not_bot', 403]];
    }

    #[DataProvider('verdicts')]
    public function testAVerifiedCrawlerPassesAConditionItMeetsAndOthersAreRefused(string $verdict, int $status): void
    {
        $this->serve($verdict, ['allow_bots' => true, 'block_condition' => ['isHosting' => true]]);

        self::assertSame($status, $this->visit());
        self::assertSame(['user_agent' => self::GOOGLEBOT], $this->sent());
    }

    public function testOffByDefaultNoUserAgentIsSentAndTheConditionHolds(): void
    {
        $this->serve('verified', ['block_condition' => ['isHosting' => true]]);

        self::assertSame(403, $this->visit());
        self::assertSame([], $this->sent());
    }

    public function testAnEmptyUserAgentSendsNone(): void
    {
        $this->serve(null, ['allow_bots' => true]);

        self::assertSame(200, $this->visit(''));
        self::assertSame([], $this->sent());
    }

    public function testAConditionOnTheVerdictRefusesAForgedCrawler(): void
    {
        $this->serve('spoofed', ['allow_bots' => true, 'block_condition' => ['userAgent' => ['verdict' => 'spoofed']]]);

        self::assertSame(403, $this->visit());
        self::assertSame(['user_agent' => self::GOOGLEBOT], $this->sent());
    }

    public function testAnAnswerWithoutAVerdictWarnsOnce(): void
    {
        $warnings = [];
        $this->serve(null, [
            'allow_bots' => true,
            'on_warn' => function (string $m) use (&$warnings): void {
                $warnings[] = $m;
            },
        ]);

        self::assertSame(200, $this->visit());
        self::assertSame(200, $this->visit());
        self::assertCount(1, $warnings);
        self::assertStringContainsString('user_agent', $warnings[0]);
    }
}
