<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Entity\Transaction\CashTransactionAutoRuleCondition;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use App\Shared\Entity\AuditLog;
use App\Shared\Enum\AuditLogAction;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;

/**
 * Столбец действий списка автоправил: одно меню «⋯» вместо трёх кнопок,
 * необратимые операции — через модальное подтверждение.
 */
final class CashTransactionAutoRuleActionsMenuTest extends WebTestCaseBase
{
    public function testActionsColumnRendersDropdownWithFourItems(): void
    {
        $client = static::createClient();
        [$user, $company, $rule] = $this->fixtures();

        $crawler = $this->openIndex($client, $user, $company);

        $menu = $crawler->filter('td .dropdown-menu');
        self::assertCount(1, $menu);

        $items = $menu->filter('.dropdown-item');
        self::assertSame(
            ['Проверка', 'Редактирование', 'Отключить', 'Удалить'],
            array_map(static fn (string $text): string => trim($text), $items->extract(['_text'])),
        );

        // Проверка и Редактирование — обычные ссылки, необратимые действия открывают модалку.
        self::assertSame(
            sprintf('/cash-transaction-auto-rules/%s/check', $rule->getId()),
            $items->eq(0)->attr('href'),
        );
        self::assertSame(
            sprintf('/cash-transaction-auto-rules/%s/edit', $rule->getId()),
            $items->eq(1)->attr('href'),
        );
        self::assertSame('#disable-modal-'.$rule->getId(), $items->eq(2)->attr('data-bs-target'));
        self::assertSame('#delete-modal-'.$rule->getId(), $items->eq(3)->attr('data-bs-target'));

        // Обе модалки на странице, каждая со своей формой и своим токеном.
        self::assertCount(1, $crawler->filter('#disable-modal-'.$rule->getId()));
        self::assertCount(1, $crawler->filter('#delete-modal-'.$rule->getId()));
        self::assertSame(
            sprintf('/cash-transaction-auto-rules/%s/delete', $rule->getId()),
            $crawler->filter('#delete-modal-'.$rule->getId().' form')->attr('action'),
        );

        // Старая кнопка с браузерным confirm() не осталась.
        self::assertStringNotContainsString('onsubmit', (string) $client->getResponse()->getContent());
    }

    public function testDisabledRuleKeepsDeleteButNotDisable(): void
    {
        $client = static::createClient();
        [$user, $company, $rule] = $this->fixtures();
        $rule->disable();
        $this->em()->flush();

        $crawler = $this->openIndex($client, $user, $company);

        $labels = array_map(
            static fn (string $text): string => trim($text),
            $crawler->filter('td .dropdown-menu .dropdown-item')->extract(['_text']),
        );
        self::assertSame(['Проверка', 'Редактирование', 'Включить', 'Удалить'], $labels);
        self::assertCount(0, $crawler->filter('#disable-modal-'.$rule->getId()));
        self::assertCount(1, $crawler->filter('#delete-modal-'.$rule->getId()));
    }

    public function testDisabledRuleCanBeEnabledBack(): void
    {
        $client = static::createClient();
        [$user, $company, $rule] = $this->fixtures();
        $rule->disable();
        $this->em()->flush();
        $ruleId = (string) $rule->getId();

        $crawler = $this->openIndex($client, $user, $company);
        $client->submit($crawler->filter('td .dropdown-menu form')->form());

        self::assertResponseRedirects('/cash-transaction-auto-rules/');
        $this->em()->clear();

        $stored = $this->em()->find(CashTransactionAutoRule::class, $ruleId);
        self::assertNotNull($stored);
        self::assertTrue($stored->isActive());
        self::assertNull($stored->getDisabledAt());
    }

    public function testEnableWithoutValidTokenKeepsRuleDisabled(): void
    {
        $client = static::createClient();
        [$user, $company, $rule] = $this->fixtures();
        $rule->disable();
        $this->em()->flush();
        $ruleId = (string) $rule->getId();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $client->request('POST', sprintf('/cash-transaction-auto-rules/%s/enable', $ruleId), [
            '_token' => 'wrong',
        ]);

        self::assertResponseRedirects('/cash-transaction-auto-rules/');
        $this->em()->clear();
        self::assertFalse($this->em()->find(CashTransactionAutoRule::class, $ruleId)->isActive());
    }

