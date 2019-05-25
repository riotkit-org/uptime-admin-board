<?php declare(strict_types=1);

use DI\Container;
use Doctrine\Common\Cache\Cache;
use Doctrine\Common\Cache\FilesystemCache;
use Doctrine\Common\Cache\RedisCache;
use Doctrine\Common\Cache\VoidCache;
use Wolnosciowiec\UptimeAdminBoard\Component\Config;
use Wolnosciowiec\UptimeAdminBoard\Provider\CachedProvider;
use Wolnosciowiec\UptimeAdminBoard\Provider\MultipleProvider;
use Wolnosciowiec\UptimeAdminBoard\Provider\ServerUptimeProvider;
use Wolnosciowiec\UptimeAdminBoard\Provider\UptimeRobotProvider;
use Wolnosciowiec\UptimeAdminBoard\Provider\WithMetricsRecordedProvider;
use Wolnosciowiec\UptimeAdminBoard\Provider\WithTorWrapperProvider;
use Wolnosciowiec\UptimeAdminBoard\Repository\HistoryRepository;
use Wolnosciowiec\UptimeAdminBoard\Repository\HistorySQLiteRepository;
use Wolnosciowiec\UptimeAdminBoard\Service\TORProxyHandler;

return [

    //
    // Infrastructure
    //
    
    Cache::class => function (Container $container) {
        $config    = $container->get(Config::class);
        $cacheType = $config->get('cache');

        if (!$cacheType || $cacheType === 'void' || $cacheType === 'none') {
            return new VoidCache();
        }

        if ($cacheType === 'redis') {
            $redis = new Redis();
            $redis->connect($config->get('redis_host'), $config->get('redis_port'));

            $cache = new RedisCache();
            $cache->setRedis($redis);

            return $cache;
        }

        return new FilesystemCache(__DIR__ . '/../../var/cache/');
    },

    Twig\Environment::class => function (Container $container) {
        return new Twig\Environment(
            new Twig\Loader\FilesystemLoader([__DIR__ . '/../../templates/'])
        );
    },


    //
    // Application
    //

    HistorySQLiteRepository::class => function (Config $config) {
        return new HistorySQLiteRepository(
            $config->get('db_path')
        );
    },

    HistoryRepository::class => static function (Container $container) {
        return $container->get(HistorySQLiteRepository::class);
    },

    // STARTS: Provider chain
    ServerUptimeProvider::class => static function (Container $container) {
        return $container->get(CachedProvider::class);
    },

    CachedProvider::class => function (Container $container) {
        $config = $container->get(Config::class);

        return new CachedProvider(
            $container->get(WithMetricsRecordedProvider::class),
            $container->get(Cache::class),
            $config->get('cache_id', hash('sha256', __DIR__)),
            $config->get('cache_ttl', 60)
        );
    },

    WithTorWrapperProvider::class => function (Container $container) {
        return new WithTorWrapperProvider(
            $container->get(MultipleProvider::class),
            $container->get(TORProxyHandler::class)
        );
    },

    WithMetricsRecordedProvider::class => function (Container $container) {
        return new WithMetricsRecordedProvider(
            $container->get(WithTorWrapperProvider::class),
            $container->get(HistoryRepository::class)
        );
    },

    MultipleProvider::class => function (Container $container) {
        return new MultipleProvider([
            $container->get(UptimeRobotProvider::class)
        ]);
    },
    // ENDS: Provider chain

    TORProxyHandler::class => function (Container $container) {
        $config = $container->get(Config::class);

        return new TORProxyHandler(
            $config->get('proxy_address', ''),
            $config->get('tor_management_port', 9052),
            $config->get('tor_password', '')
        );
    },

    Config::class => function () {
        return new Config(require __DIR__ . '/../../config.php');
    }

    // ... the rest of services are not configured due to their simple dependencies
    //     that are resolved automatically by autowiring in the DI container
];
