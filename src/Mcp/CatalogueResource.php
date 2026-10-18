<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Mcp;

use AlfaSix\AlfakitAi\Support\PanelGateway;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Resource;

/**
 * `alfakit://catalogue`: the resources the token's user may list, with their fields.
 */
class CatalogueResource extends Resource
{
    protected string $uri = 'alfakit://catalogue';

    protected string $mimeType = 'application/json';

    protected string $name = 'catalogue';

    protected string $title = 'Panel resources';

    protected string $description = 'The resources of this panel the user can see, with the fields each one shows.';

    public function handle(): Response
    {
        return Response::json(['resources' => PanelGateway::current()?->catalogue() ?? []]);
    }
}
