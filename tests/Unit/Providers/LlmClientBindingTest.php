<?php

declare(strict_types=1);

namespace Tests\Unit\Providers;

use App\Providers\AppServiceProvider;
use App\Services\Ai\DummyLlmClient;
use App\Services\Ai\LlmClient;
use RuntimeException;
use Tests\TestCase;

class LlmClientBindingTest extends TestCase
{
    public function test_binds_dummy_client_from_config(): void
    {
        config(['ai.provider' => 'dummy']);

        $client = app(LlmClient::class);

        $this->assertInstanceOf(DummyLlmClient::class, $client);
        $this->assertSame($client, app(LlmClient::class));
    }

    public function test_unknown_provider_fails_loud_on_rebind(): void
    {
        config(['ai.provider' => 'bogus']);
        $this->app->forgetInstance(LlmClient::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AI provider [bogus] is not whitelisted in config/ai.php');

        (new AppServiceProvider($this->app))->register();
    }
}
