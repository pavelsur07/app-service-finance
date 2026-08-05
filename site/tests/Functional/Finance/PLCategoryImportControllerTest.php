<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Finance\Entity\PLCategory;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Component\HttpFoundation\File\UploadedFile;

final class PLCategoryImportControllerTest extends WebTestCaseBase
{
    public function testImportFormListsOnlyAccessibleCompaniesAndExcludesActiveOne(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $source = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->withName('Source Co')->build();
        $target = CompanyBuilder::aCompany()->withIndex(2)->withOwner($user)->withName('Target Co')->build();
        $foreignOwner = UserBuilder::aUser()->withIndex(2)->build();
        $foreign = CompanyBuilder::aCompany()->withIndex(3)->withOwner($foreignOwner)->withName('Foreign Co')->build();

        $em = $this->em();
        foreach ([$user, $source, $target, $foreignOwner, $foreign] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $target->getId());

        $crawler = $client->request('GET', '/pl-categories/import');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Source Co', $crawler->filter('select#sourceCompanyId')->text());
        self::assertStringNotContainsString('Target Co', $crawler->filter('select#sourceCompanyId')->text());
        self::assertStringNotContainsString('Foreign Co', $crawler->filter('select#sourceCompanyId')->text());
    }

    public function testPreviewIsForbiddenForInaccessibleSourceCompany(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $target = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $foreignOwner = UserBuilder::aUser()->withIndex(2)->build();
        $foreign = CompanyBuilder::aCompany()->withIndex(2)->withOwner($foreignOwner)->build();

        $em = $this->em();
        foreach ([$user, $target, $foreignOwner, $foreign] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $target->getId());

        $client->request('GET', '/pl-categories/import?sourceCompanyId='.$foreign->getId());

        self::assertResponseStatusCodeSame(403);
    }

    public function testPreviewShowsCreateAndUpdateCounts(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $source = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $target = CompanyBuilder::aCompany()->withIndex(2)->withOwner($user)->build();

        $sourceRoot = PLCategoryBuilder::aPLCategory()->forCompany($source)->withName('Расходы')->withCode('EXP')->build();
        $sourceChild = PLCategoryBuilder::aPLCategory()->forCompany($source)->withName('Реклама')->withParent($sourceRoot)->build();
        // Уже существует в target с тем же code, но другим именем — должна обновиться, не создаться заново.
        $existingRoot = PLCategoryBuilder::aPLCategory()->forCompany($target)->withName('Старое имя')->withCode('EXP')->build();

        $em = $this->em();
        foreach ([$user, $source, $target, $sourceRoot, $sourceChild, $existingRoot] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $target->getId());

        $crawler = $client->request('GET', '/pl-categories/import?sourceCompanyId='.$source->getId());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Расходы', $crawler->filter('table')->text());
        self::assertStringContainsString('Реклама', $crawler->filter('table')->text());
        self::assertSame('1', trim($crawler->filter('.h2')->eq(0)->text()));
        self::assertSame('1', trim($crawler->filter('.h2')->eq(1)->text()));
    }

    public function testApplyRequiresValidCsrfToken(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $source = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $target = CompanyBuilder::aCompany()->withIndex(2)->withOwner($user)->build();

        $em = $this->em();
        foreach ([$user, $source, $target] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $target->getId());

        $client->request('POST', '/pl-categories/import/apply', [
            'sourceCompanyId' => $source->getId(),
            '_token' => 'invalid-token',
        ]);

        self::assertResponseStatusCodeSame(403);
    }

    public function testApplyIsForbiddenForInaccessibleSourceCompanyEvenWithValidCsrf(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $target = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $foreignOwner = UserBuilder::aUser()->withIndex(2)->build();
        $foreign = CompanyBuilder::aCompany()->withIndex(2)->withOwner($foreignOwner)->build();
        $foreignCategory = PLCategoryBuilder::aPLCategory()->forCompany($foreign)->withName('Чужая категория')->build();

        $em = $this->em();
        foreach ([$user, $target, $foreignOwner, $foreign, $foreignCategory] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $target->getId());

        // GET не вызывался в этом тесте — валидный CSRF-токен получаем
        // напрямую, чтобы доказать, что именно POST-маршрут сам проверяет
        // доступ к source-компании, а не полагается на предыдущий GET.
        $token = $this->csrfToken($client, 'pl-category-import'.$target->getId());

        $client->request('POST', '/pl-categories/import/apply', [
            'sourceCompanyId' => (string) $foreign->getId(),
            '_token' => $token,
        ]);

        self::assertResponseStatusCodeSame(403);

        self::assertCount(0, $this->em()->getRepository(PLCategory::class)->findBy(['company' => $target]));
    }

