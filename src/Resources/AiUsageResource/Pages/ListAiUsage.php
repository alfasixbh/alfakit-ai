<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Resources\AiUsageResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\ListRecords;
use AlfaSix\Alfakit\Schemas\Schema;
use AlfaSix\AlfakitAi\AiPlugin;
use AlfaSix\AlfakitAi\Enums\AiFeature;
use AlfaSix\AlfakitAi\Resources\AiUsageResource;
use AlfaSix\AlfakitAi\Resources\AiUsageResource\Widgets\AiUsageStats;
use AlfaSix\AlfakitAi\Support\SetupNotice;

class ListAiUsage extends ListRecords
{
    protected static string $resource = AiUsageResource::class;

    /**
     * The resource the plugin registered (a subclass given to `usageResource()`).
     */
    public static function getResource(): string
    {
        return AiPlugin::get()?->getUsageResource() ?? AiUsageResource::class;
    }

    /**
     * Read only: no "create" button.
     */
    protected function getHeaderActions(): array
    {
        return [];
    }

    protected function getHeaderWidgets(): array
    {
        return [AiUsageStats::class];
    }

    /**
     * Without a provider key: the setup notice above the table (calls answered with
     * `demoResponses()` are listed with the provider `demo`).
     */
    public function content(Schema $schema): Schema
    {
        return $schema->components(array_values(array_filter([SetupNotice::callout(AiFeature::Actions)])));
    }
}
