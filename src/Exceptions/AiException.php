<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Exceptions;

use RuntimeException;

/**
 * An AI request that could not be made or failed. The message is safe to show to users
 * (provider errors are logged, not shown).
 */
class AiException extends RuntimeException
{
    public static function unavailable(): self
    {
        return new self((string) __('alfakit-ai::ai.errors.unavailable'));
    }

    public static function failed(): self
    {
        return new self((string) __('alfakit-ai::ai.errors.failed'));
    }

    public static function invalidAnswer(): self
    {
        return new self((string) __('alfakit-ai::ai.errors.invalid_answer'));
    }
}