    public function testApplyRejectsActiveCompanyAsItsOwnSource(): void
    {
        // Экран не показывает активную компанию в списке источников, но POST
        // приходит напрямую: перенос компании в саму себя матчил бы каждый узел
        // сам на себя. Проверка живёт в контроллере — Action про компанию-
        // источник больше не знает.
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $target = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $category = PLCategoryBuilder::aPLCategory()->forCompany($target)->withName('Расходы')->withCode('EXP')->build();

        $em = $this->em();
        foreach ([$user, $target, $category] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $target->getId());

        $targetId = (string) $target->getId();
        $client->request('POST', '/pl-categories/import/apply', [
            'sourceCompanyId' => $targetId,
            '_token' => $this->csrfToken($client, 'pl-category-import'.$targetId),
        ]);

        // Редирект на форму импорта (а не на список, как после успешного
        // применения) плюс точный текст ошибки: без guard'а запрос ушёл бы в
        // ветку успеха.
        self::assertResponseRedirects('/pl-categories/import');
        self::assertSame(
            ['Источник и целевая компания совпадают.'],
            $client->getRequest()->getSession()->getFlashBag()->peek('danger'),
        );

        $this->em()->clear();
        self::assertCount(1, $this->em()->getRepository(PLCategory::class)->findBy(['company' => $target]));
    }

    public function testApplyCreatesAndUpdatesTreeAndReimportIsIdempotent(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $source = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $target = CompanyBuilder::aCompany()->withIndex(2)->withOwner($user)->build();

        $sourceRoot = PLCategoryBuilder::aPLCategory()->forCompany($source)->withName('Расходы')->withCode('EXP')->build();
        $sourceChild = PLCategoryBuilder::aPLCategory()->forCompany($source)->withName('Реклама')->withParent($sourceRoot)->build();
        $existingRoot = PLCategoryBuilder::aPLCategory()->forCompany($target)->withName('Старое имя')->withCode('EXP')->build();
        $existingRootId = (string) $existingRoot->getId();

        $em = $this->em();
        foreach ([$user, $source, $target, $sourceRoot, $sourceChild, $existingRoot] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $target->getId());

        $targetId = (string) $target->getId();
        $token = $this->csrfToken($client, 'pl-category-import'.$targetId);

        $client->request('POST', '/pl-categories/import/apply', [
            'sourceCompanyId' => (string) $source->getId(),
            '_token' => $token,
        ]);

        self::assertResponseRedirects('/pl-categories/');

        $this->em()->clear();
        $imported = $this->em()->getRepository(PLCategory::class)->findBy(['company' => $target]);
        self::assertCount(2, $imported);

        $updatedRoot = $this->em()->getRepository(PLCategory::class)->find($existingRootId);
        self::assertInstanceOf(PLCategory::class, $updatedRoot);
        self::assertSame('Расходы', $updatedRoot->getName());

        // Повторный импорт того же источника — идемпотентен, дублей нет.
        $client->request('POST', '/pl-categories/import/apply', [
            'sourceCompanyId' => (string) $source->getId(),
            '_token' => $this->csrfToken($client, 'pl-category-import'.$targetId),
        ]);

        self::assertResponseRedirects('/pl-categories/');

        $this->em()->clear();
        $reimported = $this->em()->getRepository(PLCategory::class)->findBy(['company' => $target]);
        self::assertCount(2, $reimported);
    }

