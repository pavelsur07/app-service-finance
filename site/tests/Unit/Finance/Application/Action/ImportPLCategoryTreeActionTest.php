<?php

declare(strict_types=1);

namespace App\Tests\Unit\Finance\Application\Action;

use App\Company\Entity\Company;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Finance\Application\Action\ImportPLCategoryTreeAction;
use App\Finance\Application\Command\ImportPLCategoryTreeCommand;
use App\Finance\Application\DTO\PLCategoryTreeNode;
use App\Finance\Application\Service\PLCategoryTreeExporter;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLFlow;
use App\Finance\Repository\PLCategoryRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;

final class ImportPLCategoryTreeActionTest extends TestCase
{
    public function testCreatesTreeInEmptyTargetCompany(): void
    {
        $source = CompanyBuilder::aCompany()->withIndex(1)->build();
        $target = CompanyBuilder::aCompany()->withIndex(2)->build();

        $root = PLCategoryBuilder::aPLCategory()->forCompany($source)->withName('Расходы')->withCode('EXP')->build();
        $child = PLCategoryBuilder::aPLCategory()->forCompany($source)->withName('Маркетинг')->withParent($root)->build();

        $plCategoryRepository = $this->createMock(PLCategoryRepository::class);
        $sourceNodes = $this->nodes([$root, $child]);
        $plCategoryRepository->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(2))->method('persist');
        $em->expects(self::once())->method('flush');
        $em->expects(self::never())->method('remove');

        $result = $this->action($target, $plCategoryRepository, $em)(
            new ImportPLCategoryTreeCommand($sourceNodes, (string) $target->getId(), false),
        );

