<?php

declare(strict_types=1);

namespace App\Tests\Functional\Finance;

use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\User;
use App\Finance\Entity\Document;
use App\Finance\Enum\DocumentStatus;
use App\Finance\Enum\DocumentType;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CompanyMemberBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Регрессия: после захода в документ журнал «Документы ОПиУ» открывался сброшенным —
 * меню, хлебные крошки, «Отмена» и переход после сохранения вели на голый /documents/.
 */
final class DocumentListResumeTest extends WebTestCaseBase
{
    public function testResumeReturnsToLastFiltersAndPage(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();
        for ($i = 0; $i < 21; ++$i) {
            $this->persistDocument($company);
        }
        $this->login($client, $user, $company);

        $client->request('GET', '/documents/', ['type' => 'PAYROLL', 'status' => 'ACTIVE']);
        $client->request('GET', '/documents/', ['type' => 'OTHER', 'page' => '2', 'limit' => '20', 'foo' => 'bar']);
        self::assertResponseIsSuccessful();

        $client->request('GET', '/documents/resume');

        self::assertResponseRedirects('/documents/?page=2&limit=20&type=OTHER');
    }

    public function testResumeReturnsToOperationsTabWhenItWasLast(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();
        $this->login($client, $user, $company);

        $client->request('GET', '/documents/', ['type' => 'PAYROLL']);
        $client->request('GET', '/documents/operations', ['direction' => 'income']);
        $client->request('GET', '/documents/resume');

        self::assertResponseRedirects('/documents/operations?direction=income');
    }

    public function testResetAllIsRememberedAsEmptyJournal(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();
        $this->login($client, $user, $company);

        $client->request('GET', '/documents/', ['type' => 'PAYROLL']);
        // «Сбросить всё» ведёт на сам список без параметров.
        $client->request('GET', '/documents/');
        $client->request('GET', '/documents/resume');

        self::assertResponseRedirects('/documents/');
    }

    public function testResumeWithoutSavedStateOpensJournal(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();
        $this->login($client, $user, $company);

        $client->request('GET', '/documents/resume');

        self::assertResponseRedirects('/documents/');
    }

    public function testFiltersOfAnotherCompanyAreNotApplied(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();
        $other = CompanyBuilder::aCompany()->withIndex(random_int(10000, 99999))->withOwner($user)->build();
        $this->em()->persist($other);
        $this->em()->flush();
        $this->login($client, $user, $company);

        $client->request('GET', '/documents/', ['type' => 'PAYROLL']);
        $this->setClientSessionValue($client, 'active_company_id', $other->getId());
        $client->request('GET', '/documents/resume');

        self::assertResponseRedirects('/documents/');
    }

    public function testColleagueInSameBrowserDoesNotGetPreviousUsersFilters(): void
    {
        $client = static::createClient();
        [$owner, $company] = $this->prepareCompanyContext();
        $colleague = UserBuilder::aUser()->withIndex(random_int(10000, 99999))->build();
        $member = CompanyMemberBuilder::aMember()->withId(Uuid::uuid4()->toString())->withCompany($company)->withUser($colleague)->withRole(CompanyMember::ROLE_OWNER)->build();
        $this->em()->persist($colleague);
        $this->em()->persist($member);
        $this->em()->flush();

        $this->login($client, $owner, $company);
        $client->request('GET', '/documents/', ['type' => 'PAYROLL']);

        // Тот же браузер и та же сессия, вошёл другой сотрудник той же компании.
        $client->loginUser($colleague);
        $client->request('GET', '/documents/resume');

        self::assertResponseRedirects('/documents/');
    }

    public function testStalePageFallsBackToFirstPageKeepingFilters(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();
        $this->persistDocument($company);
        $this->login($client, $user, $company);

        $client->request('GET', '/documents/', ['type' => 'OTHER', 'page' => '5']);
        self::assertResponseRedirects('/documents/?type=OTHER');

        $client->request('GET', '/documents/operations', ['type' => 'OTHER', 'page' => '5']);
        self::assertResponseRedirects('/documents/operations?type=OTHER');
    }

    public function testSavingDocumentReturnsToRememberedJournal(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->prepareCompanyContext();
        $document = $this->persistDocument($company);
        $this->login($client, $user, $company);

        $client->request('GET', '/documents/', ['type' => 'OTHER']);
        $crawler = $client->request('GET', sprintf('/documents/%s', $document->getId()));
        self::assertSame('/documents/resume', $crawler->filter('.breadcrumb a')->last()->attr('href'));

        $client->request('POST', sprintf('/documents/%s/delete', $document->getId()), [
            '_token' => $this->csrfToken($client, 'delete'.$document->getId()),
        ]);
        self::assertResponseRedirects('/documents/resume');
        $client->followRedirect();

        self::assertResponseRedirects('/documents/?type=OTHER');
    }

    private function login(KernelBrowser $client, User $user, Company $company): void
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
    }

    private function persistDocument(Company $company): Document
    {
        $document = new Document(Uuid::uuid4()->toString(), $company);
        $document->setDate(new \DateTimeImmutable('2024-05-15'));
        $document->setType(DocumentType::OTHER);
        $document->setStatus(DocumentStatus::ACTIVE);

        $this->em()->persist($document);
        $this->em()->flush();

        return $document;
    }

    /**
     * @return array{0: User, 1: Company}
     */
    private function prepareCompanyContext(): array
    {
        $user = UserBuilder::aUser()->withIndex(random_int(1000, 9999))->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(random_int(1000, 9999))->withOwner($user)->build();

        $this->em()->persist($user);
        $this->em()->persist($company);
        $this->em()->flush();

        return [$user, $company];
    }
}