    public function testFileUploadShowsPreviewAndApplyImportsIntoActiveCompany(): void
    {
        // Сквозной сценарий задачи: файл выгружен в чужом аккаунте, у текущего
        // пользователя нет к той компании никакого доступа — перенос всё равно
        // обязан пройти.
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $target = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        // Уже есть в целевой компании с тем же кодом, но другим именем.
        $existingRoot = PLCategoryBuilder::aPLCategory()->forCompany($target)->withName('Старое имя')->withCode('EXP')->build();
        $existingRootId = (string) $existingRoot->getId();

        $em = $this->em();
        foreach ([$user, $target, $existingRoot] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $target->getId());

        $targetId = (string) $target->getId();
        $crawler = $client->request('POST', '/pl-categories/import/upload', [
            '_token' => $this->csrfToken($client, 'pl-category-import-file'.$targetId),
        ], ['import_file' => $this->uploadFile($this->exportFile([
            ['name' => 'Расходы', 'code' => 'EXP', 'children' => [
                ['name' => 'Реклама'],
            ]],
        ]))]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('categories.json', $crawler->filter('.card-title')->last()->text());
        self::assertSame('1', trim($crawler->filter('.h2')->eq(0)->text()), 'будет создано');
        self::assertSame('1', trim($crawler->filter('.h2')->eq(1)->text()), 'будет обновлено');

        $client->submitForm('Импортировать');

        self::assertResponseRedirects('/pl-categories/');

        $this->em()->clear();
        $imported = $this->em()->getRepository(PLCategory::class)->findBy(['company' => $target]);
        self::assertCount(2, $imported);

        $updatedRoot = $this->em()->getRepository(PLCategory::class)->find($existingRootId);
        self::assertInstanceOf(PLCategory::class, $updatedRoot);
        self::assertSame('Расходы', $updatedRoot->getName());
    }

    public function testReuploadingSameFileIsIdempotent(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $target = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();

        $em = $this->em();
        foreach ([$user, $target] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $target->getId());

        $targetId = (string) $target->getId();
        $json = $this->exportFile([
            ['name' => 'Расходы', 'code' => 'EXP', 'children' => [['name' => 'Реклама']]],
        ]);

        foreach ([1, 2] as $attempt) {
            $crawler = $client->request('POST', '/pl-categories/import/upload', [
                '_token' => $this->csrfToken($client, 'pl-category-import-file'.$targetId),
            ], ['import_file' => $this->uploadFile($json)]);

            self::assertResponseIsSuccessful();

            if (2 === $attempt) {
                // Второй заход: всё уже на месте, менять нечего.
                self::assertSame('0', trim($crawler->filter('.h2')->eq(0)->text()), 'будет создано');
                self::assertSame('0', trim($crawler->filter('.h2')->eq(1)->text()), 'будет обновлено');
                self::assertSame('2', trim($crawler->filter('.h2')->eq(2)->text()), 'без изменений');
            }

            $client->submitForm('Импортировать');

            self::assertResponseRedirects('/pl-categories/');
        }

        $this->em()->clear();
        self::assertCount(2, $this->em()->getRepository(PLCategory::class)->findBy(['company' => $target]));
    }

    public function testUploadRequiresValidCsrfToken(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $target = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();

        $em = $this->em();
        foreach ([$user, $target] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $target->getId());

        $client->request('POST', '/pl-categories/import/upload', [
            '_token' => 'invalid-token',
        ], ['import_file' => $this->uploadFile($this->exportFile([['name' => 'Расходы']]))]);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->em()->getRepository(PLCategory::class)->findBy(['company' => $target]));
    }

    public function testBrokenFileIsRejectedWithMessageAndChangesNothing(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $target = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();

        $em = $this->em();
        foreach ([$user, $target] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $target->getId());

        $targetId = (string) $target->getId();
        $client->request('POST', '/pl-categories/import/upload', [
            '_token' => $this->csrfToken($client, 'pl-category-import-file'.$targetId),
        ], ['import_file' => $this->uploadFile('{"version": 1, "categories": [{"name": "Расходы", "flow": "WRONG"}]}')]);

        self::assertResponseRedirects('/pl-categories/import');
        self::assertSame(
            ['Недопустимое значение "WRONG" в поле "flow" категории "Расходы". Допустимые значения: INCOME, EXPENSE, NONE.'],
            $client->getRequest()->getSession()->getFlashBag()->peek('danger'),
        );

        $this->em()->clear();
        self::assertCount(0, $this->em()->getRepository(PLCategory::class)->findBy(['company' => $target]));
    }

