<?php

declare(strict_types=1);

namespace VPNDetection\Laravel\Tests;

use Illuminate\Http\Request;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpFoundation\Response;
use VPNDetection\Laravel\VPNDetectionMiddleware;
use VPNDetection\Laravel\VPNDetectionServiceProvider;

/**
 * The setup the README gives, end to end: the published config, the key in the
 * variable the README names, and a real HTTP server standing in for the API, asked
 * about a PUBLIC visitor (a private address is answered without asking it). Every
 * other test hands the provider a client it built, so a key lost between `.env`
 * and the request would pass them all.
 */
final class ReadmeSetupTest extends TestCase
{
    private const PUBLIC_IP = '146.70.116.130';
    private const KEY = 'k_readme_setup';

    /** @var resource|null */
    private static $server = null;
    private static int $port = 0;
    private static string $dir = '';

    public static function setUpBeforeClass(): void
    {
        self::$dir = sys_get_temp_dir() . '/vpndetection-laravel-' . bin2hex(random_bytes(6));
        mkdir(self::$dir);
        file_put_contents(self::$dir . '/answer.json', json_encode(['ip' => self::PUBLIC_IP, 'is_vpn' => false]));
        self::$port = random_int(20000, 60000);
        self::$server = proc_open(
            [PHP_BINARY, '-S', '127.0.0.1:' . self::$port, __DIR__ . '/fake-api.php'],
            [0 => ['pipe', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
            null,
            ['FAKE_API_DIR' => self::$dir],
        );
        for ($i = 0; $i < 100; $i++) {
            $socket = @fsockopen('127.0.0.1', self::$port);
            if ($socket !== false) {
                fclose($socket);
                return;
            }
            usleep(50_000);
        }
        self::fail('the stand-in API never listened');
    }

    public static function tearDownAfterClass(): void
    {
        if (self::$server !== null) {
            proc_terminate(self::$server);
            proc_close(self::$server);
        }
    }

    /** The variable the README tells an app to put its key in. */
    private static function readmeVariable(): string
    {
        $readme = (string) file_get_contents(__DIR__ . '/../README.md');
        self::assertSame(
            1,
            preg_match('/Set `([A-Z][A-Z0-9_]*)` in your `\.env`/', $readme, $m),
            'the README no longer names the variable that holds the key',
        );
        return $m[1];
    }

    private static function setVariable(?string $value): void
    {
        $name = self::readmeVariable();
        if ($value === null) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
            return;
        }
        putenv("{$name}={$value}");
        $_ENV[$name] = $value;
        $_SERVER[$name] = $value;
    }

    protected function tearDown(): void
    {
        parent::tearDown();
        self::setVariable(null);
    }

    protected function getPackageProviders($app): array
    {
        return [VPNDetectionServiceProvider::class];
    }

    protected function defineRoutes($router): void
    {
        $router->get('/', static fn (): string => 'ok')->middleware(VPNDetectionMiddleware::class);
    }

    protected function defineEnvironment($app): void
    {
        // Only what a test needs on top of the README: where the API is.
        $app['config']->set('vpndetection.base_url', 'http://127.0.0.1:' . self::$port);
        $app['config']->set('vpndetection.cache_store', false);
    }

    /** @return list<array{path: string, authorization: ?string}> */
    private function visit(): array
    {
        @unlink(self::$dir . '/requests.jsonl');
        $middleware = $this->app->make(VPNDetectionMiddleware::class);
        $reached = false;
        $request = Request::create('/', 'GET', server: ['REMOTE_ADDR' => self::PUBLIC_IP]);
        $response = $middleware->handle($request, function () use (&$reached): Response {
            $reached = true;
            return new Response('ok');
        });
        self::assertTrue($reached, 'the route never answered');
        self::assertSame(200, $response->getStatusCode());
        $log = (string) @file_get_contents(self::$dir . '/requests.jsonl');
        return array_map(
            static fn (string $line): array => json_decode($line, true),
            array_values(array_filter(explode("\n", $log))),
        );
    }

    public function testTheKeyFromTheReadmesVariableReachesTheApi(): void
    {
        self::setVariable(self::KEY);
        $this->refreshApplication();

        self::assertSame(
            [['path' => '/' . self::PUBLIC_IP, 'authorization' => 'Bearer ' . self::KEY]],
            $this->visit(),
        );
    }

    public function testWithoutTheVariableTheLookupIsKeyless(): void
    {
        self::setVariable(null);
        $this->refreshApplication();

        self::assertSame(
            [['path' => '/' . self::PUBLIC_IP, 'authorization' => null]],
            $this->visit(),
        );
    }

    /**
     * Local development behind no proxy: a bogon peer sending a crawler's agent is answered
     * without asking the API, and its answer carries no verdict, which is no plan gap and no 500.
     */
    public function testABogonPeerWithACrawlerAgentIsNoPlanGapAndNeverReachesTheApi(): void
    {
        self::setVariable(self::KEY);
        $log = tempnam(sys_get_temp_dir(), 'vpndetection-warnings');
        $previous = ini_set('error_log', $log);
        try {
            foreach (['127.0.0.1', '10.0.0.1', '::1'] as $peer) {
                foreach (['throw', 'warn'] as $action) {
                    $this->refreshApplication();
                    $this->app['config']->set('vpndetection.allow_bots', true);
                    $this->app['config']->set('vpndetection.block_condition', ['userAgent' => ['verdict' => 'spoofed']]);
                    $this->app['config']->set('vpndetection.on_missing_field', $action);
                    @unlink(self::$dir . '/requests.jsonl');

                    $response = $this->withServerVariables(['REMOTE_ADDR' => $peer])
                        ->get('/', ['User-Agent' => 'Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html)']);

                    $response->assertOk();
                    self::assertFileDoesNotExist(self::$dir . '/requests.jsonl', "{$peer}, {$action}: a bogon reached the API");
                }
            }
            self::assertStringNotContainsString('does not include', (string) file_get_contents($log));
            self::assertStringContainsString('not a public address', (string) file_get_contents($log));
        } finally {
            ini_set('error_log', $previous === false ? '' : $previous);
            @unlink($log);
        }
    }
}
