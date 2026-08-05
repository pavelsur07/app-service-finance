<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Finance\Entity\PLCategory;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

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
}
