<?php

declare(strict_types=1);

namespace App\Tests\Functional\Company;

use App\Company\Application\Service\CompanyOwnerMembershipCreator;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Этап 5 docs/plan/counterparty-display-name.md: отображаемое название — в поиске пикера, в пилюле
 * и чипе «Операций ДДС», в карточке контрагента (пометка). Сырое name не меняется.
 */
final class CounterpartyDisplayNameShownTest extends WebTestCaseBase
{
    public function testSearchApiReturnsDisplayName(): void
    {
        // Given
        $client = static::createClient();
        [$owner, $company] = $this->createCompany($client, 971);
        $id = $this->createCounterparty($company, 'ООО "Ромашка"', 'ООО «Ромашка»', 'manual');
        $this->login($client, $owner, $company);

        // When
        $client->request('GET', '/api/counterparties/search?q=%D1%80%D0%BE%D0%BC%D0%B0%D1%88%D0%BA%D0%B0');

        // Then
        self::assertResponseIsSuccessful();
        /** @var list<array<string, mixed>> $items */
        $items = json_decode((string) $client->getResponse()->getContent(), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame($id, $items[0]['id']);
        self::assertSame('ООО "Ромашка"', $items[0]['name']);
        self::assertSame('ООО «Ромашка»', $items[0]['displayName']);
    }

    public function testOperationsPillAndChipShowDisplayName(): void
    {
        // Given
        $client = static::createClient();
        [$owner, $company] = $this->createCompany($client, 972);
        $id = $this->createCounterparty($company, 'ООО "ИНТЕРНЕТ МАРКЕТИНГ"', 'ООО «ИНТЕРНЕТ МАРКЕТИНГ»', 'auto');
        $this->login($client, $owner, $company);

        // When
        $crawler = $client->request('GET', '/finance/cash-transactions/?counterpartyId='.$id);

        // Then
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Контрагент: ООО «ИНТЕРНЕТ МАРКЕТИ…', $crawler->filter('#ops-pill-counterparty')->text());
        self::assertStringContainsString('Контрагент: ООО «ИНТЕРНЕТ МАРКЕТИНГ»', $crawler->filter('.vf-ops-chip')->text());
        /** @var list<array<string, mixed>> $initial */
        $initial = json_decode((string) $crawler->filter('[data-picker-pill]')->attr('data-picker-initial'), true, flags: \JSON_THROW_ON_ERROR);
        self::assertSame('ООО «ИНТЕРНЕТ МАРКЕТИНГ»', $initial[0]['displayName']);
    }

    public function testEditFormNotesManualDisplayName(): void
    {
        // Given
        $client = static::createClient();
        [$owner, $company] = $this->createCompany($client, 973);
        $manualId = $this->createCounterparty($company, 'ООО "Ромашка"', 'Ромашка (поставщик)', 'manual');
        $plainId = $this->createCounterparty($company, 'ООО "Лютик"', null, null);
        $this->login($client, $owner, $company);

        // When / Then: ручное — пометка с пояснением
        $crawler = $client->request('GET', '/counterparties/'.$manualId.'/edit');
        self::assertResponseIsSuccessful();
        $note = $crawler->filter('.vf-form [role=note]');
        self::assertCount(1, $note);
        self::assertStringContainsString('отображается как Ромашка (поставщик) (задано вручную)', preg_replace('/\s+/u', ' ', $note->text()) ?? '');
        self::assertSame('ООО "Ромашка"', $crawler->filter('#counterparty_name')->attr('value'));

        // When / Then: без отображаемого — без пометки
        $crawler = $client->request('GET', '/counterparties/'.$plainId.'/edit');
        self::assertCount(0, $crawler->filter('.vf-form [role=note]'));
    }

    private function createCounterparty(Company $company, string $name, ?string $displayName, ?string $source): string
    {
        // После clear() в предыдущем вызове компания отсоединена — берём управляемую ссылку.
        $managedCompany = $this->em()->getReference(Company::class, $company->getId());
        self::assertInstanceOf(Company::class, $managedCompany);
        $counterparty = CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid7()->toString())->withCompany($managedCompany)->withName($name)->withInn(null)->build();
        $this->em()->persist($counterparty);
        $this->em()->flush();
        if (null !== $displayName) {
            $this->em()->getConnection()->executeStatement(
                'UPDATE "counterparty" SET display_name = :name, display_name_source = :source WHERE id = :id',
                ['name' => $displayName, 'source' => $source, 'id' => $counterparty->getId()],
            );
        }
        $id = $counterparty->getId();
        // Запрос клиента идёт в том же EntityManager: без clear() он отдал бы снимок без UPDATE.
        $this->em()->clear();

        return $id;
    }

    private function login(KernelBrowser $client, User $owner, Company $company): void
    {
        $client->loginUser($owner);
        $this->setClientSessionValue($client, 'active_company_id', (string) $company->getId());
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