    public function testApplyFromFileWithoutPreviewPayloadIsRejected(): void
    {
        // Применяется ровно то, что показано на странице предпросмотра.
        // Запрос без payload применять нечему.
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $target = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();

        $em = $this->em();
        foreach ([$user, $target] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $target->getId());

        $targetId = (string) $target->getId();
        $client->request('POST', '/pl-categories/import/apply', [
            'mode' => 'file',
            '_token' => $this->csrfToken($client, 'pl-category-import'.$targetId),
        ]);

        self::assertResponseRedirects('/pl-categories/import');
        self::assertSame(
            ['Данные предпросмотра не получены — загрузите файл заново.'],
            $client->getRequest()->getSession()->getFlashBag()->peek('danger'),
        );

        $this->em()->clear();
        self::assertCount(0, $this->em()->getRepository(PLCategory::class)->findBy(['company' => $target]));
    }

    public function testPreviewWarnsAboutFormulaCodesMissingInTargetCompany(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $target = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();

        $em = $this->em();
        foreach ([$user, $target] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $target->getId());

        $targetId = (string) $target->getId();
        $crawler = $client->request('POST', '/pl-categories/import/upload', [
            '_token' => $this->csrfToken($client, 'pl-category-import-file'.$targetId),
        ], ['import_file' => $this->uploadFile($this->exportFile([
            ['name' => 'Выручка', 'code' => 'REVENUE'],
            ['name' => 'Маржа', 'code' => 'MARGIN', 'type' => 'KPI', 'formula' => 'REVENUE - OLD_METRIC'],
        ]))]);

        self::assertResponseIsSuccessful();
        $warning = $crawler->filter('.alert-warning')->text();
        self::assertStringContainsString('OLD_METRIC', $warning);
        self::assertStringNotContainsString('REVENUE', $warning);
    }

    public function testAppliesExactlyThePreviewThatWasShownWhenTwoFilesArePreviewed(): void
    {
        // Две вкладки одной компании: в первой предпросмотрен файл A, во второй
        // — B. Применение из первой вкладки обязано импортировать A. Общий на
        // всю сессию слот подменил бы его файлом B, и пользователь получил бы
        // в справочник ОПиУ не то, что видел на экране.
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $target = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();

        $em = $this->em();
        foreach ([$user, $target] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $target->getId());

        $targetId = (string) $target->getId();

        $tabA = $client->request('POST', '/pl-categories/import/upload', [
            '_token' => $this->csrfToken($client, 'pl-category-import-file'.$targetId),
        ], ['import_file' => $this->uploadFile($this->exportFile([['name' => 'Из файла A', 'code' => 'AAA']]))]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/pl-categories/import/upload', [
            '_token' => $this->csrfToken($client, 'pl-category-import-file'.$targetId),
        ], ['import_file' => $this->uploadFile($this->exportFile([['name' => 'Из файла B', 'code' => 'BBB']]))]);
        self::assertResponseIsSuccessful();

        $client->submit($tabA->selectButton('Импортировать')->form());

        self::assertResponseRedirects('/pl-categories/');

