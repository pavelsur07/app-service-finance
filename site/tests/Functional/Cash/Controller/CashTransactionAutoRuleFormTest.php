<?php

declare(strict_types=1);

namespace App\Tests\Functional\Cash\Controller;

use App\Cash\Entity\Transaction\CashTransactionAutoRule;
use App\Cash\Entity\Transaction\CashTransactionAutoRuleCondition;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleAction;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionField;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleConditionOperator;
use App\Cash\Enum\Transaction\CashTransactionAutoRuleOperationType;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\WebTestCaseBase;
use Ramsey\Uuid\Uuid;

/**
 * Форма автоправила: условие рендерится одной строкой, и прототип для добавления
 * новых условий собирается тем же партиалом.
 *
 * Тест страхует именно рендер: логика показа и скрытия ячеек живёт в Stimulus, а
 * автотестов на JS в проекте нет — ошибка в Twig прототипа иначе всплыла бы только
 * при клике «+ Добавить условие» у пользователя.
 */
final class CashTransactionAutoRuleFormTest extends WebTestCaseBase
{
    public function testEditFormRendersOneRowPerConditionAndRowPrototype(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->withIndex(1)->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $category = CashflowCategoryBuilder::aCashflowCategory()
            ->withId('44444444-4444-4444-4444-444444444401')
            ->withCompany($company)
            ->withName('Аренда')
            ->build();

        $rule = new CashTransactionAutoRule(
            Uuid::uuid4()->toString(),
            $company,
            'Правило аренды',
            CashTransactionAutoRuleAction::FILL,
            CashTransactionAutoRuleOperationType::ANY,
            $category,
        );
        $rule->addCondition(new CashTransactionAutoRuleCondition(
            field: CashTransactionAutoRuleConditionField::DESCRIPTION,
            operator: CashTransactionAutoRuleConditionOperator::CONTAINS,
            value: 'аренд',
        ));
        $rule->addCondition(new CashTransactionAutoRuleCondition(
            field: CashTransactionAutoRuleConditionField::AMOUNT,
            operator: CashTransactionAutoRuleConditionOperator::BETWEEN,
            value: '100',
            valueTo: '200',
        ));

        foreach ([$user, $company, $category, $rule] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', sprintf('/cash-transaction-auto-rules/%s/edit', $rule->getId()));

        self::assertResponseIsSuccessful();

        // Одна строка на условие вместо карточки с шестью полями в столбик.
        self::assertCount(2, $crawler->filter('[data-auto-rule-condition].condition-row'));

        // Ячейки строки: поле операции, оператор, полиморфное значение, удаление.
        $firstRow = $crawler->filter('[data-auto-rule-condition]')->first();
        self::assertCount(1, $firstRow->filter('.condition-field-row'));
        self::assertCount(1, $firstRow->filter('.condition-operator-cell .condition-operator-row'));
        self::assertCount(1, $firstRow->filter('.condition-value-cell .condition-value-row'));
        self::assertCount(1, $firstRow->filter('.condition-value-cell .condition-value-to-row'));
        self::assertCount(1, $firstRow->filter('.condition-value-cell .condition-counterparty-row'));
        self::assertCount(1, $firstRow->filter('[data-remove-condition]'));

        // Метки выводятся шапкой один раз, а не у каждого поля каждой строки.
        self::assertCount(1, $crawler->filter('.condition-head'));

        // Прототип собран тем же партиалом: добавленное условие не разойдётся с
        // отрендеренным сервером.
        $prototype = $crawler->filter('[data-auto-rule-conditions-prototype-value]')
            ->attr('data-auto-rule-conditions-prototype-value');
        self::assertIsString($prototype);
        self::assertStringContainsString('condition-row', $prototype);
        self::assertStringContainsString('condition-value-cell', $prototype);
        self::assertStringContainsString('data-remove-condition', $prototype);
    }

    /**
     * Пустое название — ошибка валидации, а не 500: TextType отдаёт null, а
     * CashTransactionAutoRule::setName() принимает только string.
     */
    public function testEmptyNameIsValidationErrorNotServerError(): void
    {
        $client = static::createClient();
        $this->resetDb();

        $user = UserBuilder::aUser()->withIndex(1)->asCompanyOwner()->build();
        $company = CompanyBuilder::aCompany()->withIndex(1)->withOwner($user)->build();
        $category = CashflowCategoryBuilder::aCashflowCategory()
            ->withId('44444444-4444-4444-4444-444444444402')
            ->withCompany($company)
            ->withName('Аренда')
            ->build();

        foreach ([$user, $company, $category] as $entity) {
            $this->em()->persist($entity);
        }
        $this->em()->flush();

        $client->loginUser($user);
        $this->setClientSessionValue($client, 'active_company_id', $company->getId());

        $crawler = $client->request('GET', '/cash-transaction-auto-rules/new');
        $client->submit($crawler->selectButton('Сохранить')->form(), [
            'cash_transaction_auto_rule[name]' => '',
        ]);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString(
            'Укажите название автоправила.',
            (string) $client->getResponse()->getContent(),
        );
    }
}
