<?php

declare(strict_types=1);

namespace App\Tests\Integration\Shared;

use App\Shared\Domain\ValueObject\Money;
use App\Tests\Fixtures\Doctrine\MoneyHolder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\ORM\Tools\SchemaTool;
use Ramsey\Uuid\Uuid;

final class MoneyEmbeddableMappingTest extends IntegrationTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $tool = new SchemaTool($this->em);
        $meta = [$this->em->getClassMetadata(MoneyHolder::class)];
        $tool->dropSchema($meta);
        $tool->createSchema($meta);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            (new SchemaTool($this->em))->dropSchema([$this->em->getClassMetadata(MoneyHolder::class)]);
        }

        parent::tearDown();
    }

    public function testEmbeddableMapsTwoColumns(): void
    {
        $meta = $this->em->getClassMetadata(MoneyHolder::class);

        self::assertTrue($meta->hasField('amount.amountMinor'));
        self::assertTrue($meta->hasField('amount.currency'));
        self::assertSame('amount_minor', $meta->getColumnName('amount.amountMinor'));
        self::assertSame('currency', $meta->getColumnName('amount.currency'));
        self::assertSame('money_amount_minor', $meta->getTypeOfField('amount.amountMinor'));
    }

    public function testRoundTripPersistAndHydrate(): void
    {
        $id = Uuid::uuid7()->toString();

        $this->em->persist(new MoneyHolder($id, Money::fromString('123.45', 'RUB')));
        $this->em->flush();
        $this->em->clear();

        $found = $this->em->getRepository(MoneyHolder::class)->find($id);

        self::assertNotNull($found);
        // ключевая проверка: readonly int гидрируется из bigint как int, не string
        self::assertSame(12345, $found->getAmount()->amountMinor());
        self::assertSame('RUB', $found->getAmount()->currency());
        self::assertTrue($found->getAmount()->equals(Money::fromMinor(12345, 'RUB')));
    }

    public function testRoundTripNegativeAmount(): void
    {
        $id = Uuid::uuid7()->toString();

        $this->em->persist(new MoneyHolder($id, Money::fromMinor(-50000, 'USD')));
        $this->em->flush();
        $this->em->clear();

        $found = $this->em->getRepository(MoneyHolder::class)->find($id);

        self::assertNotNull($found);
        self::assertSame(-50000, $found->getAmount()->amountMinor());
        self::assertSame('USD', $found->getAmount()->currency());
    }
}
