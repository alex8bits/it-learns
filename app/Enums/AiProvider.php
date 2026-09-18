<?php

declare(strict_types=1);

namespace App\Enums;

enum AiProvider: string
{
    case Openai = 'openai';
    case Anthropic = 'anthropic';
    case Minimax = 'minimax';
    case OpenaiCompatible = 'openai_compatible';
    case Dummy = 'dummy';

    public function label(): string
    {
        return match ($this) {
            self::Openai => 'OpenAI',
            self::Anthropic => 'Anthropic',
            self::Minimax => 'MiniMax',
            self::OpenaiCompatible => 'OpenAI-совместимый',
            self::Dummy => 'Фиктивный (dev/тесты)',
        };
    }
}
