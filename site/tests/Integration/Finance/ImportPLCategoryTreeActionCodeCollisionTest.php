<?php

declare(strict_types=1);

namespace App\Tests\Integration\Finance;

use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Finance\Application\Action\ImportPLCategoryTreeAction;
use App\Finance\Application\Command\ImportPLCategoryTreeCommand;
use App\Finance\Repository\PLCategoryRepository;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;

/**
 * Проверяет releaseChangingCodes() на реальном Doctrine + PostgreSQL:
 * итоговые значения code после apply корректны при коллизии code-матча и
 * (parent,name)-фолбэка на одну и ту же существующую строку.
 *
 * Индекс `uniq_plcat_company_code` (company_id, code), созданный в
 * `Version20251001120000`, на момент написания теста в схеме отсутствует —
 * удалён в `Version20251105174115::up()` и не восстановлен (см. ARCHITECTURE
 * changelog / Stage Report задачи pl-category-import). Поэтому этот тест НЕ
 * доказывает защиту от нарушения того индекса — только то, что итоговые
 * code после apply верны. Если индекс восстановят отдельной миграцией,
 * releaseChangingCodes() — это защита от временной коллизии внутри одного
 * flush(), актуальной именно тогда.
 */
final class ImportPLCategoryTreeActionCodeCollisionTest extends IntegrationTestCase
{
    public function testAppliesCorrectFinalCodesWhenCodeMatchAndNameFallbackCollide(): void
    {
        $sourceUser = UserBuilder::aUser()->withIndex(501)->build();
        $targetUser = UserBuilder::aUser()->withIndex(502)->build();
        $sourceCompany = CompanyBuilder::aCompany()->withIndex(501)->withOwner($sourceUser)->build();
        $targetCompany = CompanyBuilder::aCompany()->withIndex(502)->withOwner($targetUser)->build();

        // Target: единственный существующий узел с code='C'.
        $existingC = PLCategoryBuilder::aPLCategory()
            ->forCompany($targetCompany)
            ->withName('Совпадает по имени')
            ->withCode('C')
            ->build();

        // Source, в порядке sortOrder: сначала узел БЕЗ code, который матчится
        // на existingC по (parent, name); затем узел С code='C', который
        // забирает себе этот код (existingC уже занят первым узлом).
        $sourceNoCode = PLCategoryBuilder::aPLCategory()
            ->forCompany($sourceCompany)
            ->withName('Совпадает по имени')
            ->build();
        $sourceNoCode->setSortOrder(10);

        $sourceWithCode = PLCategoryBuilder::aPLCategory()
            ->forCompany($sourceCompany)
            ->withName('Новый узел')
            ->withCode('C')
            ->build();
        $sourceWithCode->setSortOrder(20);

        $this->em->persist($sourceUser);
        $this->em->persist($targetUser);
        $this->em->persist($sourceCompany);
        $this->em->persist($targetCompany);
        $this->em->persist($existingC);
        $this->em->persist($sourceNoCode);
        $this->em->persist($sourceWithCode);
        $this->em->flush();
        $this->em->clear();

        /** @var CompanyRepository $companyRepository */
        $companyRepository = self::getContainer()->get(CompanyRepository::class);
        /** @var PLCategoryRepository $plCategoryRepository */
        $plCategoryRepository = self::getContainer()->get(PLCategoryRepository::class);

        $action = new ImportPLCategoryTreeAction($companyRepository, $plCategoryRepository, $this->em);

        $result = $action(new ImportPLCategoryTreeCommand(
            (string) $sourceCompany->getId(),
            (string) $targetCompany->getId(),
            false,
        ));

        self::assertCount(1, $result->created);
        self::assertCount(1, $result->updated);

        $this->em->clear();

        $targetCategories = $plCategoryRepository->findRootByCompany($targetCompany);
        self::assertCount(2, $targetCategories);

        $byName = [];
        foreach ($targetCategories as $category) {
            $byName[$category->getName()] = $category;
        }

        self::assertArrayHasKey('Совпадает по имени', $byName);
        self::assertArrayHasKey('Новый узел', $byName);
        self::assertNull($byName['Совпадает по имени']->getCode());
        self::assertSame('C', $byName['Новый узел']->getCode());
    }
}
