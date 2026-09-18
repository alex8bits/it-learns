<?php

declare(strict_types=1);

namespace App\Services\Ai;

use App\Enums\AiProvider;
use RuntimeException;

/**
 * Fail-loud validation of the config/ai.php payload, called on boot
 * from AppServiceProvider: provider whitelist membership, required
 * credentials for non-dummy providers, and SSRF protection for the
 * openai_compatible base_url. Pure class — no DB/HTTP, unit-testable
 * in isolation.
 */
final class AiConfigValidator
{
    /**
     * @param  array<string, mixed>  $config  payload of config('ai')
     *
     * @throws RuntimeException when the configuration is invalid
     */
    public function validate(array $config): void
    {
        $provider = $config['provider'] ?? null;

        $clients = $config['clients'] ?? [];
        $clients = is_array($clients) ? $clients : [];

        if (! is_string($provider) || ! array_key_exists($provider, $clients)) {
            $providerName = is_string($provider) ? $provider : gettype($provider);

            throw new RuntimeException("AI provider [{$providerName}] is not whitelisted in config/ai.php");
        }

        if ($provider === AiProvider::Dummy->value) {
            return;
        }

        foreach (['api_key' => 'AI_API_KEY', 'model' => 'AI_MODEL'] as $key => $envName) {
            $value = $config[$key] ?? null;

            if (! is_string($value) || trim($value) === '') {
                throw new RuntimeException(
                    "AI provider [{$provider}] requires a non-empty [{$key}] ({$envName} in .env)",
                );
            }
        }

        if ($provider === AiProvider::OpenaiCompatible->value) {
            $this->validateBaseUrl($config['base_url'] ?? null);
        }
    }

    /**
     * SSRF guard for AI_BASE_URL (platform plan, Stage 4): the URL must
     * be well-formed and point to a public host — loopback names,
     * private and reserved IP ranges (10/8, 172.16/12, 192.168/16,
     * 127/8, ::1, IPv4-mapped IPv6, legacy IPv4 forms like "127.1",
     * "0177.0.0.1", "2130706433", "0x7f.1", per-component hex forms
     * like "0x7f.0x0.0x0.0x1" and "127.0x0.0.1", ...) are rejected.
     *
     * @param  mixed  $baseUrl  config('ai.base_url')
     *
     * @throws RuntimeException when the URL is missing, malformed or internal
     */
    private function validateBaseUrl(mixed $baseUrl): void
    {
        if (! is_string($baseUrl) || trim($baseUrl) === '') {
            throw new RuntimeException(
                'AI provider [openai_compatible] requires a non-empty [base_url] (AI_BASE_URL in .env)',
            );
        }

        $trimmed = trim($baseUrl);

        if (filter_var($trimmed, FILTER_VALIDATE_URL) === false) {
            throw new RuntimeException("AI base_url [{$trimmed}] is not a valid URL");
        }

        $host = parse_url($trimmed, PHP_URL_HOST);

        if (! is_string($host) || $host === '') {
            throw new RuntimeException("AI base_url [{$trimmed}] has no host");
        }

        // parse_url keeps the brackets of IPv6 literals ("[::1]").
        $host = trim($host, '[]');

        $isLoopbackName = strcasecmp($host, 'localhost') === 0 || $host === '::1';

        $isInternalIp = filter_var($host, FILTER_VALIDATE_IP) !== false
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;

        if ($this->isInternalIpv4MappedIpv6($host)) {
            $isInternalIp = true;
        }

        // Legacy IPv4 spellings ("127.1", "0177.0.0.1", "2130706433",
        // "0x7f.1") are not valid IPs per PHP filters, so they would slip
        // through as "hostnames", yet getaddrinfo (glibc) resolves them to
        // the corresponding IPv4 — require a strict dotted quad instead.
        $isAmbiguousIpLiteral = (preg_match('/^[0-9.]+$/', $host) === 1
                || preg_match('/^0[xX][0-9a-fA-F.]*$/', $host) === 1)
            && filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false;

        // Per-component hex spellings ("0x7f.0x0.0x0.0x1", "127.0x0.0.1"):
        // glibc inet_aton resolves each component independently, so a hex
        // prefix in any single component makes the host ambiguous even
        // when the rest looks like a plain hostname — reject outright.
        $hasHexPrefixedComponent = preg_match('/(^|\.)0[xX]/', $host) === 1;

        if ($isLoopbackName || $isInternalIp || $isAmbiguousIpLiteral || $hasHexPrefixedComponent) {
            throw new RuntimeException(
                "AI base_url [{$trimmed}] points to an internal address, blocked by SSRF protection",
            );
        }
    }

    /**
     * Detects IPv4-mapped IPv6 hosts ("::ffff:127.0.0.1"): PHP's IP
     * filters do not flag them as private, but curl/glibc resolve them
     * to the embedded IPv4, so the embedded address is checked instead.
     */
    private function isInternalIpv4MappedIpv6(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) === false) {
            return false;
        }

        $binary = inet_pton($host);

        if ($binary === false || strlen($binary) !== 16
            || substr($binary, 0, 10) !== str_repeat("\x00", 10)
            || substr($binary, 10, 2) !== "\xff\xff") {
            return false;
        }

        $embeddedIpv4 = inet_ntop(substr($binary, 12));

        return $embeddedIpv4 !== false
            && filter_var($embeddedIpv4, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false;
    }
}
