<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Support;

use AlfaSix\Alfakit\Schemas\Components\Callout;
use AlfaSix\AlfakitAi\AiPlugin;
use AlfaSix\AlfakitAi\Enums\AiFeature;
use Illuminate\Support\HtmlString;

/**
 * The notice shown where AI appears when no provider key is configured: "Sample answer —
 * AI isn't configured" with `demoResponses()`, otherwise "AI isn't set up yet" (with the
 * setup command, for users who may see it) or "AI is not available right now".
 */
final class SetupNotice
{
    public const COMMAND = 'php artisan alfakit:ai-setup';

    /**
     * @param  string|array<int|string, string>|null  $provider  an action's own provider
     * @return array{status: string, heading: string, description: ?string, hint: bool}|null
     */
    public static function for(AiFeature $feature, string|array|null $provider = null): ?array
    {
        return AiPlugin::get()?->getSetupNotice($feature, $provider);
    }

    /**
     * A callout for a modal or page, or null when the feature (or `$provider`) is configured.
     *
     * @param  string|array<int|string, string>|null  $provider
     */
    public static function callout(AiFeature $feature, string|array|null $provider = null, string $key = 'ai_setup_notice'): ?Callout
    {
        $notice = self::for($feature, $provider);

        if ($notice === null) {
            return null;
        }

        $description = $notice['description'];

        return Callout::make($notice['heading'])
            ->key($key)
            ->status($notice['status'])
            ->description($description === null ? null : new HtmlString(str_replace(e(self::COMMAND), '<code>'.e(self::COMMAND).'</code>', e($description))))
            ->extraAttributes(['data-ai-notice' => $notice['status'] === 'info' ? 'demo' : 'setup']);
    }
}
