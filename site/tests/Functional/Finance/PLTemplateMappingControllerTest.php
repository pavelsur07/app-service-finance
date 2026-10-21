<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\CompanyRole;
use App\Company\Entity\User;
use App\Company\Enum\BusinessProfile;
use App\Company\Security\SystemCompanyRoles;
use App\Finance\Application\Action\EnsureDefaultPLTreeAction;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLCategoryType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Builders\Finance\PLTemplateApplicationBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class PLTemplateMappingControllerTest extends WebTestCaseBase
{
    public function testPageSuggestsSimilarOwnLineAndSaveBindsIt(): void
    {
        $client = static::createClient();
        [$user, $company, $net] = $this->ownTree();
        $this->login($client, $user, $company);

        $crawler = $client->request('GET', '/pl-categories/import/template-mapping');
        self::assertResponseIsSuccessful();
        self::assertSame((string) $net->getId(), $crawler->filter('select[name="map[NET_PROFIT]"] option[selected]')->attr('value'));

        $client->request('POST', '/pl-categories/import/template-mapping', [
            'map' => ['NET_PROFIT' => (string) $net->getId(), 'COGS_TOTAL' => ''],
            '_token' => $this->csrfToken($client, 'pl-template-mapping'.(string) $company->getId()),
        ]);
        self::assertResponseRedirects('/pl-categories/import?source=template');

        self::assertSame(['NET_PROFIT'], $this->codes($company));
    }

    public function testSaveRequiresCsrfToken(): void
    {
        $client = static::createClient();
        [$user, $company, $net] = $this->ownTree();
        $this->login($client, $user, $company);

        $client->request('POST', '/pl-categories/import/template-mapping', ['map' => ['NET_PROFIT' => (string) $net->getId()], '_token' => 'bad']);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->codes($company));
    }

    public function testForeignCategoryIsRejected(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->ownTree();
        $other = CompanyBuilder::aCompany()->withIndex(2)->withOwner($user)->build();
        $foreign = PLCategoryBuilder::aPLCategory()->forCompany($other)->withName('Чистая прибыль')->withCode(null)->withType(PLCategoryType::KPI)->build();
        $this->save($other, $foreign);
        $this->login($client, $user, $company);

        $client->request('POST', '/pl-categories/import/template-mapping', [
            'map' => ['NET_PROFIT' => (string) $foreign->getId()],
            '_token' => $this->csrfToken($client, 'pl-template-mapping'.(string) $company->getId()),
        ]);

        self::assertResponseRedirects('/pl-categories/import/template-mapping');
        $client->followRedirect();
        self::assertSelectorTextContains('.toast.text-bg-danger', 'не найдена у компании');
        self::assertNull($this->em()->getConnection()->fetchOne('SELECT code FROM pl_categories WHERE id = ?', [(string) $foreign->getId()]));
    }

    public function testMemberWithoutFinanceWriteCannotSave(): void
    {
        $client = static::createClient();
        [, $company, $net] = $this->ownTree();
        $role = $this->em()->find(CompanyRole::class, SystemCompanyRoles::MARKETPLACE_ID);
        self::assertInstanceOf(CompanyRole::class, $role);
        $member = UserBuilder::aUser()->withIndex(2)->withEmail('mapping-member@example.test')->withRoles(['ROLE_COMPANY_USER'])->build();
        $this->save($member, CompanyMemberBuilder::aMember()->withCompany($company)->withUser($member)->withRole(CompanyMember::ROLE_OPERATOR)->withAccessRole($role)->build());
        $this->login($client, $member, $company);

        $client->request('POST', '/pl-categories/import/template-mapping', [
            'map' => ['NET_PROFIT' => (string) $net->getId()],
            '_token' => $this->csrfToken($client, 'pl-template-mapping'.(string) $company->getId()),
        ]);

        self::assertResponseStatusCodeSame(403);
        self::assertSame([], $this->codes($company));
    }

    public function testTemplatePreviewOnOwnTreeRefusesWithoutApplyButton(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->ownTree();
        $this->login($client, $user, $company);

        $client->request('GET', '/pl-categories/import?source=template');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.toast.text-bg-danger', 'Шаблон опознал 0 из 3 корневых статей');
        self::assertSelectorNotExists('input[name="mode"][value="template"]');
        self::assertSelectorExists('a[href="/pl-categories/import/template-mapping"]');
    }

    public function testTemplatePreviewListsSimilarLinesThatWillNotBeCreated(): void
    {
        $client = static::createClient();
        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $this->save($user, $company);
        self::assertSame(72, (static::getContainer()->get(EnsureDefaultPLTreeAction::class))((string) $company->getId()));
        $connection = $this->em()->getConnection();
        $connection->delete('pl_categories', ['company_id' => (string) $company->getId(), 'code' => 'BELOW_EBITDA_PROFIT_TAX']);
        $connection->update('pl_categories', ['code' => null, 'name' => 'Налог на прибыль / УСН'], ['company_id' => (string) $company->getId(), 'code' => 'BELOW_EBITDA_USN']);
        $this->em()->clear();
        $this->login($client, $user, $company);

        $client->request('GET', '/pl-categories/import?source=template');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-warning', 'у вас «Налог на прибыль / УСН»');
        self::assertSelectorTextContains('.alert-warning', 'BELOW_EBITDA_PROFIT_TAX');
    }

    public function testScreenApplicationRecordsOriginAndScreenShowsIt(): void
    {
        $client = static::createClient();
        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $this->save($user, $company);
        $this->login($client, $user, $company);

        $crawler = $client->request('GET', '/pl-categories/import');
        self::assertStringContainsString('Происхождение дерева не записано', $crawler->filter('[data-template-origin]')->text());

        $client->request('POST', '/pl-categories/import/apply', ['mode' => 'template', '_token' => $this->csrfToken($client, 'pl-category-import'.(string) $company->getId())]);
        self::assertResponseRedirects('/pl-categories/');
        self::assertSame(['screen'], $this->em()->getConnection()->fetchFirstColumn('SELECT source FROM pl_template_applications WHERE company_id = ?', [(string) $company->getId()]));

        $crawler = $client->request('GET', '/pl-categories/import');
        self::assertStringContainsString('Дерево создано шаблоном «Маркетплейсы и e-com», версия 2', $crawler->filter('[data-template-origin]')->text());
    }

    public function testTemplatePreviewOfAnotherProfileIsRefused(): void
    {
        $client = static::createClient();
        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $company->setBusinessProfile(BusinessProfile::UNIVERSAL);
        $this->save($user, $company, PLTemplateApplicationBuilder::anApplication()->forCompany((string) $company->getId())->build());
        $this->login($client, $user, $company);

        $client->request('GET', '/pl-categories/import?source=template');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.toast.text-bg-danger', 'шаблон «Базовый» к нему не применяется');
        self::assertSelectorNotExists('input[name="mode"][value="template"]');
    }

    /**
     * Своё дерево без кодов: «Выручка», «Себестоимость», «Чистая прибыль».
     *
     * @return array{User, Company, PLCategory}
     */
    private function ownTree(): array
    {
        $user = UserBuilder::aUser()->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $net = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Чистая прибыль')->withCode(null)->withType(PLCategoryType::KPI)->build();
        $this->save(
            $user,
            $company,
            PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Выручка')->withCode(null)->withType(PLCategoryType::SUBTOTAL)->build(),
            PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Себестоимость')->withCode(null)->withType(PLCategoryType::SUBTOTAL)->build(),
            $net,
        );

        return [$user, $company, $net];
    }

    private function save(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();
    }

    private function login(KernelBrowser $client, User $user, Company $company): void
    {
        $this->em()->clear();
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }

    /**
     * @return list<string>
     */
    private function codes(Company $company): array
    {
        return $this->em()->getConnection()->fetchFirstColumn('SELECT code FROM pl_categories WHERE company_id = ? AND code IS NOT NULL ORDER BY code', [(string) $company->getId()]);
    }
}
