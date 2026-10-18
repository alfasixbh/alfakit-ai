<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Tests\Fixtures\Models;

use AlfaSix\Alfakit\Tests\Fixtures\Models\TenantUser;
use Laravel\Sanctum\HasApiTokens;

class AiUser extends TenantUser
{
    // MCP clients may authenticate with Sanctum personal access tokens.
    use HasApiTokens;

    protected $table = 'users';
}
