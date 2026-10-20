<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company;

use App\Admin\Application\CreateAccountAction;
use App\Company\Application\CreateCompanyOwnerAccountAction;
use App\Company\Entity\User;
use App\Company\Enum\BusinessProfile;
use App\Company\Event\CompanyCreatedEvent;
use App\Finance\Application\Service\PLCategoryTreeFileReader;
use App\Finance\Infrastructure\Provider\DefaultPLCategoryTreeProvider;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Ramsey\Uuid\Uuid;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;

/**
 * Сквозная проверка онбординга: что получает новая компания от всех подписчиков
 * `CompanyCreatedEvent`. Путь `/company/new` проверяет `CompanyCreateFlowTest`.
 */
final class CompanyOnboardingTest extends IntegrationTestCase
{
    private const FULL_SETUP = [
        'money_accounts' => 'bank:Основной счет:RUB, cash:Основная:RUB',
        'project' => 'PROJECT_GENERAL:Общий',
        'responsibility_center' => 'CFO_GENERAL:Общий',
        'cashflow_system_categories' => 7,
        'pl_categories' => 70,
        'balance_articles' => 33,
        'balance_accounts' => 'ACC_MAIN_BANK, ACC_MAIN_REGISTER',
    ];

    public function testRegistrationCreatesFullSetup(): void
    {
        $company = (self::getContainer()->get(CreateCompanyOwnerAccountAction::class))($this->newUser(), 'plain-password', 'Onboarding Co', false);

        self::assertSame(self::FULL_SETUP, $this->onboardingOf((string) $company->getId()));
    }

    public function testUniversalProfileGetsUniversalTemplates(): void
    {
        $company = (self::getContainer()->get(CreateCompanyOwnerAccountAction::class))($this->newUser(), 'plain-password', 'Universal Setup Co', false, BusinessProfile::UNIVERSAL);
        $companyId = (string) $company->getId();

        self::assertSame(array_merge(self::FULL_SETUP, ['pl_categories' => 41, 'balance_articles' => 31]), $this->onboardingOf($companyId));
        // Ни одной статьи маркетплейса: ни в ОПиУ, ни в Балансе.
        self::assertSame([], $this->connection->fetchFirstColumn(
            "SELECT code FROM pl_categories WHERE company_id = ? AND (code LIKE '%SPP%' OR code LIKE 'COGS_MP%' OR code LIKE 'CM_%' OR code LIKE 'PRODUCT_INFRA%' OR code LIKE 'OPEX_WH%' OR code LIKE 'PROMO%')",
            [$companyId],
        ));
        self::assertSame([], $this->connection->fetchFirstColumn("SELECT code FROM balance_articles WHERE company_id = ? AND code IN ('AR_MARKETPLACES', 'INV_MARKETPLACE')", [$companyId]));
        self::assertSame(['GROSS_MARGIN', 'GROSS_PROFIT'], $this->connection->fetchFirstColumn("SELECT code FROM pl_categories WHERE company_id = ? AND code LIKE 'GROSS%' ORDER BY code", [$companyId]));
    }

    public function testAdminAccountCreationCreatesFullSetup(): void
    {
        $company = (self::getContainer()->get(CreateAccountAction::class))($this->newUser(), 'plain-password', 'Admin Onboarding Co');

        self::assertSame(self::FULL_SETUP, $this->onboardingOf((string) $company->getId()));
    }

    public function testEventCarriesChosenProfile(): void
    {
        $profiles = [];
        self::getContainer()->get(EventDispatcherInterface::class)->addListener(
            CompanyCreatedEvent::class,
            static function (CompanyCreatedEvent $event) use (&$profiles): void {
                $profiles[] = $event->businessProfile;
            },
        );

        $company = (self::getContainer()->get(CreateCompanyOwnerAccountAction::class))($this->newUser(), 'plain-password', 'Universal Event Co', false, BusinessProfile::UNIVERSAL);

        self::assertSame([BusinessProfile::UNIVERSAL], $profiles);
        self::assertSame('UNIVERSAL', $this->connection->fetchOne('SELECT business_profile FROM companies WHERE id = ?', [(string) $company->getId()]));
    }

    public function testRepeatedEventDoesNotDuplicateAnything(): void
    {
        $company = (self::getContainer()->get(CreateCompanyOwnerAccountAction::class))($this->newUser(), 'plain-password', 'Repeat Co', false);
        $companyId = (string) $company->getId();

        $this->em->clear();
        self::getContainer()->get(EventDispatcherInterface::class)->dispatch(new CompanyCreatedEvent($companyId, $company->getBusinessProfile()));

        self::assertSame(self::FULL_SETUP, $this->onboardingOf($companyId));
    }

    public function testSubscriberFailureRollsBackCompany(): void
    {
        // Битый шаблон ОПиУ: подписчик Finance падает уже после того, как компания, счета и
        // статьи ДДС записаны в той же транзакции.
        self::getContainer()->set(DefaultPLCategoryTreeProvider::class, new DefaultPLCategoryTreeProvider(
            ['MARKETPLACE' => '/nonexistent/default_pl_tree.json'],
            self::getContainer()->get(PLCategoryTreeFileReader::class),
        ));

        try {
            (self::getContainer()->get(CreateCompanyOwnerAccountAction::class))($this->newUser(), 'plain-password', 'Rollback Co', false);
            self::fail('Создание компании должно упасть вместе с подписчиком.');
        } catch (\Throwable $e) {
            self::assertStringContainsString('default_pl_tree.json', $e->getMessage());
        }

        self::assertSame(0, (int) $this->connection->fetchOne("SELECT COUNT(*) FROM companies WHERE name = 'Rollback Co'"));
    }

    private function newUser(): User
    {
        return UserBuilder::aUser()->withId(Uuid::uuid7()->toString())->withEmail(Uuid::uuid7()->toString().'@example.test')->build();
    }

    /**
     * @return array<string, int|string>
     */
    private function onboardingOf(string $companyId): array
    {
        $list = fn (string $sql): string => implode(', ', $this->connection->fetchFirstColumn($sql, [$companyId]));

        return [
            'money_accounts' => $list("SELECT type || ':' || name || ':' || currency FROM money_account WHERE company_id = ? ORDER BY type"),
            'project' => $list("SELECT system_code || ':' || name FROM project_directions WHERE company_id = ?"),
            'responsibility_center' => $list("SELECT code || ':' || name FROM financial_responsibility_centers WHERE company_id = ?"),
            'cashflow_system_categories' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM cashflow_categories WHERE company_id = ? AND system_code IS NOT NULL', [$companyId]),
            'pl_categories' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM pl_categories WHERE company_id = ?', [$companyId]),
            'balance_articles' => (int) $this->connection->fetchOne('SELECT COUNT(*) FROM balance_articles WHERE company_id = ?', [$companyId]),
            'balance_accounts' => $list('SELECT code FROM balance_accounts WHERE company_id = ? ORDER BY code'),
        ];
    }
}