    public function testDeleteRemovesRuleWithConditionsAndWritesAudit(): void
    {
        $client = static::createClient();
        [$user, $company, $rule] = $this->fixtures();
        $ruleId = (string) $rule->getId();

        $crawler = $this->openIndex($client, $user, $company);
        $client->submit($crawler->filter('#delete-modal-'.$ruleId.' form')->form());

        self::assertResponseRedirects('/cash-transaction-auto-rules/');
        $this->em()->clear();

        self::assertNull($this->em()->find(CashTransactionAutoRule::class, $ruleId));
        self::assertSame(
            0,
            (int) $this->em()->getConnection()->fetchOne(
                'SELECT COUNT(*) FROM cash_transaction_auto_rule_condition WHERE auto_rule_id = ?',
                [$ruleId],
            ),
        );

        $audit = $this->em()->getRepository(AuditLog::class)->findOneBy([
            'entityId' => $ruleId,
            'action' => AuditLogAction::DELETE,
        ]);
        self::assertNotNull($audit, 'Удаление правила должно остаться в аудите.');
    }

    public function testDisableKeepsRuleAndMarksItInactive(): void
    {
        $client = static::createClient();
        [$user, $company, $rule] = $this->fixtures();
        $ruleId = (string) $rule->getId();

        $crawler = $this->openIndex($client, $user, $company);
        $client->submit($crawler->filter('#disable-modal-'.$ruleId.' form')->form());

        self::assertResponseRedirects('/cash-transaction-auto-rules/');
        $this->em()->clear();

        $stored = $this->em()->find(CashTransactionAutoRule::class, $ruleId);
        self::assertNotNull($stored);
        self::assertFalse($stored->isActive());
    }

    public function testDisabledRuleIsStillDeletedPhysically(): void
    {
        $client = static::createClient();
        [$user, $company, $rule] = $this->fixtures();
        $rule->disable();
        $this->em()->flush();
        $ruleId = (string) $rule->getId();

        $crawler = $this->openIndex($client, $user, $company);
        $client->submit($crawler->filter('#delete-modal-'.$ruleId.' form')->form());

        $this->em()->clear();
        self::assertNull($this->em()->find(CashTransactionAutoRule::class, $ruleId));
    }

    /**
     * Токены операций не взаимозаменяемы: подпись отключения не должна открывать
     * удаление, иначе одна подтверждённая операция давала бы право на другую.
     */
    public function testDisableTokenDoesNotAuthorizeDelete(): void
    {
        $client = static::createClient();
        [$user, $company, $rule] = $this->fixtures();
        $ruleId = (string) $rule->getId();

        $crawler = $this->openIndex($client, $user, $company);
        $disableToken = $crawler->filter('#disable-modal-'.$ruleId.' input[name="_token"]')->attr('value');

        $client->request('POST', sprintf('/cash-transaction-auto-rules/%s/delete', $ruleId), [
            '_token' => $disableToken,
        ]);

        self::assertResponseRedirects('/cash-transaction-auto-rules/');
        $this->em()->clear();
        self::assertNotNull($this->em()->find(CashTransactionAutoRule::class, $ruleId));
    }

    public function testDeleteWithoutValidTokenKeepsRule(): void
    {
        $client = static::createClient();
        [$user, $company, $rule] = $this->fixtures();
        $ruleId = (string) $rule->getId();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());
        $client->request('POST', sprintf('/cash-transaction-auto-rules/%s/delete', $ruleId), [
            '_token' => 'wrong',
        ]);

        self::assertResponseRedirects('/cash-transaction-auto-rules/');
        $this->em()->clear();
        self::assertNotNull($this->em()->find(CashTransactionAutoRule::class, $ruleId));
    }

    public function testRuleOfAnotherCompanyIsNotFound(): void
    {
        $client = static::createClient();
        [$user, , $rule] = $this->fixtures();
        $otherCompany = CompanyBuilder::aCompany()->withIndex(2)->withOwner($user)->build();
        $this->em()->persist($otherCompany);
        $this->em()->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $otherCompany->getId());
        $client->request('POST', sprintf('/cash-transaction-auto-rules/%s/delete', $rule->getId()));

        self::assertResponseStatusCodeSame(404);
    }

    /** @return array{0: User, 1: Company, 2: CashTransactionAutoRule} */
    private function fixtures(): array
    {
        $this->resetDb();

        $user = UserBuilder::aUser()->withIndex(1)->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $category = CashflowCategoryBuilder::aCashflowCategory()
            ->withId('44444444-4444-4444-4444-444444444403')
            ->withCompany($company)
            ->withName('Аренда')
            ->build();

        $rule = new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            'Правило аренды',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::OUTFLOW,
            $category,
        );
        $rule->addCondition(new CashTransactionAutoRuleCondition(
            field: CashTransactionAutoRuleConditionField::DESCRIPTION,
            operator: CashTransactionAutoRuleConditionOperator::CONTAINS,
            value: 'аренд',
        ));

        foreach ([$user, $company, $category, $rule] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        return [$user, $company, $rule];
    }

    private function openIndex(KernelBrowser $client, User $user, Company $company): \Symfony\Component\DomCrawler\Crawler
    {
        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/cash-transaction-auto-rules/');
        self::assertResponseIsSuccessful();

        return $crawler;
    }
}
