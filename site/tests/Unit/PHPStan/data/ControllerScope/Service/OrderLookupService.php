<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\ControllerScope\Service;

use App\Tests\Unit\PHPStan\data\ControllerScope\Entity\Order;
use App\Tests\Unit\PHPStan\data\ControllerScope\Repository\OrderRepository;

/** Не контроллер: правила контроллеров здесь молчат. */
final class OrderLookupService
{
    public function __construct(private readonly OrderRepository $orders)
    {
    }

    public function byId(Order $order, string $id): ?Order
    {
        return $this->orders->find($id) ?? $order;
    }
}
