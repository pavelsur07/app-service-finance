<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Entity\Transaction\CashTransactionAutoRuleCondition;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\CounterpartyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Поиск, фильтр по статусу и пагинация списка автоправил.
 */
final class CashTransactionAutoRuleSearchTest extends WebTestCaseBase
{
    private const ADS_RULE = 'Реклама Яндекса';
    private const RENT_RULE = 'Аренда офиса';
    private const DISABLED_RULE = 'Старое правило';

    public function testSearchMatchesRuleName(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->fixtures();

        self::assertSame([self::ADS_RULE], $this->names($this->open($client, $user, $company, '?q=реклама')));
    }

    public function testSearchMatchesConditionValue(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->fixtures();

        // «аренд» лежит только в условии правила, в названии этого слова нет.
        self::assertSame([self::RENT_RULE], $this->names($this->open($client, $user, $company, '?q=за+аренду+помещения')));
    }

    public function testSearchMatchesCounterpartyName(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->fixtures();

        self::assertSame([self::ADS_RULE], $this->names($this->open($client, $user, $company, '?q=ромашка')));
    }

    public function testSearchIsCaseInsensitiveAndDoesNotSplitRuleIntoDuplicates(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->fixtures();

        // У правила два условия, и оба подходят под запрос — строка должна остаться одна.
        $crawler = $this->open($client, $user, $company, '?q=ЯНДЕКС');
        self::assertSame([self::ADS_RULE], $this->names($crawler));

        // Счётчик берётся из COUNT пагинации: join размножил бы строки в подсчёте,
        // даже если гидратация схлопнула бы их в одну сущность.
        self::assertSame('Найдено правил: 1', trim($crawler->filter('.card-footer .text-muted')->text()));
    }

    public function testUnderscoreInQueryIsNotASingleCharacterWildcard(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->fixtures();

        self::assertSame([], $this->names($this->open($client, $user, $company, '?q=%5F')));
    }

    public function testSearchSurvivesApplyingFilters(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->fixtures();

        $crawler = $this->open($client, $user, $company, '?q=реклама');
        $client->submit($crawler->filter('#auto-rule-filters-offcanvas form')->form());

        self::assertResponseIsSuccessful();
        self::assertSame([self::ADS_RULE], $this->names($client->getCrawler()));
    }

    public function testLikeWildcardsInQueryAreNotTreatedAsPattern(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->fixtures();

        self::assertSame([], $this->names($this->open($client, $user, $company, '?q=%25')));
    }

    public function testDisabledRulesAreHiddenByDefaultAndShownByFilter(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->fixtures();

        self::assertSame([self::ADS_RULE, self::RENT_RULE], $this->names($this->open($client, $user, $company, '')));
        self::assertSame([self::DISABLED_RULE], $this->names($this->open($client, $user, $company, '?status=disabled')));
        self::assertSame(
            [self::ADS_RULE, self::RENT_RULE, self::DISABLED_RULE],
            $this->names($this->open($client, $user, $company, '?status=all')),
        );
    }

    public function testListIsPaginatedByFiftyRules(): void
    {
        $client = static::createClient();
        [$user, $company] = $this->fixtures();
        $category = $this->em()->getRepository(CashflowCategory::class)->findOneBy(['company' => $company]);

        for ($i = 0; $i < 50; ++$i) {
            $this->em()->persist(new CashTransactionAutoRule(
                Uuid::uuid4()->toString(),
                $company,
                sprintf('Массовое правило %02d', $i),
                CashTransactionAutoRuleAction::FILL,
                CashTransactionAutoRuleOperationType::ANY,
                $category,
            ));
        }
        $this->em()->flush();

        self::assertCount(50, $this->names($this->open($client, $user, $company, '')));
        self::assertCount(2, $this->names($this->open($client, $user, $company, '?page=2')));

        // Страница вне диапазона прижимается к последней, а не показывает пустой
        // список рядом со счётчиком «найдено 52».
        self::assertCount(2, $this->names($this->open($client, $user, $company, '?page=999')));
        self::assertCount(50, $this->names($this->open($client, $user, $company, '?page=-3')));
        self::assertCount(50, $this->names($this->open($client, $user, $company, '?page=abc')));
    }

    private function open(KernelBrowser $client, User $user, Company $company, string $query): Crawler
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/cash-transaction-auto-rules/'.$query);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    /** @return list<string> */
    private function names(Crawler $crawler): array
    {
        return array_values(array_map(
            static fn (string $text): string => trim($text),
            $crawler->filter('tbody tr td:first-child div')->extract(['_text']),
        ));
    }

    /** @return array{0: User, 1: Company} */
    private function fixtures(): array
    {
        $this->resetDb();

        $user = UserBuilder::aUser()->withIndex(1)->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $category = CashflowCategoryBuilder::aCashflowCategory()
            ->withId('44444444-4444-4444-4444-444444444404')
            ->withCompany($company)
            ->withName('Маркетинг')
            ->build();
        $counterparty = CounterpartyBuilder::aCounterparty()
            ->withId('55555555-5555-5555-5555-555555555503')
            ->withCompany($company)
            ->withName('ООО Ромашка')
            ->build();

        foreach ([$user, $company, $category, $counterparty] as $entity) {
            $this->em()->persist($entity);
        }

        // Правила создаются в одну секунду, а created_at хранится с точностью до
        // секунды, поэтому порядок доопределяется по id — задаём их явно.
        $ads = $this->rule($company, $category, self::ADS_RULE, '66666666-6666-6666-6666-666666666601');
        $ads->setCounterparty($counterparty);
        $ads->addCondition(new CashTransactionAutoRuleCondition(
            field: CashTransactionAutoRuleConditionField::DESCRIPTION,
            operator: CashTransactionAutoRuleConditionOperator::CONTAINS,
            value: 'Яндекс Директ',
        ));
        $ads->addCondition(new CashTransactionAutoRuleCondition(
            field: CashTransactionAutoRuleConditionField::COUNTERPARTY_NAME,
            operator: CashTransactionAutoRuleConditionOperator::CONTAINS,
            value: 'яндекс',
        ));

        $rent = $this->rule($company, $category, self::RENT_RULE, '66666666-6666-6666-6666-666666666602');
        $rent->addCondition(new CashTransactionAutoRuleCondition(
            field: CashTransactionAutoRuleConditionField::DESCRIPTION,
            operator: CashTransactionAutoRuleConditionOperator::CONTAINS,
            value: 'за аренду помещения',
        ));

        $disabled = $this->rule($company, $category, self::DISABLED_RULE, '66666666-6666-6666-6666-666666666603');
        $disabled->disable();

        foreach ([$ads, $rent, $disabled] as $rule) {
            $this->em()->persist($rule);
        }
        $this->em()->flush();

        return [$user, $company];
    }

    private function rule(
        Company $company,
        CashflowCategory $category,
        string $name,
        string $id,
    ): CashTransactionAutoRule {
        return new CashTransactionAutoRule(
            $id,
            $company,
            $name,
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY,
            $category,
        );
    }
}
