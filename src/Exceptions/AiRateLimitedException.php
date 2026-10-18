<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Exceptions;

/**
 * The user sent more AI requests than `AiPlugin::rateLimit()` allows.
 */
class AiRateLimitedException extends AiException
{
    public function __construct(public readonly int $retryAfter)
    {
        parent::__construct((string) __('alfakit-ai::ai.errors.rate_limited', ['seconds' => max(1, $retryAfter)]));
    }
}
