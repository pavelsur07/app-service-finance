<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\ControllerScope\Controller;

use App\Tests\Unit\PHPStan\data\ControllerScope\Entity\Order;
use App\Tests\Unit\PHPStan\data\ControllerScope\Entity\Tariff;
use App\Tests\Unit\PHPStan\data\ControllerScope\Repository\LegacyOrderRepository;
use App\Tests\Unit\PHPStan\data\ControllerScope\Repository\OrderRepository;
use App\Tests\Unit\PHPStan\data\ControllerScope\Repository\TariffRepository;
use Doctrine\ORM\EntityManagerInterface;

final class OrderController
{
    public function __construct(
        private readonly OrderRepository $orders,
        private readonly TariffRepository $tariffs,
        private readonly EntityManagerInterface $em,
        private readonly LegacyOrderRepository $legacyOrders,
    ) {
    }

    public function show(Order $order, string $id): string
    {
        return $order->getId().$id;
    }

    public function tariff(Tariff $tariff): string
    {
        return $tariff->getId();
    }

    /**
     * @return list<object|null>
     */
    public function lookups(string $id): array
    {
        return [
            $this->orders->find($id),
            $this->em->find(Order::class, $id),
            $this->orders->findOneBy(['id' => $id, 'companyId' => 'c']),
            $this->tariffs->find($id),
            ...$this->orders->findAll(),
            $this->legacyOrders->find($id),
            $this->em->getRepository(Order::class)->find($id),
        ];
    }

    public static function fromOrder(?Order $order): string
    {
        return null !== $order ? $order->getId() : '';
    }

    public function nullable(?Order $order): string
    {
        return null !== $order ? $order->getId() : '';
    }

    private function helper(Order $order): string
    {
        return $order->getId();
    }
}
