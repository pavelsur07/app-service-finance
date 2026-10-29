<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance\Infrastructure;

use App\Finance\Application\DTO\PLCategoryUsage;
use App\Finance\Entity\Document;
use App\Finance\Entity\DocumentOperation;
use App\Finance\Infrastructure\Query\PLCategoryUsageQuery;
use App\Loan\Entity\Loan;
use App\Marketplace\Entity\MarketplaceSaleMapping;
use App\Marketplace\Enum\AmountSource;
use App\Marketplace\Enum\MarketplaceType;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;

final class PLCategoryUsageQueryTest extends IntegrationTestCase
{
    public function testCountsEveryReferenceOfCompanyCategoriesOnly(): void
    {
        $user = UserBuilder::aUser()->withIndex(1)->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $foreignUser = UserBuilder::aUser()->withIndex(2)->build();
        $foreign = CompanyBuilder::aCompany()->withIndex(2)->withOwner($foreignUser)->build();

        $overhead = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Косвенные')->build();
        $rent = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Аренда')->withParent($overhead)->build();
        $office = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Офис')->withParent($overhead)->build();
        $foreignRoot = PLCategoryBuilder::aPLCategory()->forCompany($foreign)->withName('Чужая')->build();
        $foreignChild = PLCategoryBuilder::aPLCategory()->forCompany($foreign)->withName('Чужая вложенная')->withParent($foreignRoot)->build();
        $cashflow = CashflowCategoryBuilder::aCashflowCategory()->withIndex(1)->withCompany($company)->withName('Аренда ДДС')->build();
        $cashflow->setPlCategory($rent);
        foreach ([$user, $company, $foreignUser, $foreign, $overhead, $rent, $office, $foreignRoot, $foreignChild, $cashflow] as $entity) {
            $this->em->persist($entity);
        }

        // Аренда: две операции живого документа и одна — удалённого; привязана статья ДДС.
        $document = new Document(Uuid::uuid4()->toString(), $company);
        $deletedDocument = new Document(Uuid::uuid4()->toString(), $company);
        foreach ([[$document, 2], [$deletedDocument, 1]] as [$doc, $count]) {
            for ($i = 0; $i < $count; ++$i) {
                $operation = new DocumentOperation();
                $operation->setAmount('10.00');
                $operation->setCategory($rent);
                $doc->addOperation($operation);
            }
            $this->em->persist($doc);
        }
        $deletedDocument->markDeleted(null);

        // Кредит: проценты и комиссия на одну статью — две ссылки; правило продаж маркетплейса.
        $loan = new Loan($company, 'Кредит', '1000.00', new \DateTimeImmutable('2026-01-01'));
        $loan->setPlCategoryInterest($office);
        $loan->setPlCategoryFee($office);
        $this->em->persist($loan);
        $this->em->persist(new MarketplaceSaleMapping(Uuid::uuid7()->toString(), $company, MarketplaceType::WILDBERRIES, AmountSource::SALE_GROSS, $office));

        // Операция чужого документа на чужую статью — не видна.
        $foreignDocument = new Document(Uuid::uuid4()->toString(), $foreign);
        $foreignOperation = new DocumentOperation();
        $foreignOperation->setAmount('5.00');
        $foreignOperation->setCategory($foreignChild);
        $foreignDocument->addOperation($foreignOperation);
        $this->em->persist($foreignDocument);
        $this->em->flush();

        $query = self::getContainer()->get(PLCategoryUsageQuery::class);
        $usage = $query((string) $company->getId());

        self::assertEquals(
            [
                $overhead->getId() => new PLCategoryUsage(2, 0, 0, 0, 0, 0),
                $rent->getId() => new PLCategoryUsage(0, 2, 1, 1, 0, 0, [['id' => (string) $cashflow->getId(), 'name' => 'Аренда ДДС']]),
                // Проценты и комиссия одного кредита на одну статью — один кредит.
                $office->getId() => new PLCategoryUsage(0, 0, 0, 0, 1, 1, [], [['id' => $loan->getId(), 'name' => 'Кредит']]),
            ],
            $usage,
        );
        self::assertSame(
            '2 операции документов ОПиУ, 1 операция в удалённых документах ОПиУ, 1 связанная статья ДДС',
            $usage[(string) $rent->getId()]->summary(),
        );
        self::assertEquals([(string) $overhead->getId() => new PLCategoryUsage(2, 0, 0, 0, 0, 0)], $query((string) $company->getId(), (string) $overhead->getId()));
        self::assertSame([], $query((string) $company->getId(), (string) $foreignRoot->getId()), 'чужая компания не видна');
    }
}
