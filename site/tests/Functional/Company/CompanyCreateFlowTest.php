<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Company\Application\Service\CompanyOwnerMembershipCreator;
use App\Company\Entity\Company;
use App\Company\Entity\CompanyMember;
use App\Company\Entity\FinancialResponsibilityCenter;
use App\Company\Entity\ProjectDirection;
use App\Company\Repository\FinancialResponsibilityCenterProjectRepository;
use App\Company\Repository\FinancialResponsibilityCenterRepository;
use App\Company\Repository\ProjectDirectionRepository;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;

final class CompanyCreateFlowTest extends WebTestCaseBase
{
    public function testCreateCompanyAddsOwnerCompanyMember(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()
            ->withEmail('owner@example.test')
            ->withRoles(['ROLE_COMPANY_OWNER'])
            ->build();

        $em = $this->em();
        $em->persist($owner);
        $em->flush();

        $client->loginUser($owner);
        $crawler = $client->request('GET', '/company/new');

        $form = $crawler->selectButton('Создать')->form([
            'company[name]' => '  Created Company  ',
            'company[inn]' => '1234567890',
        ]);

        $client->submit($form);

        self::assertResponseRedirects('/company/');

        $company = $em->getRepository(Company::class)->findOneBy(['name' => 'Created Company']);
        self::assertNotNull($company);
        self::assertSame($owner->getId(), $company->getUser()?->getId());
        self::assertSame('1234567890', $company->getInn());

        $member = $em->getRepository(CompanyMember::class)->findOneByCompanyAndUser($company, $owner);
        self::assertNotNull($member);
        self::assertSame(CompanyMember::ROLE_OWNER, $member->getRole());
        self::assertSame(CompanyMember::STATUS_ACTIVE, $member->getStatus());

        /** @var ProjectDirectionRepository $projectRepository */
        $projectRepository = $client->getContainer()->get(ProjectDirectionRepository::class);
        /** @var FinancialResponsibilityCenterRepository $centerRepository */
        $centerRepository = $client->getContainer()->get(FinancialResponsibilityCenterRepository::class);
        /** @var FinancialResponsibilityCenterProjectRepository $pairRepository */
        $pairRepository = $client->getContainer()->get(FinancialResponsibilityCenterProjectRepository::class);

        $project = $projectRepository->findDefaultForCompany($company);
        $center = $centerRepository->findGeneralByCompanyId((string) $company->getId());
        self::assertInstanceOf(ProjectDirection::class, $project);
        self::assertSame(ProjectDirection::CODE_GENERAL, $project->getSystemCode());
        self::assertInstanceOf(FinancialResponsibilityCenter::class, $center);
        self::assertTrue($pairRepository->isAllowed(
            (string) $company->getId(),
            (string) $project->getId(),
            $center->getId(),
        ));
    }

    public function testSystemProjectCannotBeDeletedThroughController(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $owner = UserBuilder::aUser()
            ->withEmail('system-project-owner@example.test')
            ->withRoles(['ROLE_COMPANY_OWNER'])
            ->build();
        $em = $this->em();
        $em->persist($owner);
        $em->flush();

        /** @var CompanyOwnerMembershipCreator $creator */
        $creator = $client->getContainer()->get(CompanyOwnerMembershipCreator::class);
        /** @var ProjectDirectionRepository $projectRepository */
        $projectRepository = $client->getContainer()->get(ProjectDirectionRepository::class);
        $company = $creator->createCompany($owner, 'Protected Company');
        $em->flush();
        $project = $projectRepository->findDefaultForCompany($company);
        self::assertInstanceOf(ProjectDirection::class, $project);

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', (string) $company->getId());
        $client->request('POST', sprintf('/project-directions/%s/delete', $project->getId()), [
            '_token' => $this->csrfToken($client, 'delete'.$project->getId()),
        ]);

        self::assertResponseRedirects('/project-directions/');
        $client->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Системный проект нельзя удалить.', (string) $client->getResponse()->getContent());

        $em->clear();
        self::assertNotNull($em->getRepository(ProjectDirection::class)->find($project->getId()));
    }
}