        self::assertCount(2, $result->created);
        self::assertSame('Расходы', $result->created[0]->name);
        self::assertSame('Расходы / Маркетинг', $result->created[1]->path);
        self::assertSame([], $result->updated);
        self::assertSame(0, $result->unchangedCount);
    }

    public function testUpdatesExistingNodeMatchedByCode(): void
    {
        $source = CompanyBuilder::aCompany()->withIndex(1)->build();
        $target = CompanyBuilder::aCompany()->withIndex(2)->build();

        $sourceRoot = PLCategoryBuilder::aPLCategory()->forCompany($source)
            ->withName('Расходы')->withCode('EXP')->withFlow(PLFlow::EXPENSE)->build();
        $existingRoot = PLCategoryBuilder::aPLCategory()->forCompany($target)
            ->withName('Старое имя')->withCode('EXP')->withFlow(PLFlow::INCOME)->build();

        $plCategoryRepository = $this->createMock(PLCategoryRepository::class);
        $sourceNodes = $this->nodes([$sourceRoot]);
        $plCategoryRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?PLCategory => 'EXP' === ($criteria['code'] ?? null) ? $existingRoot : null,
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::once())->method('flush');

        $result = $this->action($target, $plCategoryRepository, $em)(
            new ImportPLCategoryTreeCommand($sourceNodes, (string) $target->getId(), false),
        );

        self::assertSame([], $result->created);
        self::assertCount(1, $result->updated);
        self::assertSame(0, $result->unchangedCount);
        self::assertSame('Расходы', $existingRoot->getName());
        self::assertSame(PLFlow::EXPENSE, $existingRoot->getFlow());
    }

    public function testMatchesByParentAndNameWhenSourceCodeIsNull(): void
    {
        $source = CompanyBuilder::aCompany()->withIndex(1)->build();
        $target = CompanyBuilder::aCompany()->withIndex(2)->build();

        $sourceRoot = PLCategoryBuilder::aPLCategory()->forCompany($source)->withName('Расходы')->withCode('EXP')->build();
        $sourceChild = PLCategoryBuilder::aPLCategory()->forCompany($source)
            ->withName('Маркетинг')->withParent($sourceRoot)->build();

        $existingRoot = PLCategoryBuilder::aPLCategory()->forCompany($target)->withName('Расходы')->withCode('EXP')->build();
        $existingChild = PLCategoryBuilder::aPLCategory()->forCompany($target)
            ->withName('Маркетинг')->withParent($existingRoot)->build();
        $existingChild->setIsVisible(false); // отличается от источника — должно обновиться

        $plCategoryRepository = $this->createMock(PLCategoryRepository::class);
        $sourceNodes = $this->nodes([$sourceRoot, $sourceChild]);
        $plCategoryRepository->method('findOneBy')->willReturnCallback(
            static function (array $criteria) use ($existingRoot, $existingChild): ?PLCategory {
                if (array_key_exists('code', $criteria)) {
                    return 'EXP' === $criteria['code'] ? $existingRoot : null;
                }

                if ($existingRoot === $criteria['parent'] && 'Маркетинг' === $criteria['name']) {
                    return $existingChild;
                }

                return null;
            },
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::once())->method('flush');

        $result = $this->action($target, $plCategoryRepository, $em)(
            new ImportPLCategoryTreeCommand($sourceNodes, (string) $target->getId(), false),
        );

        self::assertSame([], $result->created);
        self::assertCount(1, $result->updated);
        self::assertSame(1, $result->unchangedCount);
        self::assertTrue($existingChild->isVisible());
        self::assertSame($existingRoot, $existingChild->getParent());
    }

    public function testDryRunDoesNotPersistOrFlush(): void
    {
        $source = CompanyBuilder::aCompany()->withIndex(1)->build();
        $target = CompanyBuilder::aCompany()->withIndex(2)->build();

        $root = PLCategoryBuilder::aPLCategory()->forCompany($source)->withName('Расходы')->withCode('EXP')->build();

        $plCategoryRepository = $this->createMock(PLCategoryRepository::class);
        $sourceNodes = $this->nodes([$root]);
        $plCategoryRepository->method('findOneBy')->willReturn(null);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');
        $em->expects(self::never())->method('remove');

        $result = $this->action($target, $plCategoryRepository, $em)(
            new ImportPLCategoryTreeCommand($sourceNodes, (string) $target->getId(), true),
        );

        self::assertCount(1, $result->created);
    }

    public function testDryRunReportsUpdateWithoutMutatingManagedEntity(): void
    {
        $source = CompanyBuilder::aCompany()->withIndex(1)->build();
        $target = CompanyBuilder::aCompany()->withIndex(2)->build();

        $sourceRoot = PLCategoryBuilder::aPLCategory()->forCompany($source)
            ->withName('Расходы')->withCode('EXP')->withFlow(PLFlow::EXPENSE)->build();
        $existingRoot = PLCategoryBuilder::aPLCategory()->forCompany($target)
            ->withName('Старое имя')->withCode('EXP')->withFlow(PLFlow::INCOME)->build();

        $plCategoryRepository = $this->createMock(PLCategoryRepository::class);
        $sourceNodes = $this->nodes([$sourceRoot]);
        $plCategoryRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?PLCategory => 'EXP' === ($criteria['code'] ?? null) ? $existingRoot : null,
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $result = $this->action($target, $plCategoryRepository, $em)(
            new ImportPLCategoryTreeCommand($sourceNodes, (string) $target->getId(), true),
        );

        self::assertCount(1, $result->updated);
        self::assertSame('Старое имя', $existingRoot->getName());
        self::assertSame(PLFlow::INCOME, $existingRoot->getFlow());
    }

    public function testReRunAfterApplyIsIdempotent(): void
    {
        $source = CompanyBuilder::aCompany()->withIndex(1)->build();
        $target = CompanyBuilder::aCompany()->withIndex(2)->build();

        $root = PLCategoryBuilder::aPLCategory()->forCompany($source)->withName('Расходы')->withCode('EXP')->build();
        $child = PLCategoryBuilder::aPLCategory()->forCompany($source)->withName('Маркетинг')->withParent($root)->build();

        /** @var list<PLCategory> $persisted */
        $persisted = [];

        $plCategoryRepository = $this->createMock(PLCategoryRepository::class);
        $sourceNodes = $this->nodes([$root, $child]);
        $plCategoryRepository->method('findOneBy')->willReturnCallback(
            static function (array $criteria) use (&$persisted): ?PLCategory {
                foreach ($persisted as $category) {
                    if (array_key_exists('code', $criteria)) {
                        if (null !== $category->getCode() && $criteria['code'] === $category->getCode()) {
                            return $category;
                        }

                        continue;
                    }

                    if ($criteria['parent'] === $category->getParent() && $criteria['name'] === $category->getName()) {
                        return $category;
                    }
                }

                return null;
            },
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('persist')->willReturnCallback(static function (object $entity) use (&$persisted): void {
            $persisted[] = $entity;
        });
        $em->expects(self::exactly(2))->method('flush');

        $action = $this->action($target, $plCategoryRepository, $em);
        $command = new ImportPLCategoryTreeCommand($sourceNodes, (string) $target->getId(), false);

        $first = $action($command);
        self::assertCount(2, $first->created);
        self::assertSame([], $first->updated);

        $second = $action($command);
        self::assertSame([], $second->created);
        self::assertSame([], $second->updated);
        self::assertSame(2, $second->unchangedCount);
    }

    public function testRejectsMoveOfExistingNodeWhenPreservedDescendantsWouldExceedMaxDepthOnApply(): void
    {
        $source = CompanyBuilder::aCompany()->withIndex(1)->build();
        $target = CompanyBuilder::aCompany()->withIndex(2)->build();
        [$sourceNodes, $plCategoryRepository] = $this->preservedDepthScenario($source, $target);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Превышена максимальная вложенность (5 уровней) при переносе категории "X".');

        $this->action($target, $plCategoryRepository, $em)(
            new ImportPLCategoryTreeCommand($sourceNodes, (string) $target->getId(), false),
        );
    }

    public function testRejectsMoveOfExistingNodeWhenPreservedDescendantsWouldExceedMaxDepthOnDryRun(): void
    {
        // Тот же сценарий, что и *OnApply — dry-run и apply обязаны совпадать:
        // preview не должен молчать о проблеме, которую apply потом отклонит,
        // и наоборот. Регрессия: раньше проверка глубины смотрела на
        // «живой» getLevel() совпавшего узла, который в dry-run не мутируется.
        $source = CompanyBuilder::aCompany()->withIndex(1)->build();
        $target = CompanyBuilder::aCompany()->withIndex(2)->build();
        [$sourceNodes, $plCategoryRepository] = $this->preservedDepthScenario($source, $target);

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Превышена максимальная вложенность (5 уровней) при переносе категории "X".');

        $this->action($target, $plCategoryRepository, $em)(
            new ImportPLCategoryTreeCommand($sourceNodes, (string) $target->getId(), true),
        );
    }

    public function testAllowsMovingExistingNodeToRootWithNewChildOnApply(): void
    {
        [$sourceNodes, $target, $plCategoryRepository] = $this->rootMoveWithNewChildScenario();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $result = $this->action($target, $plCategoryRepository, $em)(
            new ImportPLCategoryTreeCommand($sourceNodes, (string) $target->getId(), false),
        );

        self::assertCount(1, $result->created);
        self::assertSame('Y', $result->created[0]->name);
    }

    public function testAllowsMovingExistingNodeToRootWithNewChildOnDryRun(): void
    {
        // Регрессия: план хранил create/update раздельно и применял их двумя
        // отдельными проходами — X (update) мог остаться на старом уровне,
        // когда обрабатывался Y (create), из-за чего apply отклонял валидный
        // перенос, который dry-run считал допустимым. Мутации теперь идут
        // одним проходом в исходном порядке дерева, а сама проверка глубины
        // вообще не смотрит на «живой» уровень сущностей.
        [$sourceNodes, $target, $plCategoryRepository] = $this->rootMoveWithNewChildScenario();

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('persist');
        $em->expects(self::never())->method('flush');

        $result = $this->action($target, $plCategoryRepository, $em)(
            new ImportPLCategoryTreeCommand($sourceNodes, (string) $target->getId(), true),
        );

        self::assertCount(1, $result->created);
        self::assertSame('Y', $result->created[0]->name);
    }

    public function testDoesNotCountSiblingBeingReparentedElsewhereAsPreservedDepth(): void
    {
        // Регрессия: preservedDescendantDepth() считал ВСЕХ живых потомков
        // совпавшего узла, включая тех, которыми источник управляет отдельно
        // (matched, переносятся в другое место) — из-за чего валидный перенос
        // мог быть ошибочно отклонён.
        $source = CompanyBuilder::aCompany()->withIndex(1)->build();
        $target = CompanyBuilder::aCompany()->withIndex(2)->build();

        // Target: A(уровень 1) → B(уровень 2), оба существуют.
        $existingA = PLCategoryBuilder::aPLCategory()->forCompany($target)->withName('A (старое)')->withCode('A_CODE')->build();
        $existingB = PLCategoryBuilder::aPLCategory()->forCompany($target)
            ->withName('B (старое)')->withCode('B_CODE')->withParent($existingA)->build();

        // Source: A уходит на уровень 5 (под 4 новых предка), B становится
        // отдельным корнем — источник управляет обоими независимо, поэтому B
        // не должен засчитываться в глубину, "сохраняемую" вместе с A.
        $sourceAncestors = [];
        $ancestor = null;
        for ($level = 1; $level <= 4; ++$level) {
            $ancestor = PLCategoryBuilder::aPLCategory()->forCompany($source)
                ->withName(sprintf('Предок %d', $level))->withParent($ancestor)->build();
            $sourceAncestors[] = $ancestor;
        }
        $sourceA = PLCategoryBuilder::aPLCategory()->forCompany($source)->withName('A')->withCode('A_CODE')->withParent($ancestor)->build();
        $sourceB = PLCategoryBuilder::aPLCategory()->forCompany($source)->withName('B')->withCode('B_CODE')->build();

        $plCategoryRepository = $this->createMock(PLCategoryRepository::class);
        $sourceNodes = $this->nodes([...$sourceAncestors, $sourceA, $sourceB]);
        $plCategoryRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?PLCategory => match ($criteria['code'] ?? null) {
                'A_CODE' => $existingA,
                'B_CODE' => $existingB,
                default => null,
            },
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::exactly(4))->method('persist');
        $em->expects(self::once())->method('flush');

        $result = $this->action($target, $plCategoryRepository, $em)(
            new ImportPLCategoryTreeCommand($sourceNodes, (string) $target->getId(), false),
        );

        self::assertCount(4, $result->created);
        self::assertCount(2, $result->updated);
        self::assertSame('A', $existingA->getName());
        self::assertSame('B', $existingB->getName());
        self::assertNull($existingB->getParent());
    }

    public function testDoesNotAssignSameExistingTargetNodeToTwoSourceNodes(): void
    {
        // Регрессия: code-матч и (parent,name)-фолбэк резолвятся независимо и
        // могут указать на одну и ту же target-строку — например, source
        // переименовывает узел с кодом C, а другой source-узел без кода
        // случайно называется как старое (дореименования) имя этого узла.
        $source = CompanyBuilder::aCompany()->withIndex(1)->build();
        $target = CompanyBuilder::aCompany()->withIndex(2)->build();

        $existingC = PLCategoryBuilder::aPLCategory()->forCompany($target)->withName('Старое')->withCode('C')->build();

        $sourceRenamed = PLCategoryBuilder::aPLCategory()->forCompany($source)->withName('Новое')->withCode('C')->build();
        $sourceCollision = PLCategoryBuilder::aPLCategory()->forCompany($source)->withName('Старое')->build();

        $plCategoryRepository = $this->createMock(PLCategoryRepository::class);
        $sourceNodes = $this->nodes([$sourceRenamed, $sourceCollision]);
        $plCategoryRepository->method('findOneBy')->willReturnCallback(
            static function (array $criteria) use ($existingC): ?PLCategory {
                if (array_key_exists('code', $criteria)) {
                    return 'C' === $criteria['code'] ? $existingC : null;
                }

                return null === $criteria['parent'] && 'Старое' === $criteria['name'] ? $existingC : null;
            },
        );

        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::once())->method('persist');
        $em->expects(self::once())->method('flush');

        $result = $this->action($target, $plCategoryRepository, $em)(
            new ImportPLCategoryTreeCommand($sourceNodes, (string) $target->getId(), false),
        );

        self::assertCount(1, $result->created);
        self::assertSame('Старое', $result->created[0]->name);
        self::assertCount(1, $result->updated);
        self::assertSame('Новое', $existingC->getName());
    }

    public function testReleasesChangingCodeBeforeCreatingNodeThatClaimsIt(): void
    {
        // code — стабильный идентификатор строки P&L (см. releaseChangingCodes()
        // в самом Action) — уникальность в рамках компании держим как
        // прикладной инвариант. Doctrine внутри одного flush() выполняет все
        // insert раньше всех update, поэтому без отдельного pre-flush,
        // освобождающего старый code, insert новой записи с тем же code
        // временно столкнулся бы с ещё не очищенным старым значением. Мок
        // EntityManager не может подтвердить поведение реальной БД (см.
        // интеграционный тест ImportPLCategoryTreeActionCodeCollisionTest),
        // здесь проверяется только структура: код освобождается отдельным
        // flush() ДО create.
        $source = CompanyBuilder::aCompany()->withIndex(1)->build();
        $target = CompanyBuilder::aCompany()->withIndex(2)->build();

        $existingC = PLCategoryBuilder::aPLCategory()->forCompany($target)->withName('Совпадает по имени')->withCode('C')->build();

        $sourceNoCode = PLCategoryBuilder::aPLCategory()->forCompany($source)->withName('Совпадает по имени')->build();
        $sourceWithCode = PLCategoryBuilder::aPLCategory()->forCompany($source)->withName('Новый узел')->withCode('C')->build();

        $plCategoryRepository = $this->createMock(PLCategoryRepository::class);
        $sourceNodes = $this->nodes([$sourceNoCode, $sourceWithCode]);
        $plCategoryRepository->method('findOneBy')->willReturnCallback(
            static function (array $criteria) use ($existingC): ?PLCategory {
                if (array_key_exists('code', $criteria)) {
                    return 'C' === $criteria['code'] ? $existingC : null;
                }

                return null === $criteria['parent'] && 'Совпадает по имени' === $criteria['name'] ? $existingC : null;
            },
        );

        $flushCallCount = 0;
        $codeAtFirstFlush = 'not-called';

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('flush')->willReturnCallback(function () use (&$flushCallCount, &$codeAtFirstFlush, $existingC): void {
            ++$flushCallCount;
            if (1 === $flushCallCount) {
                $codeAtFirstFlush = $existingC->getCode();
            }
        });
        $em->expects(self::once())->method('persist');

        $this->action($target, $plCategoryRepository, $em)(
            new ImportPLCategoryTreeCommand($sourceNodes, (string) $target->getId(), false),
        );

        self::assertSame(2, $flushCallCount);
        self::assertNull($codeAtFirstFlush);
        self::assertNull($existingC->getCode());
    }

    public function testWrapsBothFlushesInSingleTransaction(): void
    {
        // Регрессия: если бы releaseChangingCodes() (со своим промежуточным
        // flush()) или основной flush() случайно оказались вне
        // wrapInTransaction(), падение одного из них не откатило бы другой —
        // код мог бы остаться освобождённым (code=null) без итогового
        // значения. Проверяем: ровно один wrapInTransaction(), и оба flush()
        // происходят внутри его границы.
        $source = CompanyBuilder::aCompany()->withIndex(1)->build();
        $target = CompanyBuilder::aCompany()->withIndex(2)->build();

        $existingC = PLCategoryBuilder::aPLCategory()->forCompany($target)->withName('Совпадает по имени')->withCode('C')->build();
        $sourceNoCode = PLCategoryBuilder::aPLCategory()->forCompany($source)->withName('Совпадает по имени')->build();
        $sourceWithCode = PLCategoryBuilder::aPLCategory()->forCompany($source)->withName('Новый узел')->withCode('C')->build();

        $plCategoryRepository = $this->createMock(PLCategoryRepository::class);
        $sourceNodes = $this->nodes([$sourceNoCode, $sourceWithCode]);
        $plCategoryRepository->method('findOneBy')->willReturnCallback(
            static function (array $criteria) use ($existingC): ?PLCategory {
                if (array_key_exists('code', $criteria)) {
                    return 'C' === $criteria['code'] ? $existingC : null;
                }

                return null === $criteria['parent'] && 'Совпадает по имени' === $criteria['name'] ? $existingC : null;
            },
        );

        $companyRepository = $this->createMock(CompanyRepository::class);
        $companyRepository->method('findById')->willReturnCallback(
            static fn (string $id): ?Company => match ($id) {
                (string) $source->getId() => $source,
                (string) $target->getId() => $target,
                default => null,
            },
        );

        $wrapInTransactionCalls = 0;
        $insideTransaction = false;
        $flushesInsideTransaction = 0;
        $flushesOutsideTransaction = 0;

        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(
            function (callable $func) use (&$wrapInTransactionCalls, &$insideTransaction): mixed {
                ++$wrapInTransactionCalls;
                $insideTransaction = true;
                try {
                    return $func();
                } finally {
                    $insideTransaction = false;
                }
            },
        );
        $em->method('flush')->willReturnCallback(
            function () use (&$insideTransaction, &$flushesInsideTransaction, &$flushesOutsideTransaction): void {
                if ($insideTransaction) {
                    ++$flushesInsideTransaction;
                } else {
                    ++$flushesOutsideTransaction;
                }
            },
        );

        (new ImportPLCategoryTreeAction($companyRepository, $plCategoryRepository, $em))(
            new ImportPLCategoryTreeCommand($sourceNodes, (string) $target->getId(), false),
        );

        self::assertSame(1, $wrapInTransactionCalls);
        self::assertSame(2, $flushesInsideTransaction);
        self::assertSame(0, $flushesOutsideTransaction);
    }

    public function testRejectsWhenTargetCompanyNotFound(): void
    {
        $companyRepository = $this->createMock(CompanyRepository::class);
        $companyRepository->method('findById')->willReturn(null);

        $plCategoryRepository = $this->createMock(PLCategoryRepository::class);
        $em = $this->createMock(EntityManagerInterface::class);
        $em->expects(self::never())->method('flush');

        $this->expectException(\DomainException::class);

        (new ImportPLCategoryTreeAction($companyRepository, $plCategoryRepository, $em))(
            new ImportPLCategoryTreeCommand([], '11111111-1111-1111-1111-000000000009', false),
        );
    }

    /**
     * Источник: A → B → C → X(code=DEEP), X окажется на уровне 4.
     * Target: X уже существует на уровне 1 с сохранённой веткой X → Y → Z
     * (2 уровня потомков, отсутствующих в источнике) — перенос X на уровень 4
     * увёл бы Z на уровень 6.
     *
     * @return array{0: list<PLCategoryTreeNode>, 1: PLCategoryRepository}
     */
    private function preservedDepthScenario(Company $source, Company $target): array
    {
        $sourceA = PLCategoryBuilder::aPLCategory()->forCompany($source)->withName('A')->build();
        $sourceB = PLCategoryBuilder::aPLCategory()->forCompany($source)->withName('B')->withParent($sourceA)->build();
        $sourceC = PLCategoryBuilder::aPLCategory()->forCompany($source)->withName('C')->withParent($sourceB)->build();
        $sourceX = PLCategoryBuilder::aPLCategory()->forCompany($source)->withName('X')->withCode('DEEP')->withParent($sourceC)->build();

        $existingX = PLCategoryBuilder::aPLCategory()->forCompany($target)->withName('X (старое имя)')->withCode('DEEP')->build();
        $existingY = PLCategoryBuilder::aPLCategory()->forCompany($target)->withName('Y')->withParent($existingX)->build();
        PLCategoryBuilder::aPLCategory()->forCompany($target)->withName('Z')->withParent($existingY)->build();

        $plCategoryRepository = $this->createMock(PLCategoryRepository::class);
        $sourceNodes = $this->nodes([$sourceA, $sourceB, $sourceC, $sourceX]);
        $plCategoryRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?PLCategory => 'DEEP' === ($criteria['code'] ?? null) ? $existingX : null,
        );

        return [$sourceNodes, $plCategoryRepository];
    }

    /**
     * Target: X существует на уровне 5 (валидно само по себе).
     * Source: X переносится в корень и получает нового потомка Y — итоговое
     * дерево валидно (X уровень 1, Y уровень 2).
     *
     * @return array{0: list<PLCategoryTreeNode>, 1: Company, 2: PLCategoryRepository}
     */
    private function rootMoveWithNewChildScenario(): array
    {
        $source = CompanyBuilder::aCompany()->withIndex(1)->build();
        $target = CompanyBuilder::aCompany()->withIndex(2)->build();

        $chainParent = null;
        for ($level = 1; $level <= 5; ++$level) {
            $chainParent = PLCategoryBuilder::aPLCategory()->forCompany($target)
                ->withName(sprintf('Существующий уровень %d', $level))
                ->withParent($chainParent)
                ->build();
        }
        $chainParent->setCode('DEEP');
        $existingX = $chainParent;

        $sourceX = PLCategoryBuilder::aPLCategory()->forCompany($source)->withName('X')->withCode('DEEP')->build();
        $sourceY = PLCategoryBuilder::aPLCategory()->forCompany($source)->withName('Y')->withParent($sourceX)->build();

        $plCategoryRepository = $this->createMock(PLCategoryRepository::class);
        $sourceNodes = $this->nodes([$sourceX, $sourceY]);
        $plCategoryRepository->method('findOneBy')->willReturnCallback(
            static fn (array $criteria): ?PLCategory => 'DEEP' === ($criteria['code'] ?? null) ? $existingX : null,
        );

        return [$sourceNodes, $target, $plCategoryRepository];
    }

    /**
     * Источник строится настоящим PLCategoryTreeExporter, а не вручную: так
     * тесты проверяют ровно ту пару «экспортёр + Action», которая работает в
     * проде на переносе компания→компания.
     *
     * @param PLCategory[] $dfsTree
     *
     * @return list<PLCategoryTreeNode>
     */
    private function nodes(array $dfsTree): array
    {
        return (new PLCategoryTreeExporter())->fromEntities($dfsTree);
    }

    private function action(
        Company $target,
        PLCategoryRepository $plCategoryRepository,
        EntityManagerInterface $em,
    ): ImportPLCategoryTreeAction {
        $companyRepository = $this->createMock(CompanyRepository::class);
        $companyRepository->method('findById')->willReturnCallback(
            static fn (string $id): ?Company => (string) $target->getId() === $id ? $target : null,
        );

        // Мок EntityManagerInterface по умолчанию не вызывает переданный
        // callable — иначе мутационная фаза Action (обёрнута в
        // wrapInTransaction) молча не выполнится.
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $func): mixed => $func());

        return new ImportPLCategoryTreeAction($companyRepository, $plCategoryRepository, $em);
    }
}
