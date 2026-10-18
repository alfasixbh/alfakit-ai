<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Exceptions;

use AlfaSix\AlfakitAi\AiPlugin;

/**
 * No provider key is configured and `AiPlugin::demoResponses()` is off. The message tells
 * users who may see the setup hint how to fix it, and everyone else that AI isn't available.
 */
class AiNotConfiguredException extends AiException
{
    public static function for(?AiPlugin $plugin): self
    {
        return new self($plugin?->isSetupHintVisible() === true
            ? __('alfakit-ai::ai.setup.not_configured').' '.__('alfakit-ai::ai.setup.hint', ['command' => 'php artisan alfakit:ai-setup'])
            : (string) __('alfakit-ai::ai.setup.unavailable'));
    }
}
