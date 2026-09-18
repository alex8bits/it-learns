<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Ai;

use App\Enums\AiProvider;
use App\Services\Ai\AiConfigValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class AiConfigValidatorTest extends TestCase
{
    private AiConfigValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->validator = new AiConfigValidator;
    }

    public function test_dummy_provider_passes_without_any_credentials(): void
    {
        $this->validator->validate($this->config(AiProvider::Dummy->value, [
            'api_key' => null,
            'model' => null,
            'base_url' => null,
        ]));

        $this->expectNotToPerformAssertions();
    }

    public function test_provider_outside_whitelist_is_rejected_with_exact_message(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('AI provider [bogus] is not whitelisted in config/ai.php');

        $this->validator->validate($this->config('bogus'));
    }

    public function test_missing_provider_key_is_rejected(): void
    {
        $config = $this->config(AiProvider::Dummy->value);
        unset($config['provider']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('is not whitelisted in config/ai.php');

        $this->validator->validate($config);
    }

    #[DataProvider('nonDummyProviders')]
    public function test_non_dummy_provider_requires_a_non_empty_api_key(string $provider): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("AI provider [{$provider}] requires a non-empty [api_key]");

        $this->validator->validate($this->config($provider, ['api_key' => null]));
    }

    #[DataProvider('nonDummyProviders')]
    public function test_non_dummy_provider_requires_a_non_empty_model(string $provider): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage("AI provider [{$provider}] requires a non-empty [model]");

        $this->validator->validate($this->config($provider, ['model' => '']));
    }

    #[DataProvider('nonDummyProviders')]
    public function test_non_dummy_provider_with_credentials_passes(string $provider): void
    {
        $config = $this->config($provider, ['base_url' => 'https://api.example.com/v1']);

        $this->validator->validate($config);

        $this->expectNotToPerformAssertions();
    }

    public function test_blank_api_key_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('requires a non-empty [api_key]');

        $this->validator->validate($this->config(AiProvider::Openai->value, ['api_key' => '   ']));
    }

    #[DataProvider('invalidBaseUrls')]
    public function test_openai_compatible_rejects_missing_or_malformed_base_url(?string $baseUrl): void
    {
        $this->expectException(RuntimeException::class);

        $this->validator->validate($this->config(AiProvider::OpenaiCompatible->value, [
            'base_url' => $baseUrl,
        ]));
    }

    #[DataProvider('invalidInternalBaseUrls')]
    public function test_openai_compatible_rejects_internal_addresses_with_ssrf_message(string $baseUrl): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('blocked by SSRF protection');

        $this->validator->validate($this->config(AiProvider::OpenaiCompatible->value, [
            'base_url' => $baseUrl,
        ]));
    }

    #[DataProvider('publicBaseUrls')]
    public function test_openai_compatible_accepts_public_base_urls(string $baseUrl): void
    {
        $this->validator->validate($this->config(AiProvider::OpenaiCompatible->value, [
            'base_url' => $baseUrl,
        ]));

        $this->expectNotToPerformAssertions();
    }

    /**
     * @return array<int, array{string}>
     */
    public static function nonDummyProviders(): array
    {
        return [
            [AiProvider::Openai->value],
            [AiProvider::Anthropic->value],
            [AiProvider::Minimax->value],
            [AiProvider::OpenaiCompatible->value],
        ];
    }

    /**
     * @return array<string, array{?string}>
     */
    public static function invalidBaseUrls(): array
    {
        return [
            'missing' => [null],
            'empty string' => [''],
            'whitespace only' => ['   '],
            'not a url' => ['not-a-url'],
            'no scheme' => ['example.com/v1'],
            'valid url without host' => ['mailto:user@example.com'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function invalidInternalBaseUrls(): array
    {
        return [
            'localhost hostname' => ['http://localhost:8080/v1'],
            'loopback ipv4' => ['http://127.0.0.1:8080/v1'],
            'loopback ipv6' => ['http://[::1]/v1'],
            'private 10/8' => ['http://10.0.5.3/v1'],
            'private 172.16/12 start' => ['http://172.16.0.1/v1'],
            'private 172.16/12 end' => ['http://172.31.255.255/v1'],
            'private 192.168/16' => ['http://192.168.1.5/v1'],
            'ipv4-mapped loopback' => ['http://[::ffff:127.0.0.1]/v1'],
            'ipv4-mapped private 10/8' => ['http://[::ffff:10.0.0.1]/v1'],
            'ipv4-mapped private 172.16/12' => ['http://[::ffff:172.16.0.1]/v1'],
            'ipv4-mapped private 192.168/16' => ['http://[::ffff:192.168.1.1]/v1'],
            'short loopback form' => ['http://127.1/v1'],
            'octal loopback' => ['http://0177.0.0.1/v1'],
            'decimal loopback' => ['http://2130706433/v1'],
            'hex loopback' => ['http://0x7f.1/v1'],
            'per-component hex loopback' => ['http://0x7f.0x0.0x0.0x1/v1'],
            'mixed decimal-hex loopback' => ['http://127.0x0.0.1/v1'],
        ];
    }

    /**
     * @return array<string, array{string}>
     */
    public static function publicBaseUrls(): array
    {
        return [
            'dns hostname' => ['https://api.example.com/v1'],
            'public ipv4' => ['https://8.8.8.8/v1'],
            'public ipv6' => ['http://[2001:4860::8888]/v1'],
            'public ipv4-mapped ipv6' => ['http://[::ffff:8.8.8.8]/v1'],
            'just outside 172.16/12' => ['http://172.32.0.1/v1'],
            'just outside 192.168/16' => ['http://192.169.1.1/v1'],
        ];
    }

    /**
     * Synthetic config('ai') payload with the full provider whitelist
     * and valid credentials, so each test overrides only what it checks.
     *
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function config(string $provider, array $overrides = []): array
    {
        $clients = [];
        foreach (AiProvider::cases() as $case) {
            $clients[$case->value] = 'App\\Services\\Ai\\'.$case->name.'LlmClient';
        }

        return array_merge([
            'provider' => $provider,
            'clients' => $clients,
            'api_key' => 'sk-test',
            'model' => 'test-model',
            'base_url' => null,
            'token_limit_global_per_day' => 100000,
            'token_limit_per_user_per_day' => 5000,
            'log_full_prompts' => false,
        ], $overrides);
    }
}
