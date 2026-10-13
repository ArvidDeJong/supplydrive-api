<?php

use Darvis\SupplydriveApi\MessageStatus;
use Darvis\SupplydriveApi\Support\SupplydriveConfig;

/**
 * SupplydriveConfig is the one place that reads the package config. These tests guard the two
 * things that go wrong once a default is written down twice: an accessor that disagrees with the
 * config file, and a caller that reaches past the accessor and keeps its own stale fallback.
 */
function packageRoot(string $path = ''): string
{
    return dirname(__DIR__, 2).($path === '' ? '' : '/'.$path);
}

test('the accessors return the values the config file ships', function () {
    $config = require packageRoot('config/supplydrive.php');

    config(['supplydrive' => $config]);

    expect(SupplydriveConfig::baseUrl())->toBe($config['base_url'])
        ->and(SupplydriveConfig::tokenUrl())->toBe($config['token_url'])
        ->and(SupplydriveConfig::clientId())->toBe($config['client_id'])
        ->and(SupplydriveConfig::username())->toBe('')
        ->and(SupplydriveConfig::password())->toBe('');
});

test('the accessors hold the defaults when the config is missing', function () {
    config(['supplydrive' => []]);

    expect(SupplydriveConfig::baseUrl())->toBe('https://portal.supplydrive.com')
        ->and(SupplydriveConfig::tokenUrl())->toBe('https://portal.supplydrive.com/realms/Supplydrive/protocol/openid-connect/token')
        ->and(SupplydriveConfig::clientId())->toBe('SupplydrivePortal');
});

test('the base URL loses its trailing slash', function () {
    config(['supplydrive.base_url' => 'https://example.test/portal/']);

    expect(SupplydriveConfig::baseUrl())->toBe('https://example.test/portal');
});

test('an empty client id is the default client id', function () {
    config(['supplydrive.client_id' => '']);
    expect(SupplydriveConfig::clientId())->toBe('SupplydrivePortal');

    config(['supplydrive.client_id' => 'OtherPortal']);
    expect(SupplydriveConfig::clientId())->toBe('OtherPortal');
});

test('the config file keeps every key of 1.0', function () {
    $config = require packageRoot('config/supplydrive.php');

    expect(array_keys($config))
        ->toBe(['base_url', 'client_id', 'connect_timeout', 'password', 'timeout', 'token_cache', 'token_url', 'username'])
        ->and(array_keys($config['token_cache']))->toBe(['enabled', 'store']);
});

test('the timeouts are thirty and ten seconds, and anything that is not a positive number is the default', function () {
    $config = require packageRoot('config/supplydrive.php');

    expect($config['timeout'])->toBe(30)
        ->and($config['connect_timeout'])->toBe(10)
        ->and(SupplydriveConfig::timeout())->toBe(30.0)
        ->and(SupplydriveConfig::connectTimeout())->toBe(10.0);

    config(['supplydrive.timeout' => '45', 'supplydrive.connect_timeout' => 2.5]);
    expect(SupplydriveConfig::timeout())->toBe(45.0)
        ->and(SupplydriveConfig::connectTimeout())->toBe(2.5);

    foreach ([0, -1, 'abc', '', null, true, []] as $value) {
        config(['supplydrive.timeout' => $value, 'supplydrive.connect_timeout' => $value]);

        expect(SupplydriveConfig::timeout())->toBe(30.0)
            ->and(SupplydriveConfig::connectTimeout())->toBe(10.0);
    }
});

test('the token cache is on by default, and reads a yes or a no from the environment', function () {
    $config = require packageRoot('config/supplydrive.php');

    expect($config['token_cache'])->toBe(['enabled' => true, 'store' => null])
        ->and(SupplydriveConfig::tokenCacheEnabled())->toBeTrue()
        ->and(SupplydriveConfig::tokenCacheStore())->toBeNull();

    foreach ([false, 'false', '0', 0, 'off', 'no'] as $value) {
        config(['supplydrive.token_cache.enabled' => $value]);
        expect(SupplydriveConfig::tokenCacheEnabled())->toBeFalse();
    }

    foreach ([true, 'true', '1', 1, 'on', null, '', 'maybe', []] as $value) {
        config(['supplydrive.token_cache.enabled' => $value]);
        expect(SupplydriveConfig::tokenCacheEnabled())->toBeTrue();
    }

    config(['supplydrive' => []]);
    expect(SupplydriveConfig::tokenCacheEnabled())->toBeTrue();
});

test('an empty cache store name is the default store', function () {
    config(['supplydrive.token_cache.store' => '']);
    expect(SupplydriveConfig::tokenCacheStore())->toBeNull();

    config(['supplydrive.token_cache.store' => 'redis']);
    expect(SupplydriveConfig::tokenCacheStore())->toBe('redis');
});

test('nothing outside the accessor reads the package config', function () {
    $offenders = [];

    foreach (['src', 'resources', 'routes'] as $directory) {
        $path = packageRoot($directory);

        if (! is_dir($path)) {
            continue;
        }

        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path)) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $relative = str_replace(packageRoot().'/', '', $file->getPathname());

            // The accessor is where the reading happens, and the Boost guideline quotes the call
            // it tells you not to write.
            if (str_ends_with($relative, 'SupplydriveConfig.php') || str_starts_with($relative, 'resources/boost/')) {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (preg_match("/(config\\(|Config::get\\()['\"]supplydrive\\./", $contents)) {
                $offenders[] = $relative;
            }
        }
    }

    expect($offenders)->toBe([], 'these read the config directly instead of through SupplydriveConfig');
});

test('the config keys are in alphabetical order, at every level', function () {
    $walk = function (array $config, string $trail) use (&$walk): void {
        $keys = array_keys($config);

        if ($keys !== array_filter($keys, 'is_string')) {
            return;
        }

        $sorted = $keys;
        sort($sorted);

        expect($keys)->toBe($sorted, "the keys in '{$trail}' are not in alphabetical order");

        foreach ($config as $key => $value) {
            if (is_array($value) && $value !== []) {
                $walk($value, $trail.'.'.$key);
            }
        }
    };

    $walk(require packageRoot('config/supplydrive.php'), 'supplydrive');
});

test('every status has a line in every language file, in alphabetical order', function () {
    $en = require packageRoot('resources/lang/en/status.php');
    $nl = require packageRoot('resources/lang/nl/status.php');

    $expected = array_map(fn (MessageStatus $status) => $status->key(), MessageStatus::cases());
    $expected[] = 'unknown';
    sort($expected);

    expect(array_keys($en))->toBe($expected)
        ->and(array_keys($nl))->toBe($expected);
});
