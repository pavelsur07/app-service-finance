<?php

declare(strict_types=1);

namespace App\Tests\Integration\Catalog;

use App\Catalog\Application\SetPurchasePriceAction;
use App\Catalog\DTO\SetPurchasePriceCommand;
use App\Catalog\Entity\Product;
use App\Catalog\Entity\ProductPurchasePrice;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class SetPurchasePriceActionTest extends IntegrationTestCase
{
    public function testFirstInsertCreatesRecord(): void
    {
        [$companyId, $productId] = $this->createBaseFixtures(
            'owner-set-price-a@example.test',
            '11111111-1111-1111-1111-111111111991',
            '33333333-3333-3333-3333-333333333991'
        );

        $command = $this->makeCommand($companyId, $productId, '2026-04-01', 10000, 'Первая цена');
        $priceId = $this->action()($command);

        $loaded = $this->em()->getRepository(ProductPurchasePrice::class)->find($priceId);
        self::assertInstanceOf(ProductPurchasePrice::class, $loaded);
        self::assertSame('2026-04-01', $loaded->getEffectiveFrom()->format('Y-m-d'));
        self::assertNull($loaded->getEffectiveTo());
    }

    public function testSecondInsertClosesPreviousRecord(): void
    {
        [$companyId, $productId] = $this->createBaseFixtures(
            'owner-set-price-b@example.test',
            '11111111-1111-1111-1111-111111111992',
            '33333333-3333-3333-3333-333333333992'
        );

        $this->action()($this->makeCommand($companyId, $productId, '2026-04-01', 10000, 'Начальная цена'));
        $this->action()($this->makeCommand($companyId, $productId, '2026-04-15', 12000, 'Новая цена'));

        $prices = $this->em()->getRepository(ProductPurchasePrice::class)->findBy([], ['effectiveFrom' => 'ASC']);
        self::assertCount(2, $prices);
        self::assertSame('2026-04-14', $prices[0]->getEffectiveTo()?->format('Y-m-d'));
        self::assertNull($prices[1]->getEffectiveTo());
    }

    public function testInsertOverlappingFutureRecordThrowsDomainException(): void
    {
        [$companyId, $productId] = $this->createBaseFixtures(
            'owner-set-price-c@example.test',
            '11111111-1111-1111-1111-111111111993',
            '33333333-3333-3333-3333-333333333993'
        );

        $this->action()($this->makeCommand($companyId, $productId, '2026-05-10', 15000, 'Будущая цена'));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Нельзя установить цену с даты 2026-05-10, потому что уже есть цена начиная с 2026-05-10.');

        $this->action()($this->makeCommand($companyId, $productId, '2026-05-10', 11000, 'Конфликтная цена'));
    }

    private function makeCommand(string $companyId, string $productId, string $effectiveFrom, int $amount, ?string $note): SetPurchasePriceCommand
    {
        $command = new SetPurchasePriceCommand();
        $command->companyId = $companyId;
        $command->productId = $productId;
        $command->effectiveFrom = new \DateTimeImmutable($effectiveFrom);
        $command->priceAmount = $amount;
        $command->currency = 'RUB';
        $command->note = $note;

        return $command;
    }

    private function createBaseFixtures(string $email, string $companyId, string $productId): array
    {
        $owner = UserBuilder::aUser()->withEmail($email)->build();
        $company = CompanyBuilder::aCompany()
            ->withId($companyId)
            ->withOwner($owner)
            ->withName('Company for purchase price tests')
            ->build();

        $product = (new Product($productId, $company))
            ->setName('Product for purchase price tests')
            ->setSku('SKU-'.$productId)
            ->setPurchasePrice('100.00');

        $this->em()->persist($owner);
        $this->em()->persist($company);
        $this->em()->persist($product);
        $this->em()->flush();

        return [$companyId, $productId];
    }

    private function action(): SetPurchasePriceAction
    {
        return self::getContainer()->get(SetPurchasePriceAction::class);
    }
}
