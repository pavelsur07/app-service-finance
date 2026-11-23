<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash;

use App\Cash\Application\Category\DeleteCashflowCategoryAction;
use App\Cash\Application\Category\SaveCashflowCategoryAction;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Enum\Transaction\CashflowFlowKind;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Company\Entity\Company;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Форма сохраняет статью под блокировкой дерева компании и перепроверяет по базе то, что могла
 * изменить параллельная загрузка из JSON: цикл, глубину, код. Форма держит устаревшие объекты —
 * параллельный commit моделируется записью в базу в обход EntityManager.
 */
final class SaveCashflowCategoryLockTest extends IntegrationTestCase
{
    private Company $company;
    private CashflowCategory $root;

    protected function setUp(): void
    {
        parent::setUp();
        $owner = UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail('cf-save-'.Uuid::uuid7()->toString().'@example.test')->build();
        $this->company = CompanyBuilder::aCompany()->withId(Uuid::uuid7()->toString())->withOwner($owner)->build();
        $this->root = $this->category('Расходы');
        $this->em->persist($owner);
        $this->em->persist($this->company);
        $this->em->flush();
    }

    public function testSaveWaitsForCompanyTreeLockHeldByAnotherSession(): void
    {
        $rent = $this->category('Аренда', $this->root);
        $this->em->flush();

        $other = DriverManager::getConnection($this->em->getConnection()->getParams());
        $other->beginTransaction();
        $other->executeQuery('SELECT pg_advisory_xact_lock(hashtext(?))', [$this->company->getId()]);
        $this->em->getConnection()->executeStatement("SET lock_timeout = '200ms'");

        try {
            $rent->setName('Аренда офиса');
            ($this->action())($rent);
            self::fail('Сохранение должно ждать блокировку дерева компании.');
        } catch (DriverException $exception) {
            self::assertSame('55P03', $exception->getSQLState(), 'lock timeout');
        } finally {
            $other->rollBack();
            $other->close();
        }
    }

    public function testCounterMoveCommittedMeanwhileIsRefusedWithoutCycle(): void
    {
        $a = $this->category('А', $this->root);
        $b = $this->category('Б', $this->root);
        $this->em->flush();
        // Параллельная загрузка уже перенесла А под Б; форма этого не видит.
        $this->db('UPDATE cashflow_categories SET parent_id = ? WHERE id = ?', [$b->getId(), $a->getId()]);

        $b->setParent($a);
        $this->assertRefused($b, 'Обновите страницу');

        self::assertSame($this->root->getId(), $this->em->getConnection()->fetchOne('SELECT parent_id FROM cashflow_categories WHERE id = ?', [$b->getId()]));
    }

    public function testMoveIsRefusedWhenChildrenAddedMeanwhileWouldExceedFiveLevels(): void
    {
        $l2 = $this->category('2', $this->root);
        $l3 = $this->category('3', $l2);
        $l4 = $this->category('4', $l3);
        $moved = $this->category('Переносимая', $this->root);
        $child = $this->category('Потомок', $this->root);
        $this->em->flush();
        $moved->getChildren()->count();
        // Потомок появился у статьи после того, как форма её загрузила.
        $this->db('UPDATE cashflow_categories SET parent_id = ? WHERE id = ?', [$moved->getId(), $child->getId()]);

        $moved->setParent($l4);
        $this->assertRefused($moved, '5 уровней');
    }

    public function testCodeTakenMeanwhileIsRefusedInsteadOfServerError(): void
    {
        $a = $this->category('А', $this->root);
        $b = $this->category('Б', $this->root);
        $this->em->flush();
        $this->db('UPDATE cashflow_categories SET system_code = ? WHERE id = ?', ['RENT', $a->getId()]);

        $b->setCode('RENT');
        $this->assertRefused($b, 'Код RENT');

        self::assertNull($this->em->getConnection()->fetchOne('SELECT system_code FROM cashflow_categories WHERE id = ?', [$b->getId()]));
    }

    public function testNewCategoryUnderParentDeletedMeanwhileIsRefused(): void
    {
        $parent = $this->category('Удаляемая', $this->root);
        $this->em->flush();
        $this->db('DELETE FROM cashflow_categories WHERE id = ?', [$parent->getId()]);

        $new = (new CashflowCategory(Uuid::uuid7()->toString(), $this->company))->setName('Новая')->setParent($parent);
        $this->assertRefused($new, 'Родительская статья удалена');

        self::assertFalse($this->em->getConnection()->fetchOne('SELECT 1 FROM cashflow_categories WHERE id = ?', [$new->getId()]));
    }

