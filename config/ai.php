<?php

declare(strict_types=1);

use App\Services\Ai\AnthropicLlmClient;
use App\Services\Ai\DummyLlmClient;
use App\Services\Ai\MiniMaxLlmClient;
use App\Services\Ai\OpenAiCompatibleLlmClient;
use App\Services\Ai\OpenAiLlmClient;

return [

    /*
    |--------------------------------------------------------------------------
    | AI Provider
    |--------------------------------------------------------------------------
    |
    | The single active LLM provider (single-tenant), selected via
    | AI_PROVIDER (.env). Must be a key of the `clients` whitelist
    | below and a value of the AiProvider enum, otherwise the
    | application fails loudly on boot (AppServiceProvider).
    |
    */

    'provider' => env('AI_PROVIDER', 'dummy'),

    /*
    |--------------------------------------------------------------------------
    | LLM Client Whitelist
    |
    | Whitelist of LlmClient implementations (the same pattern as the
    | payment gateways in config/payments.php). Adding a provider = a
    | new implementation class + one line here, nothing else changes.
    | The dummy client is dev/tests only; the production clients talk
    | HTTP via Laravel's Http client and are validated on boot by
    | AiConfigValidator.
    |
    */

    'clients' => [
        'dummy' => DummyLlmClient::class,
        'openai' => OpenAiLlmClient::class,
        'anthropic' => AnthropicLlmClient::class,
        'minimax' => MiniMaxLlmClient::class,
        'openai_compatible' => OpenAiCompatibleLlmClient::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Provider Credentials
    |--------------------------------------------------------------------------
    |
    | A single API key and model for the active provider — kept in
    | .env, never in the database (single-tenant). Required for every
    | provider except `dummy`; validated on boot by AiConfigValidator.
    | `base_url` is used by `openai_compatible` only and must be a
    | public URL (internal addresses are blocked — SSRF guard).
    |
    */

    'api_key' => env('AI_API_KEY'),
    'model' => env('AI_MODEL'),
    'base_url' => env('AI_BASE_URL'),

    /*
    |--------------------------------------------------------------------------
    | Daily Token Limits (rule #16 of AGENTS.md)
    |
    | Platform-wide and per-user daily token budgets. When a budget is
    | exhausted the AI layer fails loudly (HTTP 429, no retries). A
    | per-user budget can be raised manually by an admin via
    | user_llm_limits.extra_tokens.
    |
    */

    'token_limit_global_per_day' => (int) env('AI_TOKEN_LIMIT_GLOBAL_PER_DAY', 100000),
    'token_limit_per_user_per_day' => (int) env('AI_TOKEN_LIMIT_PER_USER_PER_DAY', 5000),

    /*
    |--------------------------------------------------------------------------
    | Prompt Logging
    |
    | Whether ai.llm_call log records include the full prompt and
    | response texts. Dev-only: never enable in production (leak
    | risk + storage bloat).
    |
    */

    'log_full_prompts' => (bool) env('AI_LOG_FULL_PROMPTS', false),

];
