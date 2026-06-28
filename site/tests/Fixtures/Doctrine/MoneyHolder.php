<?php

declare(strict_types=1);

namespace App\Tests\Fixtures\Doctrine;

use App\Shared\Domain\ValueObject\Money;
use Doctrine\ORM\Mapping as ORM;

/**
 * Тестовая Entity для проверки маппинга Money как Embeddable.
 * Таблица создаётся/удаляется в тесте через SchemaTool, в прод-схему не попадает.
 */
#[ORM\Entity]
#[ORM\Table(name: 'test_money_holder')]
class MoneyHolder
{
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 36)]
    private string $id;

    #[ORM\Embedded(class: Money::class, columnPrefix: false)]
    private Money $amount;

    public function __construct(string $id, Money $amount)
    {
        $this->id = $id;
        $this->amount = $amount;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getAmount(): Money
    {
        return $this->amount;
    }
}
