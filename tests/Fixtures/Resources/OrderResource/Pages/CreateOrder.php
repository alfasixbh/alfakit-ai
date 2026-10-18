<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Tests\Fixtures\Resources\OrderResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\CreateRecord;
use AlfaSix\AlfakitAi\Tests\Fixtures\Resources\OrderResource;

class CreateOrder extends CreateRecord
{
    protected static string $resource = OrderResource::class;
}