        $this->em()->clear();
        $imported = $this->em()->getRepository(PLCategory::class)->findBy(['company' => $target]);
        self::assertCount(1, $imported);
        self::assertSame('Из файла A', $imported[0]->getName());
    }

    public function testApplyReportsUnresolvedFormulaCodesAfterImport(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $target = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();

        $em = $this->em();
        foreach ([$user, $target] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $target->getId());

        $targetId = (string) $target->getId();
        $client->request('POST', '/pl-categories/import/upload', [
            '_token' => $this->csrfToken($client, 'pl-category-import-file'.$targetId),
        ], ['import_file' => $this->uploadFile($this->exportFile([
            ['name' => 'Маржа', 'code' => 'MARGIN', 'type' => 'KPI', 'formula' => 'OLD_METRIC * 2'],
        ]))]);

        $client->submitForm('Импортировать');

        self::assertResponseRedirects('/pl-categories/');
        $flashes = $client->getRequest()->getSession()->getFlashBag()->peek('warning');
        self::assertCount(1, $flashes);
        self::assertStringContainsString('OLD_METRIC', $flashes[0]);
    }

    public function testExportedFileFromOneAccountImportsIntoAnother(): void
    {
        // Приёмочный сценарий задачи целиком, без подделки содержимого файла:
        // байты берутся из настоящего эндпоинта выгрузки одного пользователя и
        // загружаются другим пользователем, у которого нет и не может быть
        // доступа к компании-источнику.
        $client = static::createClient();
        $this->resetDb();

        $sourceUser = UserBuilder::aUser()->asCompanyOwner()->build();
        $sourceCompany = CompanyBuilder::aCompany()->withIndex(1)->withOwner($sourceUser)->withName('Компания А')->build();
        $targetUser = UserBuilder::aUser()->withIndex(2)->asCompanyOwner()->build();
        $targetCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($targetUser)->withName('Компания Б')->build();

        $root = PLCategoryBuilder::aPLCategory()->forCompany($sourceCompany)->withName('Расходы')->withCode('EXP')->build();
        $root->setSortOrder(10);
        $child = PLCategoryBuilder::aPLCategory()->forCompany($sourceCompany)->withName('Реклама')->withParent($root)->build();
        $child->setWeightInParent('-0.2500');
        $child->setIsVisible(false);
        $child->setSortOrder(20);

        $em = $this->em();
        foreach ([$sourceUser, $sourceCompany, $targetUser, $targetCompany, $root, $child] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        // Аккаунт 1: выгрузка.
        $client->loginUser($sourceUser);
        $this->setClientSessionValue($client, 'active_company_id', $sourceCompany->getId());
        $client->request('GET', '/pl-categories/export/json');
        self::assertResponseIsSuccessful();
        $downloaded = (string) $client->getResponse()->getContent();

        // Аккаунт 2: загрузка ровно тех же байтов.
        $client->loginUser($targetUser);
        $this->setClientSessionValue($client, 'active_company_id', $targetCompany->getId());

        $client->request('POST', '/pl-categories/import/upload', [
            '_token' => $this->csrfToken($client, 'pl-category-import-file'.(string) $targetCompany->getId()),
        ], ['import_file' => $this->uploadFile($downloaded)]);

        self::assertResponseIsSuccessful();
        $client->submitForm('Импортировать');
        self::assertResponseRedirects('/pl-categories/');

        $this->em()->clear();
        $imported = $this->em()->getRepository(PLCategory::class)->findBy(['company' => $targetCompany], ['sortOrder' => 'ASC']);
        self::assertCount(2, $imported);

        self::assertSame('Расходы', $imported[0]->getName());
        self::assertSame('EXP', $imported[0]->getCode());
        self::assertNull($imported[0]->getParent());

        self::assertSame('Реклама', $imported[1]->getName());
        self::assertSame($imported[0]->getId(), $imported[1]->getParent()?->getId());
        self::assertSame('-0.2500', $imported[1]->getWeightInParent());
        self::assertFalse($imported[1]->isVisible());
        self::assertSame(20, $imported[1]->getSortOrder());

        // Дерево источника не тронуто.
        self::assertCount(2, $this->em()->getRepository(PLCategory::class)->findBy(['company' => $sourceCompany]));
    }

    /**
     * @param list<array<string, mixed>> $categories
     */
    private function exportFile(array $categories): string
    {
        return json_encode([
            'version' => 1,
            'exportedAt' => '2026-08-05T10:00:00+03:00',
            'company' => 'Компания из другого аккаунта',
            'categories' => $categories,
        ], \JSON_THROW_ON_ERROR);
    }

    private function uploadFile(string $json): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pl-cat-').'.json';
        file_put_contents($path, $json);

        return new UploadedFile($path, 'pl-categories.json', 'application/json', null, true);
    }
}