    public function testEditOfCategoryDeletedMeanwhileIsRefused(): void
    {
        $a = $this->category('А', $this->root);
        $this->em->flush();
        $this->db('DELETE FROM cashflow_categories WHERE id = ?', [$a->getId()]);

        $a->setName('А2');
        $this->assertRefused($a, 'Статья удалена');
    }

    public function testMoveUnderParentWhoseRootChangedFlowKindMeanwhileIsRefused(): void
    {
        $financing = $this->category('Финансы')->setFlowKind(CashflowFlowKind::FINANCING);
        $parent = $this->category('Родитель', $this->root);
        $moved = $this->category('Переносимая', $this->root);
        $this->em->flush();
        // Загрузка перенесла родителя под корень другого вида деятельности; форма держит старую цепочку.
        $this->db('UPDATE cashflow_categories SET parent_id = ?, flow_kind = ? WHERE id = ?', [$financing->getId(), 'FINANCING', $parent->getId()]);

        $moved->setParent($parent);
        $this->assertRefused($moved, 'Обновите страницу');

        self::assertSame(['parent_id' => $this->root->getId(), 'flow_kind' => 'OPERATING'], $this->em->getConnection()->fetchAssociative('SELECT parent_id, flow_kind FROM cashflow_categories WHERE id = ?', [$moved->getId()]));
    }

    public function testRootFlowKindChangeIsRefusedWhenChildrenChangedMeanwhile(): void
    {
        $root = $this->category('Корень');
        $child = $this->category('Чужой потомок', $this->root);
        $this->em->flush();
        $root->getChildren()->count();
        $this->db('UPDATE cashflow_categories SET parent_id = ? WHERE id = ?', [$root->getId(), $child->getId()]);

        $root->setFlowKind(CashflowFlowKind::INVESTING);
        $this->assertRefused($root, 'Обновите страницу');
    }

    public function testDeleteWaitsForCompanyTreeLock(): void
    {
        $a = $this->category('А', $this->root);
        $this->em->flush();

        $other = DriverManager::getConnection($this->em->getConnection()->getParams());
        $other->beginTransaction();
        $other->executeQuery('SELECT pg_advisory_xact_lock(hashtext(?))', [$this->company->getId()]);
        $this->em->getConnection()->executeStatement("SET lock_timeout = '200ms'");

        try {
            self::getContainer()->get(DeleteCashflowCategoryAction::class)($a);
            self::fail('Удаление должно ждать блокировку дерева компании.');
        } catch (DriverException $exception) {
            self::assertSame('55P03', $exception->getSQLState(), 'lock timeout');
        } finally {
            $other->rollBack();
            $other->close();
        }
    }

    public function testRegularEditIsSaved(): void
    {
        $a = $this->category('А', $this->root);
        $b = $this->category('Б', $this->root);
        $this->em->flush();

        $b->setParent($a)->setCode('B_CODE')->setName('Б2');
        ($this->action())($b);

        self::assertSame(
            ['parent_id' => $a->getId(), 'system_code' => 'B_CODE', 'name' => 'Б2'],
            $this->em->getConnection()->fetchAssociative('SELECT parent_id, system_code, name FROM cashflow_categories WHERE id = ?', [$b->getId()]),
        );
    }

    private function assertRefused(CashflowCategory $category, string $message): void
    {
        try {
            ($this->action())($category);
            self::fail('Ожидался отказ сохранения.');
        } catch (\DomainException $exception) {
            self::assertStringContainsString($message, $exception->getMessage());
        }
        self::assertTrue($this->em->isOpen(), 'Отказ не закрывает EntityManager: форма показывается заново.');
    }

    /** Валидатор пропускает всё: проверяется перепроверка под блокировкой, а не UniqueEntity до неё. */
    private function action(): SaveCashflowCategoryAction
    {
        $validator = $this->createStub(ValidatorInterface::class);
        $validator->method('validate')->willReturn(new ConstraintViolationList());

        return new SaveCashflowCategoryAction($this->em, $validator, self::getContainer()->get(CashflowCategoryRepository::class));
    }

    private function category(string $name, ?CashflowCategory $parent = null): CashflowCategory
    {
        $category = (new CashflowCategory(Uuid::uuid7()->toString(), $this->company))->setName($name)->setParent($parent);
        $this->em->persist($category);

        return $category;
    }

    /** @param list<mixed> $params */
    private function db(string $sql, array $params): void
    {
        $this->em->getConnection()->executeStatement($sql, $params);
    }
}
