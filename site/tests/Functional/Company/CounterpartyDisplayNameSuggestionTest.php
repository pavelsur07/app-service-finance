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
use Symfony\Component\DomCrawler\Crawler;

/**
 * Подсказка нормализации в списке контрагентов: видна только там, где правила уверены и
 * название ещё не такое; «Применить» и «Вернуть исходное» работают POST + CSRF и возвращают
 * на список с теми же фильтрами.
 */
final class CounterpartyDisplayNameSuggestionTest extends WebTestCaseBase
{
    public function testSuggestionShownOnlyWhereRulesApply(): void
    {
        // Given
        $client = static::createClient();
        [$owner, $company] = $this->createCompany($client, 951);
        $this->createCounterparty($company, 'АО "АЛЬФА-БАНК" Р/С 30233810714000000919');
        $this->createCounterparty($company, 'ООО «Ромашка»');
        $this->createCounterparty($company, 'ФНС России');
        $this->createCounterparty($company, 'ФГБУ "Центр"');
        $this->createCounterparty($company, 'ООО "Архивная"', archived: true);
        $this->em()->flush();
        $this->login($client, $owner, $company);

        // When
        $crawler = $client->request('GET', '/counterparties/?show_archived=1');

        // Then: одна подсказка — у «сырого» названия
        self::assertResponseIsSuccessful();
        $suggestions = $crawler->filter('.vf-name-suggest');
        self::assertCount(1, $suggestions);
        self::assertStringContainsString('Предложено: АО «АЛЬФА-БАНК»', $suggestions->text());
        self::assertCount(1, $suggestions->filter('form[action*="/display-name/apply"] button[type=submit]'));
    }

    public function testApplyThenResetKeepsFilters(): void
    {
        // Given
        $client = static::createClient();
        [$owner, $company] = $this->createCompany($client, 952);
        $this->createCounterparty($company, 'ООО "ИНТЕРНЕТ МАРКЕТИНГ"');
        $this->em()->flush();
        $this->login($client, $owner, $company);
        $crawler = $client->request('GET', '/counterparties/?q=%D0%B8%D0%BD%D1%82%D0%B5%D1%80%D0%BD%D0%B5%D1%82&type=LEGAL_ENTITY');

        // When: «Применить»
        $client->submit($crawler->filter('.vf-name-suggest form')->form());

        // Then: назад на список с теми же фильтрами, название показано, исходное — под ним
        self::assertResponseRedirects('/counterparties/?q=%D0%B8%D0%BD%D1%82%D0%B5%D1%80%D0%BD%D0%B5%D1%82&type=LEGAL_ENTITY');
        $crawler = $client->followRedirect();
        self::assertSame('ООО «ИНТЕРНЕТ МАРКЕТИНГ»', trim($crawler->filter('.vf-table tbody tr a.vf-strong')->text()));
        self::assertStringContainsString('Исходное: ООО "ИНТЕРНЕТ МАРКЕТИНГ"', $crawler->filter('.vf-name-origin')->text());
        self::assertCount(0, $crawler->filter('.vf-name-suggest'));
        self::assertStringContainsString('Название обновлено', $this->flashes($crawler));

        // When: «Вернуть исходное»
        $client->submit($crawler->filter('.vf-name-origin form')->form());

        // Then: сырое название и подсказка снова
        $crawler = $client->followRedirect();
        self::assertSame('ООО "ИНТЕРНЕТ МАРКЕТИНГ"', trim($crawler->filter('.vf-table tbody tr a.vf-strong')->text()));
        self::assertCount(1, $crawler->filter('.vf-name-suggest'));
    }

    public function testInvalidCsrfChangesNothing(): void
    {
        // Given
        $client = static::createClient();
        [$owner, $company] = $this->createCompany($client, 953);
        $id = $this->createCounterparty($company, 'ООО "Ромашка"');
        $this->em()->flush();
        $this->login($client, $owner, $company);

        // When
        $client->request('POST', '/counterparties/'.$id.'/display-name/apply', ['_token' => 'forged']);

        // Then
        self::assertResponseRedirects('/counterparties/');
        self::assertNull($this->displayName($id));
    }

    public function testForeignCounterpartyIsNotFound(): void
    {
        // Given
        $client = static::createClient();
        [$owner, $company] = $this->createCompany($client, 954);
        [, $foreignCompany] = $this->createCompany($client, 955);
        $foreignId = $this->createCounterparty($foreignCompany, 'ООО "Чужая"');
        $this->em()->flush();
        $this->login($client, $owner, $company);

        // When
        $client->request('POST', '/counterparties/'.$foreignId.'/display-name/apply', ['_token' => $this->csrfToken($client, 'apply_display_name'.$foreignId)]);

        // Then
        self::assertResponseStatusCodeSame(404);
        self::assertNull($this->displayName($foreignId));
    }

    private function flashes(Crawler $crawler): string
    {
        return $crawler->filter('.toast')->count() > 0 ? $crawler->filter('.toast')->text() : '';
    }

    private function displayName(string $id): ?string
    {
        /** @var string|false|null $value */
        $value = $this->em()->getConnection()->fetchOne('SELECT display_name FROM "counterparty" WHERE id = :id', ['id' => $id]);

        return \is_string($value) ? $value : null;
    }

    private function createCounterparty(Company $company, string $name, bool $archived = false): string
    {
        $builder = CounterpartyBuilder::aCounterparty()->withId(Uuid::uuid7()->toString())->withCompany($company)->withName($name)->withInn(null);
        if ($archived) {
            $builder = $builder->asArchived();
        }
        $counterparty = $builder->build();
        $this->em()->persist($counterparty);

        return $counterparty->getId();
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
