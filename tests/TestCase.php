<?php

namespace Darvis\SupplydriveApi\Tests;

use Darvis\SupplydriveApi\SupplydriveApiServiceProvider;
use Illuminate\Support\Facades\Http;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    public const USERNAME = '1476';

    public const PASSWORD = 'test-password/with+chars==';

    public const ACCESS_TOKEN = 'test-access-token';

    protected function setUp(): void
    {
        parent::setUp();

        // A forgotten fake fails the test instead of calling Supplydrive.
        Http::preventStrayRequests();
    }

    protected function getPackageProviders($app): array
    {
        return [
            SupplydriveApiServiceProvider::class,
        ];
    }

    protected function getEnvironmentSetUp($app): void
    {
        // The package defaults have no account, and without one the client cannot log in.
        $app['config']->set('supplydrive.username', self::USERNAME);
        $app['config']->set('supplydrive.password', self::PASSWORD);

        // The token cache encrypts with the application key and uses the default cache store.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('a', 32)));
        $app['config']->set('cache.default', 'array');
    }

    /**
     * The key the client stores its token under. Pinned here on purpose: a change of format makes
     * every host app fetch a new token once, which is harmless, but must not happen by accident.
     */
    public static function tokenCacheKey(
        string $username = self::USERNAME,
        string $tokenUrl = 'https://portal.supplydrive.com/realms/Supplydrive/protocol/openid-connect/token',
        string $clientId = 'SupplydrivePortal',
    ): string {
        return 'supplydrive.token.'.hash('sha256', $tokenUrl.'|'.$clientId.'|'.$username);
    }

    /**
     * Fake the token endpoint and the status endpoint. The token endpoint always answers with the
     * test token; the status endpoint answers with the given messages, or with a callable.
     *
     * @param  array<int, array<string, mixed>>|callable  $messages
     */
    protected function fakeSupplydrive(array|callable $messages = [], int $expiresIn = 300, int $status = 200): void
    {
        Http::fake([
            'portal.supplydrive.com/realms/*' => Http::response([
                'access_token' => self::ACCESS_TOKEN,
                'token_type' => 'Bearer',
                'expires_in' => $expiresIn,
            ]),
            'portal.supplydrive.com/SD/*' => is_callable($messages) ? $messages : Http::response($messages, $status),
        ]);
    }

    /**
     * One message as the status endpoint returns it.
     *
     * @return array<string, mixed>
     */
    public static function message(string $status = 'Afgeleverd', string $functionalId = 'F260001', int $id = 1): array
    {
        return [
            'id' => $id,
            'functionalId' => $functionalId,
            'status' => $status,
            'interfaceId' => 1000,
        ];
    }
}
