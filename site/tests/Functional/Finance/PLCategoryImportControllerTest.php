<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLFlow;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;

final class PLCategoryImportControllerTest extends WebTestCaseBase
{
    public function testImportFormListsOnlyAccessibleCompaniesAndExcludesActiveOne(): void
    {
        $client = static::createClient();

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
        // Без обновления новая статья встаёт под совпавшую статью компании — путь компании.
        self::assertStringContainsString('Старое имя / Реклама', $crawler->filter('table')->text());
        self::assertSame('1', trim($crawler->filter('.h2')->eq(0)->text()), 'будет создано');
        self::assertSame('1', trim($crawler->filter('.h2')->eq(1)->text()), 'отличаются у существующих');

        // Что именно изменится у совпавшей статьи — поле, было и станет; галка обновления снята.
        $nameChange = $crawler->filter('[data-import-change="name"]');
        self::assertCount(1, $nameChange);
        self::assertStringContainsString('Старое имя', $nameChange->text());
        self::assertStringContainsString('Расходы', $nameChange->text());
        self::assertCount(1, $crawler->filter('input[name="updateExisting"]:not([checked])'));
    }

    public function testApplyRequiresValidCsrfToken(): void
    {
        $client = static::createClient();

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

        $crawler = $client->request('GET', '/pl-categories/import?sourceCompanyId='.$source->getId());
        $client->submit($crawler->selectButton('Импортировать')->form(['updateExisting' => '1']));

        self::assertResponseRedirects('/pl-categories/');

        $this->em()->clear();
        $imported = $this->em()->getRepository(PLCategory::class)->findBy(['company' => $target]);
        self::assertCount(2, $imported);

        $updatedRoot = $this->em()->getRepository(PLCategory::class)->find($existingRootId);
        self::assertInstanceOf(PLCategory::class, $updatedRoot);
        self::assertSame('Расходы', $updatedRoot->getName());

        // Повторный импорт того же источника — идемпотентен, дублей нет.
        $crawler = $client->request('GET', '/pl-categories/import?sourceCompanyId='.$source->getId());
        self::assertCount(0, $crawler->filter('input[name="updateExisting"]'), 'отличий больше нет');
        $client->submit($crawler->selectButton('Импортировать')->form());

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
            $this->category('Расходы', ['code' => 'EXP', 'children' => [
                $this->category('Реклама'),
            ]]),
        ]))]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('categories.json', $crawler->filter('.card-title')->last()->text());
        self::assertSame('1', trim($crawler->filter('.h2')->eq(0)->text()), 'будет создано');
        self::assertSame('1', trim($crawler->filter('.h2')->eq(1)->text()), 'отличаются у существующих');

        $client->submit($crawler->selectButton('Импортировать')->form(['updateExisting' => '1']));

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
            $this->category('Расходы', ['code' => 'EXP', 'children' => [$this->category('Реклама')]]),
        ]);

        foreach ([1, 2] as $attempt) {
            $crawler = $client->request('POST', '/pl-categories/import/upload', [
                '_token' => $this->csrfToken($client, 'pl-category-import-file'.$targetId),
            ], ['import_file' => $this->uploadFile($json)]);

            self::assertResponseIsSuccessful();

            if (2 === $attempt) {
                // Второй заход: всё уже на месте, менять нечего.
                self::assertSame('0', trim($crawler->filter('.h2')->eq(0)->text()), 'будет создано');
                self::assertSame('0', trim($crawler->filter('.h2')->eq(1)->text()), 'отличаются у существующих');
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
        ], ['import_file' => $this->uploadFile($this->exportFile([$this->category('Расходы')]))]);

        self::assertResponseStatusCodeSame(403);
        self::assertCount(0, $this->em()->getRepository(PLCategory::class)->findBy(['company' => $target]));
    }

    public function testBrokenFileIsRejectedWithMessageAndChangesNothing(): void
    {
        $client = static::createClient();

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
        ], ['import_file' => $this->uploadFile($this->exportFile([$this->category('Расходы', ['flow' => 'WRONG'])]))]);

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
            $this->category('Выручка', ['code' => 'REVENUE']),
            $this->category('Маржа', ['code' => 'MARGIN', 'type' => 'KPI', 'formula' => 'REVENUE - OLD_METRIC']),
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
        ], ['import_file' => $this->uploadFile($this->exportFile([$this->category('Из файла A', ['code' => 'AAA'])]))]);
        self::assertResponseIsSuccessful();

        $client->request('POST', '/pl-categories/import/upload', [
            '_token' => $this->csrfToken($client, 'pl-category-import-file'.$targetId),
        ], ['import_file' => $this->uploadFile($this->exportFile([$this->category('Из файла B', ['code' => 'BBB'])]))]);
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
            $this->category('Маржа', ['code' => 'MARGIN', 'type' => 'KPI', 'formula' => 'OLD_METRIC * 2']),
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

    public function testWarnsWhenImportBreaksFormulaOfCategoryAbsentFromFile(): void
    {
        // Тот же сценарий, что в unit-тесте Action, но на реальной БД: сюда
        // попадает настоящий запрос за формулами компании.
        $client = static::createClient();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $target = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();

        // Категория с кодом OLD: импорт совпадёт с ней по имени и обнулит код.
        $withCode = PLCategoryBuilder::aPLCategory()->forCompany($target)->withName('Расходы')->withCode('OLD')->build();
        // Категория с формулой на OLD — в переносимом файле её нет вовсе.
        $withFormula = PLCategoryBuilder::aPLCategory()->forCompany($target)->withName('Маржа')->withCode('MARGIN')->build();
        $withFormula->setFormula('OLD * 2');

        $em = $this->em();
        foreach ([$user, $target, $withCode, $withFormula] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $target->getId());

        $targetId = (string) $target->getId();
        $crawler = $client->request('POST', '/pl-categories/import/upload', [
            '_token' => $this->csrfToken($client, 'pl-category-import-file'.$targetId),
        ], ['import_file' => $this->uploadFile($this->exportFile([$this->category('Расходы')]))]);

        self::assertResponseIsSuccessful();
        $warning = $crawler->filter('.alert-warning')->text();
        self::assertStringContainsString('OLD', $warning);
        // Формулировка не должна привязывать находку к переносимым категориям:
        // сломанная формула живёт у категории, которой в файле нет.
        self::assertStringContainsString('формулы категорий, которых в источнике нет', $warning);

        // Код освобождает только обновление совпавшей статьи — его и отмечаем.
        $client->submit($crawler->selectButton('Импортировать')->form(['updateExisting' => '1']));

        self::assertResponseRedirects('/pl-categories/');
        $flashes = $client->getRequest()->getSession()->getFlashBag()->peek('warning');
        self::assertCount(1, $flashes);
        self::assertStringContainsString('OLD', $flashes[0]);
        self::assertStringContainsString('формулы категорий, которых в файле не было', $flashes[0]);
    }

    public function testFileImportByDefaultOnlyAddsMissingCategories(): void
    {
        $client = static::createClient();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $target = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $existingRoot = PLCategoryBuilder::aPLCategory()->forCompany($target)->withName('Старое имя')->withCode('EXP')->build();
        $existingRootId = (string) $existingRoot->getId();

        $em = $this->em();
        foreach ([$user, $target, $existingRoot] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $target->getId());

        $crawler = $client->request('POST', '/pl-categories/import/upload', [
            '_token' => $this->csrfToken($client, 'pl-category-import-file'.(string) $target->getId()),
        ], ['import_file' => $this->uploadFile($this->exportFile([
            $this->category('Расходы', ['code' => 'EXP', 'flow' => 'INCOME', 'children' => [$this->category('Реклама')]]),
        ]))]);

        self::assertResponseIsSuccessful();
        // Смена движения меняет отчёт — помечена, смена имени — нет.
        self::assertCount(1, $crawler->filter('[data-import-change="flow"] .badge'));
        self::assertCount(0, $crawler->filter('[data-import-change="name"] .badge'));

        $client->submitForm('Импортировать');

        self::assertResponseRedirects('/pl-categories/');

        $this->em()->clear();
        self::assertCount(2, $this->em()->getRepository(PLCategory::class)->findBy(['company' => $target]));
        $root = $this->em()->getRepository(PLCategory::class)->find($existingRootId);
        self::assertInstanceOf(PLCategory::class, $root);
        self::assertSame('Старое имя', $root->getName(), 'без отметки существующая статья не меняется');
        self::assertSame(PLFlow::EXPENSE, $root->getFlow());
        $child = $this->em()->getRepository(PLCategory::class)->findOneBy(['company' => $target, 'name' => 'Реклама']);
        self::assertInstanceOf(PLCategory::class, $child);
        self::assertSame($existingRootId, (string) $child->getParent()?->getId(), 'новая статья встаёт под совпавшую');
    }

    public function testWhenOnlyAddingIsImpossibleScreenOffersImportWithUpdate(): void
    {
        // У компании EXP — строка ввода, в файле — группа с новой статьёй: без обновления
        // статья встала бы под строку, которая потомков не суммирует. С обновлением EXP
        // становится группой — этот режим экран и предлагает, вместо отказа целиком.
        $client = static::createClient();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $target = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $existingRoot = PLCategoryBuilder::aPLCategory()->forCompany($target)->withName('Расходы')->withCode('EXP')->build();
        $existingRoot->setType(PLCategoryType::LEAF_INPUT);
        $existingRootId = (string) $existingRoot->getId();

        $em = $this->em();
        foreach ([$user, $target, $existingRoot] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $target->getId());

        $crawler = $client->request('POST', '/pl-categories/import/upload', [
            '_token' => $this->csrfToken($client, 'pl-category-import-file'.(string) $target->getId()),
        ], ['import_file' => $this->uploadFile($this->exportFile([
            $this->category('Расходы', ['code' => 'EXP', 'type' => 'SUBTOTAL', 'children' => [$this->category('Реклама')]]),
        ]))]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('считается не суммой вложенных статей', $crawler->filter('[data-import-keep-error]')->text());
        self::assertCount(1, $crawler->filter('[data-import-change="type"]'));
        self::assertCount(0, $crawler->filter('input[type="checkbox"][name="updateExisting"]'));

        $client->submitForm('Импортировать');

        self::assertResponseRedirects('/pl-categories/');
        $this->em()->clear();
        $root = $this->em()->getRepository(PLCategory::class)->find($existingRootId);
        self::assertInstanceOf(PLCategory::class, $root);
        self::assertSame(PLCategoryType::SUBTOTAL, $root->getType());
        self::assertCount(2, $this->em()->getRepository(PLCategory::class)->findBy(['company' => $target]));
    }

    public function testApplyRefusesWhenCategoriesChangedAfterPreview(): void
    {
        $client = static::createClient();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $target = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $existingRoot = PLCategoryBuilder::aPLCategory()->forCompany($target)->withName('Старое имя')->withCode('EXP')->build();
        $existingRootId = (string) $existingRoot->getId();

        $em = $this->em();
        foreach ([$user, $target, $existingRoot] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $target->getId());

        $crawler = $client->request('POST', '/pl-categories/import/upload', [
            '_token' => $this->csrfToken($client, 'pl-category-import-file'.(string) $target->getId()),
        ], ['import_file' => $this->uploadFile($this->exportFile([
            $this->category('Расходы', ['code' => 'EXP', 'children' => [$this->category('Реклама')]]),
        ]))]);
        self::assertResponseIsSuccessful();

        // Пока предпросмотр открыт, статью правят в соседней вкладке.
        $this->em()->getConnection()->executeStatement('UPDATE pl_categories SET name = ? WHERE id = ?', ['Правка коллеги', $existingRootId]);

        $client->submit($crawler->selectButton('Импортировать')->form(['updateExisting' => '1']));

        self::assertResponseRedirects('/pl-categories/import');
        $flashes = $this->flashes($client, 'danger');
        self::assertCount(1, $flashes);
        self::assertStringContainsString('откройте предпросмотр заново', $flashes[0]);

        $this->em()->clear();
        self::assertCount(1, $this->em()->getRepository(PLCategory::class)->findBy(['company' => $target]), 'ничего не создано');
        $root = $this->em()->getRepository(PLCategory::class)->find($existingRootId);
        self::assertInstanceOf(PLCategory::class, $root);
        self::assertSame('Правка коллеги', $root->getName(), 'правка коллеги не перетёрта');
    }

    public function testApplyFromCompanyWithoutPreviewFingerprintIsRejected(): void
    {
        $client = static::createClient();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $source = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $target = CompanyBuilder::aCompany()->withIndex(2)->withOwner($user)->build();
        $sourceRoot = PLCategoryBuilder::aPLCategory()->forCompany($source)->withName('Расходы')->withCode('EXP')->build();

        $em = $this->em();
        foreach ([$user, $source, $target, $sourceRoot] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $target->getId());

        $targetId = (string) $target->getId();
        $client->request('POST', '/pl-categories/import/apply', [
            'sourceCompanyId' => (string) $source->getId(),
            '_token' => $this->csrfToken($client, 'pl-category-import'.$targetId),
        ]);

        self::assertResponseRedirects('/pl-categories/import');
        self::assertSame(
            ['Данные предпросмотра не получены — откройте предпросмотр заново.'],
            $this->flashes($client, 'danger'),
        );

        $this->em()->clear();
        self::assertCount(0, $this->em()->getRepository(PLCategory::class)->findBy(['company' => $target]));
    }

    /**
     * Категория со всеми полями формата v1: неполный файл читатель отвергает,
     * потому что импорт перезаписывает поля целиком.
     *
     * @param array<string, mixed> $overrides
     *
     * @return array<string, mixed>
     */
    private function category(string $name, array $overrides = []): array
    {
        return $overrides + [
            'name' => $name,
            'code' => null,
            'type' => 'LEAF_INPUT',
            'format' => 'MONEY',
            'flow' => 'NONE',
            'expenseType' => 'other',
            'weightInParent' => '1.0000',
            'isVisible' => true,
            'formula' => null,
            'calcOrder' => null,
            'sortOrder' => 0,
        ];
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

    /**
     * @return array<mixed>
     */
    private function flashes(KernelBrowser $client, string $type): array
    {
        $session = $client->getRequest()->getSession();
        self::assertInstanceOf(FlashBagAwareSessionInterface::class, $session);

        return $session->getFlashBag()->peek($type);
    }

    private function uploadFile(string $json): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'pl-cat-').'.json';
        file_put_contents($path, $json);

        return new UploadedFile($path, 'pl-categories.json', 'application/json', null, true);
    }

    public function testDefaultTemplatePreviewThenApplyAddsOnlyMissingCategories(): void
    {
        $client = static::createClient();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $target = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        // Своя статья компании с кодом шаблона: шаблон её не переименовывает и не переносит,
        // а вешает под неё недостающие статьи своей ветки.
        $ownStorage = PLCategoryBuilder::aPLCategory()->forCompany($target)->withName('Склад и удержания (своё)')->withCode('OPEX_WH_TOTAL')->withType(PLCategoryType::SUBTOTAL)->build();
        $ownStorageId = (string) $ownStorage->getId();

        $em = $this->em();
        foreach ([$user, $target, $ownStorage] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $target->getId());

        $crawler = $client->request('GET', '/pl-categories/import?source=template');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h3.card-title', 'Базовый шаблон ОПиУ');
        self::assertCount(1, $crawler->filter('form[action="/pl-categories/import/apply"] input[name="mode"][value="template"]'));
        self::assertSelectorNotExists('.badge.bg-blue-lt');

        $targetId = (string) $target->getId();
        $client->request('POST', '/pl-categories/import/apply', [
            'mode' => 'template',
            '_token' => $this->csrfToken($client, 'pl-category-import'.$targetId),
        ]);
        self::assertResponseRedirects('/pl-categories/');

        $this->em()->clear();
        $repository = $this->em()->getRepository(PLCategory::class);
        $all = $repository->findBy(['company' => $target]);
        self::assertCount(72, $all);

        $kept = $repository->find($ownStorageId);
        self::assertInstanceOf(PLCategory::class, $kept);
        self::assertSame('Склад и удержания (своё)', $kept->getName());
        self::assertSame(PLCategoryType::SUBTOTAL, $kept->getType());
        self::assertNull($kept->getParent());

        $storage = $repository->findOneBy(['company' => $target, 'code' => 'OPEX_WH_STORAGE']);
        self::assertInstanceOf(PLCategory::class, $storage);
        self::assertSame($ownStorageId, (string) $storage->getParent()?->getId());

        // Повторный перенос шаблона ничего не добавляет.
        $client->request('POST', '/pl-categories/import/apply', [
            'mode' => 'template',
            '_token' => $this->csrfToken($client, 'pl-category-import'.$targetId),
        ]);
        self::assertResponseRedirects('/pl-categories/');
        $this->em()->clear();
        self::assertCount(72, $this->em()->getRepository(PLCategory::class)->findBy(['company' => $target]));
    }

    /**
     * @return iterable<string, array{PLCategoryType, ?string}>
     */
    public static function nonSummingParents(): iterable
    {
        yield 'строка ввода' => [PLCategoryType::LEAF_INPUT, null];
        yield 'KPI' => [PLCategoryType::KPI, '0'];
        yield 'подытог с формулой' => [PLCategoryType::SUBTOTAL, 'CM2 * 0'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonSummingParents')]
    public function testDefaultTemplateRefusesToHangLinesUnderANonSummingCompanyLine(PLCategoryType $type, ?string $formula): void
    {
        $client = static::createClient();

        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $target = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        // У компании «склад» считается не суммой потомков (строка ввода, KPI или подытог
        // с формулой): статьи шаблона под ней молча выпали бы из CM3.
        $inputLine = PLCategoryBuilder::aPLCategory()->forCompany($target)->withName('Склад')->withCode('OPEX_WH_TOTAL')->withType($type)->build();
        $inputLine->setFormula($formula);

        $em = $this->em();
        foreach ([$user, $target, $inputLine] as $entity) {
            $em->persist($entity);
        }
        $em->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $target->getId());

        $client->request('GET', '/pl-categories/import?source=template');
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.toast.text-bg-danger', 'считается не суммой вложенных статей');
        self::assertSelectorNotExists('input[name="mode"][value="template"]');

        $client->request('POST', '/pl-categories/import/apply', [
            'mode' => 'template',
            '_token' => $this->csrfToken($client, 'pl-category-import'.(string) $target->getId()),
        ]);
        self::assertResponseRedirects('/pl-categories/import');

        $this->em()->clear();
        self::assertCount(1, $this->em()->getRepository(PLCategory::class)->findBy(['company' => $target]));
    }
}
