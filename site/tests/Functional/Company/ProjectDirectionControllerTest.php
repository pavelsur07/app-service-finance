<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Company\Application\Service\CompanyOwnerMembershipCreator;
use App\Company\Entity\Company;
use App\Company\Entity\ProjectDirection;
use App\Company\Entity\User;
use App\Company\Repository\ProjectDirectionRepository;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class ProjectDirectionControllerTest extends WebTestCaseBase
{
    public function testNewProjectSuggestsNextSiblingSortAndKeepsManualValue(): void
    {
        $client = static::createClient();
        $this->resetDb();
        [$owner, $company] = $this->createCompany($client, 821);

        $parent = (new ProjectDirection('33333333-3333-3333-3333-000000000821', $company, 'Parent'))
            ->setSort(20);
        $root = (new ProjectDirection('33333333-3333-3333-3333-000000000822', $company, 'Root'))
            ->setSort(30);
        $child = (new ProjectDirection('33333333-3333-3333-3333-000000000823', $company, 'Child'))
            ->setParent($parent)
            ->setSort(40);
        $this->em()->persist($parent);
        $this->em()->persist($root);
        $this->em()->persist($child);
        $this->em()->flush();

        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', (string) $company->getId());

        $crawler = $client->request('GET', '/project-directions/new');

        self::assertResponseIsSuccessful();
        self::assertInputValueSame('project_direction[sort]', '40');
        self::assertSelectorExists('[data-project-direction-sort-target="sort"]');
        self::assertSelectorExists('[data-project-direction-sort-target="parent"][data-action="change->project-direction-sort#refresh"]');

        $sortController = $crawler->filter('[data-controller="project-direction-sort"]');
        self::assertCount(1, $sortController);
        $encodedSortOrders = $sortController->attr('data-project-direction-sort-next-by-parent-value');
        self::assertNotNull($encodedSortOrders);
        $nextSortByParent = json_decode($encodedSortOrders, true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame(40, $nextSortByParent['']);
        self::assertSame(50, $nextSortByParent[$parent->getId()]);
        self::assertSame(10, $nextSortByParent[$child->getId()]);

        $client->submit($crawler->selectButton('Сохранить')->form([
            'project_direction[name]' => 'Manual sort',
            'project_direction[sort]' => '77',
            'project_direction[parent]' => $parent->getId(),
        ]));
        self::assertResponseRedirects('/project-directions/');

        /** @var ProjectDirectionRepository $repository */
        $repository = $client->getContainer()->get(ProjectDirectionRepository::class);
        $created = $repository->findOneBy([
            'company' => $company,
            'name' => 'Manual sort',
        ]);
        self::assertInstanceOf(ProjectDirection::class, $created);
        self::assertSame(77, $created->getSort());
        self::assertSame($parent->getId(), $created->getParent()?->getId());

        $crawler = $client->followRedirect();
        self::assertSelectorTextContains('thead', 'Сортировка');

        $createdRow = $crawler->filterXPath('//tbody/tr[td[contains(normalize-space(.), "Manual sort")]]');
        self::assertCount(1, $createdRow);
        self::assertSame('77', trim($createdRow->filter('td')->eq(1)->text()));

        $rootRow = $crawler->filterXPath('//tbody/tr[td[contains(normalize-space(.), "Root")]]');
        self::assertCount(1, $rootRow);
        self::assertSame('30', trim($rootRow->filter('td')->eq(1)->text()));
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
}
