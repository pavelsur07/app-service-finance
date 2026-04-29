<?php

declare(strict_types=1);

namespace App\Tests\Functional\Marketplace\Controller;

use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

final class CostPLMappingControllerTest extends WebTestCaseBase
{
    public function testIndexRendersDefaultMappingUiElements(): void
    {
        $this->resetDb();
        $client = static::createClient();
        [$user, $company] = $this->seedBaseData();
        $this->loginWithActiveCompany($client, $user, $company);

        $client->request('GET', '/marketplace/cost-pl-mapping');

        self::assertResponseIsSuccessful();
        $html = (string) $client->getResponse()->getContent();

        self::assertStringContainsString('Настроить базовый маппинг', $html);
        self::assertStringContainsString('modal-default-cost-mapping', $html);
        self::assertStringContainsString('/marketplace/cost-pl-mapping/default/preview', $html);
        self::assertStringContainsString('/marketplace/cost-pl-mapping/default/apply', $html);

        $csrf = $client->getContainer()->get('security.csrf.token_manager')
            ->getToken('marketplace_default_cost_mapping')
            ->getValue();
        self::assertStringContainsString($csrf, $html);
    }

    private function loginWithActiveCompany(KernelBrowser $client, User $user, Company $company): void
    {
        $client->loginUser($user);
        $session = $client->getContainer()->get('session');
        $session->set('active_company_id', $company->getId());
        $session->save();
    }

    private function seedBaseData(): array
    {
        $user = UserBuilder::aUser()->withEmail('cost-pl-mapping@test.local')->build();
        $company = CompanyBuilder::aCompany()->withOwner($user)->build();

        $em = $this->em();
        $em->persist($user);
        $em->persist($company);
        $em->flush();

        return [$user, $company];
    }
}
