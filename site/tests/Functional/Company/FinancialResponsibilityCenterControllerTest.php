<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Company\Application\Service\CompanyOwnerMembershipCreator;
use App\Company\Entity\Company;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Company\Enum\FinancialResponsibilityCenterStatus;
use App\Company\Repository\FinancialResponsibilityCenterProjectRepository;
use App\Company\Repository\FinancialResponsibilityCenterRepository;
use App\Company\Repository\ProjectDirectionRepository;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class FinancialResponsibilityCenterControllerTest extends WebTestCaseBase
{
    public function testPageIsProtectedAndNavigationIsActive(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $client->request('GET', '/financial-responsibility-centers/');
        self::assertResponseRedirects('/login');

        [$owner, $company] = $this->createCompany($client, 801);
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', (string) $company->getId());

        $crawler = $client->request('GET', '/financial-responsibility-centers/');

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('h2.page-title', 'ЦФО');
        self::assertSelectorTextContains('table', FinancialResponsibilityCenter::NAME_GENERAL);
        self::assertCount(1, $crawler->filter('a.dropdown-item.active[href="/financial-responsibility-centers/"]'));
        self::assertCount(1, $crawler->filter('li.nav-item.dropdown.show a.nav-link.active'));
    }

    public function testCreatesEditsConfiguresAndArchivesCenter(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$owner, $company] = $this->createCompany($client, 802);
        $companyId = (string) $company->getId();
        $project = new ProjectDirection('33333333-3333-3333-3333-000000000802', $company, 'Продажи');
        $this->em()->persist($project);
        $this->em()->flush();
        /** @var ProjectDirectionRepository $projectRepository */
        $projectRepository = $client->getContainer()->get(ProjectDirectionRepository::class);
        self::assertCount(2, $projectRepository->findTreeByCompany($company));

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', $companyId);

        $crawler = $client->request('GET', '/financial-responsibility-centers/new');
        $client->submit($crawler->selectButton('Сохранить')->form([
            'financial_responsibility_center[name]' => 'Краснодар',
            'financial_responsibility_center[sort]' => '20',
        ]));
        self::assertResponseRedirects('/financial-responsibility-centers/');

        /** @var FinancialResponsibilityCenterRepository $centerRepository */
        $centerRepository = $client->getContainer()->get(FinancialResponsibilityCenterRepository::class);
        $center = $this->findCenterByName($centerRepository, $companyId, 'Краснодар');
        self::assertMatchesRegularExpression('/^CFO_[A-F0-9]{32}$/', $center->getCode());

        $crawler = $client->request('GET', sprintf('/financial-responsibility-centers/%s/edit', $center->getId()));
        $client->submit($crawler->selectButton('Сохранить')->form([
            'financial_responsibility_center[name]' => 'Ростов',
            'financial_responsibility_center[sort]' => '30',
        ]));
        self::assertResponseRedirects(sprintf('/financial-responsibility-centers/%s/edit', $center->getId()));

        $this->em()->clear();
        $centerRepository = $client->getContainer()->get(FinancialResponsibilityCenterRepository::class);
        $center = $centerRepository->findOneByIdAndCompanyId($center->getId(), $companyId);
        self::assertInstanceOf(FinancialResponsibilityCenter::class, $center);
        self::assertSame('Ростов', $center->getName());
        self::assertSame(2, $center->getVersion());

        $crawler = $client->request('GET', sprintf('/financial-responsibility-centers/%s/edit', $center->getId()));
        self::assertSelectorTextContains('body', 'Продажи');
        $projectsForm = $crawler->selectButton('Сохранить проекты')->form();
        $projectValues = $projectsForm->getPhpValues();
        $projectValues['financial_responsibility_center_projects']['projectDirectionIds'] = [(string) $project->getId()];
        $client->request($projectsForm->getMethod(), $projectsForm->getUri(), $projectValues);
        self::assertResponseRedirects(sprintf('/financial-responsibility-centers/%s/edit', $center->getId()));

        /** @var FinancialResponsibilityCenterProjectRepository $pairRepository */
        $pairRepository = $client->getContainer()->get(FinancialResponsibilityCenterProjectRepository::class);
        self::assertTrue($pairRepository->isAllowed($companyId, (string) $project->getId(), $center->getId()));

        $this->em()->clear();
        $centerRepository = $client->getContainer()->get(FinancialResponsibilityCenterRepository::class);
        $center = $centerRepository->findOneByIdAndCompanyId($center->getId(), $companyId);
        self::assertInstanceOf(FinancialResponsibilityCenter::class, $center);
        self::assertSame(3, $center->getVersion());

        $crawler = $client->request('GET', '/financial-responsibility-centers/');
        self::assertCount(1, $crawler->selectButton('Архивировать'));
        self::assertCount(1, $crawler->filter('form input[name="version"][value="3"]'));
        $client->request('POST', sprintf('/financial-responsibility-centers/%s/archive', $center->getId()), [
            '_token' => $this->csrfToken($client, 'archive'.$center->getId()),
            'version' => $center->getVersion(),
        ]);
        self::assertResponseRedirects('/financial-responsibility-centers/');

        $this->em()->clear();
        $centerRepository = $client->getContainer()->get(FinancialResponsibilityCenterRepository::class);
        $center = $centerRepository->findOneByIdAndCompanyId($center->getId(), $companyId);
        self::assertInstanceOf(FinancialResponsibilityCenter::class, $center);
        self::assertSame(FinancialResponsibilityCenterStatus::ARCHIVED, $center->getStatus());
        self::assertSame(4, $center->getVersion());
    }

    public function testOtherCompanyCenterReturnsNotFound(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$owner, $company] = $this->createCompany($client, 803);
        [, $otherCompany] = $this->createCompany($client, 804);

        /** @var FinancialResponsibilityCenterRepository $repository */
        $repository = $client->getContainer()->get(FinancialResponsibilityCenterRepository::class);
        $otherCenter = $repository->findGeneralByCompanyId((string) $otherCompany->getId());
        self::assertInstanceOf(FinancialResponsibilityCenter::class, $otherCenter);

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', (string) $company->getId());
        $client->request('GET', sprintf('/financial-responsibility-centers/%s/edit', $otherCenter->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    /**
     * @return array{User, Company}
     */
    private function createCompany(KernelBrowser $client, int $index): array
    {
        $owner = UserBuilder::aUser()->withIndex($index)->build();
        $this->em()->persist($owner);
        $this->em()->flush();

        /** @var CompanyOwnerMembershipCreator $creator */
        $creator = $client->getContainer()->get(CompanyOwnerMembershipCreator::class);
        $company = $creator->createCompany($owner, 'Company '.$index);
        $this->em()->flush();

        return [$owner, $company];
    }

    private function findCenterByName(
        FinancialResponsibilityCenterRepository $repository,
        string $companyId,
        string $name,
    ): FinancialResponsibilityCenter {
        foreach ($repository->findForManagement($companyId, true) as $center) {
            if ($name === $center->getName()) {
                return $center;
            }
        }

        self::fail(sprintf('Center "%s" was not found.', $name));
    }
}
