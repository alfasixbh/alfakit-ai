<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Tests\Fixtures\Resources\OrderResource\Pages;

use AlfaSix\Alfakit\Resources\Pages\EditRecord;
use AlfaSix\AlfakitAi\Tests\Fixtures\Resources\OrderResource;

class EditOrder extends EditRecord
{
    protected static string $resource = OrderResource::class;
}
