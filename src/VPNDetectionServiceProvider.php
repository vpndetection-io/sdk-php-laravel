<?php

declare(strict_types=1);

namespace VPNDetection\Laravel;

use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use Psr\SimpleCache\CacheInterface;
use VPNDetection\Middleware\Core;
use VPNDetection\Middleware\Options;

/**
 * Wires the middleware from `config/vpndetection.php`.
 *
 * Auto-discovered, so `composer require vpndetection/laravel` is enough; only the
 * middleware registration is yours to do.
 */
final class VPNDetectionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/vpndetection.php', 'vpndetection');

        $this->app->singleton(VPNDetectionMiddleware::class, function ($app): VPNDetectionMiddleware {
            /** @var array<string, mixed> $config */
            $config = $app['config']->get('vpndetection', []);
            $onBlocked = $config['on_blocked'] ?? [VPNDetectionMiddleware::class, 'refuse'];
            unset($config['on_blocked']);

            return new VPNDetectionMiddleware(
                new Core(self::options($config, self::store($app, $config['cache_store'] ?? null)), Selectors::default()),
                $onBlocked,
            );
        });
    }

    public function boot(): void
    {
        $this->publishes(
            [__DIR__ . '/../config/vpndetection.php' => $this->app->configPath('vpndetection.php')],
            'vpndetection-config'
        );
    }

    /**
     * Config is snake_case, as Laravel config always is; the core's Options are
     * camelCase named arguments. This is the one place the two meet.
     *
     * @param array<string, mixed> $config
     */
    private static function options(array $config, ?CacheInterface $store): Options
    {
        return new Options(
            client: $config['client'] ?? null,
            apiKey: $config['api_key'] ?? null,
            baseUrl: $config['base_url'] ?? null,
            timeout: (float) ($config['timeout'] ?? 2.5),
            retries: (int) ($config['retries'] ?? 0),
            ipSelector: $config['ip_selector'] ?? null,
            blockCondition: $config['block_condition'] ?? null,
            failClosed: (bool) ($config['fail_closed'] ?? false),
            onMissingField: (string) ($config['on_missing_field'] ?? 'warn'),
            skip: $config['skip'] ?? null,
            onWarn: $config['on_warn'] ?? null,
            cacheStore: $store,
        );
    }

    private static function store(Application $app, mixed $name): ?CacheInterface
    {
        if ($name === false || !$app->bound('cache')) {
            return null;
        }
        return $app['cache']->store(is_string($name) ? $name : null);
    }
}
