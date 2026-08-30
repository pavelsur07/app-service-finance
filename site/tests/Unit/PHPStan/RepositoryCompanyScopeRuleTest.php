<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan;

use App\Tests\PHPStan\Rules\RepositoryCompanyScopeRule;
use PHPStan\Rules\Rule;
use PHPStan\Testing\RuleTestCase;

/**
 * @extends RuleTestCase<RepositoryCompanyScopeRule>
 */
final class RepositoryCompanyScopeRuleTest extends RuleTestCase
{
    private const TIP = 'Добавить параметр string $companyId и фильтр по нему в QueryBuilder.';

    /**
     * Каждое нарушение здесь — отдельный способ обойти правило, найденный
     * внешним ревью: nullable и нетипизированный параметр, тег отказа без
     * причины в трёх формах, компания, спрятанная в DTO.
     */
    public function testReportsUnscopedQueryMethodsOfTenantScopedRepository(): void
    {
        $this->analyse(
            [__DIR__.'/data/Repository/OrderRepository.php'],
            [
                [
                    'Метод-запрос App\\Tests\\Unit\\PHPStan\\data\\Repository\\OrderRepository::findByStatus()'
                    .' не принимает $companyId — выборка не ограничена компанией (IDOR).',
                    19,
                    self::TIP,
                ],
                [
                    'Метод-запрос App\\Tests\\Unit\\PHPStan\\data\\Repository\\OrderRepository::isArchived()'
                    .' не принимает $companyId — выборка не ограничена компанией (IDOR).',
                    25,
                    self::TIP,
                ],
                [
                    'Метод-запрос App\\Tests\\Unit\\PHPStan\\data\\Repository\\OrderRepository::findByNullableCompanyId()'
                    .' не принимает $companyId — выборка не ограничена компанией (IDOR).',
                    43,
                    self::TIP,
                ],
                [
                    'Метод-запрос App\\Tests\\Unit\\PHPStan\\data\\Repository\\OrderRepository::findByUntypedCompanyId()'
                    .' не принимает $companyId — выборка не ограничена компанией (IDOR).',
                    49,
                    self::TIP,
                ],
                [
                    'Метод-запрос App\\Tests\\Unit\\PHPStan\\data\\Repository\\OrderRepository::findWithTagClosedOnSameLine()'
                    .' не принимает $companyId — выборка не ограничена компанией (IDOR).',
                    58,
                    self::TIP,
                ],
                [
                    'Метод-запрос App\\Tests\\Unit\\PHPStan\\data\\Repository\\OrderRepository::findByFilter()'
                    .' не принимает $companyId — выборка не ограничена компанией (IDOR).',
                    64,
                    self::TIP,
                ],
                [
                    'Метод-запрос App\\Tests\\Unit\\PHPStan\\data\\Repository\\OrderRepository::findWithBareExemptTag()'
                    .' не принимает $companyId — выборка не ограничена компанией (IDOR).',
                    84,
                    self::TIP,
                ],
                [
                    'Метод-запрос App\\Tests\\Unit\\PHPStan\\data\\Repository\\OrderRepository::findMentioningTagInProse()'
                    .' не принимает $companyId — выборка не ограничена компанией (IDOR).',
                    93,
                    self::TIP,
                ],
            ],
        );
    }

    /**
     * Ключевая ветка: у сущности справочника платформы нет поля компании,
     * ограничивать выборку нечем, и правило обязано молчать. Первая редакция
     * правила этого не проверяла и объявила такие методы IDOR-долгом.
     */
    public function testIgnoresRepositoryWhoseEntityHasNoCompany(): void
    {
        $this->analyse([__DIR__.'/data/Repository/PlatformPlanRepository.php'], []);
    }

    public function testIgnoresClassesThatAreNotRepositories(): void
    {
        $this->analyse([__DIR__.'/data/Service/NotARepositoryService.php'], []);
    }

    /**
     * Сущность определяется только через `parent::__construct($registry,
     * Entity::class)` — так написаны 59 репозиториев проекта, и редакция,
     * умевшая читать лишь docblock-шаблон, их не различала.
     */
    public function testResolvesEntityFromConstructorWhenTemplateIsAbsent(): void
    {
        $this->analyse(
            [__DIR__.'/data/Repository/InvoiceRepository.php'],
            [
                [
                    'Метод-запрос App\Tests\Unit\PHPStan\data\Repository\InvoiceRepository::findOverdue()'
                    .' не принимает $companyId — выборка не ограничена компанией (IDOR).',
                    23,
                    self::TIP,
                ],
            ],
        );
    }

    public function testIgnoresNonTenantRepositoryResolvedFromConstructor(): void
    {
        $this->analyse([__DIR__.'/data/Repository/CurrencyRepository.php'], []);
    }

    /**
     * Репозиторий вне namespace `*\Repository\*`: в проекте таких два, и
     * редакция, требовавшая namespace, их пропускала.
     */
    public function testChecksRepositoryOutsideRepositoryNamespace(): void
    {
        $this->analyse(
            [__DIR__.'/data/Query/LedgerRepository.php'],
            [
                [
                    'Метод-запрос App\Tests\Unit\PHPStan\data\Query\LedgerRepository::findByPeriod()'
                    .' не принимает $companyId — выборка не ограничена компанией (IDOR).',
                    13,
                    self::TIP,
                ],
            ],
        );
    }

    /**
     * Транзитивное владение: у сущности нет своего поля компании, но она
     * принадлежит `Order`, у которого оно есть. Плюс проверяются добавленный
     * префикс `stream*` и однострочная форма отказа.
     */
    public function testResolvesTransitiveCompanyOwnershipAndStreamPrefix(): void
    {
        $this->analyse(
            [__DIR__.'/data/Repository/LoanScheduleItemRepository.php'],
            [
                [
                    'Метод-запрос App\Tests\Unit\PHPStan\data\Repository\LoanScheduleItemRepository::findUnpaid()'
                    .' не принимает $companyId — выборка не ограничена компанией (IDOR).',
                    19,
                    self::TIP,
                ],
                [
                    'Метод-запрос App\Tests\Unit\PHPStan\data\Repository\LoanScheduleItemRepository::streamByFilters()'
                    .' не принимает $companyId — выборка не ограничена компанией (IDOR).',
                    31,
                    self::TIP,
                ],
            ],
        );
    }

    protected function getRule(): Rule
    {
        return new RepositoryCompanyScopeRule(self::createReflectionProvider());
    }
}
