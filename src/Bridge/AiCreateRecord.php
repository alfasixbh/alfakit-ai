<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Bridge;

use AlfaSix\Alfakit\Resources\Pages\CreateRecord;
use AlfaSix\Alfakit\Resources\Resource;

/**
 * Stands in for the CreateRecord page of resources that have none (e.g. modal-only
 * resources), so the copilot and MCP server run the same table, form, hooks and tenancy
 * logic. Never routed.
 *
 * @internal
 */
final class AiCreateRecord extends CreateRecord
{
    protected static bool $isDiscovered = false;

    /** Its own storage (not the parent's), set before every use. */
    protected static string $resource = Resource::class;

    /**
     * @param  class-string<resource>  $resource
     */
    public static function usingResource(string $resource): void
    {
        self::$resource = $resource;
    }
}
