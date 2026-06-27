<?php

namespace Hansajith18\LaravelPaycorp\Tests;

use Hansajith18\LaravelPaycorp\PaycorpServiceProvider;
use Orchestra\Testbench\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function getPackageProviders($app): array
    {
        return [PaycorpServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('paycorp.endpoint', 'https://test.paycorp.example/rest/service/proxy');
        $app['config']->set('paycorp.auth_token', 'test-auth-token-000');
        $app['config']->set('paycorp.hmac_secret', 'test-hmac-secret-000');
        $app['config']->set('paycorp.client_id_lkr', '10000001');
        $app['config']->set('paycorp.client_id_usd', '10000002');
        $app['config']->set('paycorp.default_currency', 'LKR');
        $app['config']->set('paycorp.return_url', 'https://yourapp.test/api/payments/sampath/return');
    }
}
