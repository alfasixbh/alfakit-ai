<?php

declare(strict_types=1);

namespace AlfaSix\AlfakitAi\Tests\Fixtures\Policies;

use AlfaSix\AlfakitAi\Tests\Fixtures\Models\Order;

class OrderPolicy
{
    public static bool $canCreate = true;

    public function viewAny(mixed $user): bool
    {
        return true;
    }

    public function view(mixed $user, Order $order): bool
    {
        return ! $order->getAttribute('is_private');
    }

    public function create(mixed $user): bool
    {
        return self::$canCreate;
    }

    public function update(mixed $user, Order $order): bool
    {
        return $order->getAttribute('status') !== 'locked';
    }

    public function delete(mixed $user, Order $order): bool
    {
        return false;
    }
}
