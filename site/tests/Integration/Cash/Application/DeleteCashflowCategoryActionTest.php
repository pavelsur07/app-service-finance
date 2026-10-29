<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\DeleteCashflowCategoryAction;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Exception\CashflowCategoryInUseException;
use App\Cash\Infrastructure\Query\CashflowCategoryUsageQuery;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\Connection;

final class DeleteCashflowCategoryActionTest extends IntegrationTestCase
{
    /**
     * Ссылку добавили между проверкой и удалением: проверка её не видит, FK отказывает —
     * пользователь получает понятный отказ, а не 500 (GlitchTip #449).
     */
    public function testForeignKeyRefusalBecomesInUseException(): void
    {
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $category = CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->withName('Аренда')->build();
        $account = MoneyAccountBuilder::aMoneyAccount()->forCompany($company)->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->withMoneyAccount($account)->withCashflowCategory($category)->build();
        foreach ([$user, $company, $category, $account, $transaction] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $staleConnection = $this->createStub(Connection::class);
        $staleConnection->method('fetchAllAssociative')->willReturn([]);
        $delete = new DeleteCashflowCategoryAction($this->em, new CashflowCategoryUsageQuery($staleConnection));

        $this->expectException(CashflowCategoryInUseException::class);
        $delete($category);
    }

    public function testRefusesCategoryInUseBeforeTouchingDatabase(): void
    {
        $user = UserBuilder::aUser()->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();
        $category = CashflowCategoryBuilder::aCashflowCategory()->withCompany($company)->withName('Аренда')->build();
        $account = MoneyAccountBuilder::aMoneyAccount()->forCompany($company)->build();
        $transaction = CashTransactionBuilder::aCashTransaction()->forCompany($company)->withMoneyAccount($account)->withCashflowCategory($category)->build();
        foreach ([$user, $company, $category, $account, $transaction] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        $delete = self::getContainer()->get(DeleteCashflowCategoryAction::class);
        try {
            $delete($category);
            self::fail('Ожидался отказ в удалении.');
        } catch (CashflowCategoryInUseException $exception) {
            self::assertSame(
                'Статью нельзя удалить. По статье 1 операция ДДС. Перенесите операции, платежи и правила на другую статью, затем удалите эту.',
                $exception->getMessage(),
            );
        }

        self::assertTrue($this->em->isOpen());
        self::assertNotNull($this->em->find(CashflowCategory::class, $category->getId()));
    }
}
