<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Commands;

use AlfaSix\AlfakitAi\AiPlugin;
use AlfaSix\AlfakitAi\Mcp\McpTokens;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;
use Symfony\Component\Console\Attribute\AsCommand;

/**
 * Issue an MCP token from the plugin's own token table. These work next to API and Sanctum
 * tokens unless `AiPlugin::mcpTokenSources()` leaves `'plugin'` out. Printed once.
 */
#[AsCommand(name: 'alfakit-ai:mcp-token', description: 'Issue an MCP token for a user of a panel')]
class McpTokenCommand extends Command
{
    protected $signature = 'alfakit-ai:mcp-token
        {user : The user\'s id or email}
        {--panel= : The panel id (default panel when omitted)}
        {--tenant= : The tenant\'s key (tenancy panels)}
        {--name=MCP : A name to recognise the token}
        {--days= : Expire after this many days}';

    public function handle(): int
    {
        $panel = alfakit()->getPanel(is_string($this->option('panel')) ? $this->option('panel') : null);

        if (AiPlugin::get($panel) === null) {
            $this->components->error("The AI plugin is not registered on the [{$panel->getId()}] panel.");

            return self::FAILURE;
        }

        if (! in_array(McpTokens::PLUGIN, McpTokens::sources($panel), true)) {
            $this->components->warn('This panel authenticates MCP clients with '.(McpTokens::source($panel) === McpTokens::API ? 'alfasix/alfakit-api tokens (its API tokens page)' : 'Laravel Sanctum personal access tokens ($user->createToken(...))').' only (`mcpTokenSources()`); issue one there.');

            return self::FAILURE;
        }

        $provider = $panel->auth()->getProvider();
        $identifier = (string) $this->argument('user');
        $user = str_contains($identifier, '@') ? $provider->retrieveByCredentials(['email' => $identifier]) : $provider->retrieveById($identifier);

        if (! $user instanceof Model) {
            $this->components->error('User not found.');

            return self::FAILURE;
        }

        $tenant = null;

        if ($panel->hasTenancy()) {
            $key = $this->option('tenant');
            $tenant = is_string($key) ? $panel->findTenantFor($key, $user) : null;

            if ($tenant === null) {
                $this->components->error('Pass --tenant with a tenant the user belongs to.');

                return self::FAILURE;
            }
        }

        $days = $this->option('days');
        $token = McpTokens::issue($panel, $user, (string) $this->option('name'), $tenant, is_numeric($days) ? now()->addDays((int) $days) : null);

        $this->components->info('MCP token created. Copy it now; it is not shown again:');
        $this->line($token);

        return self::SUCCESS;
    }
}
