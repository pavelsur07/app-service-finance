<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Command;

use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Entity\Transaction\CashTransaction;
use App\Cash\Entity\Transaction\CashTransactionSplit;
use App\Cash\Enum\Transaction\CashDirection;
use App\Cash\Enum\Transaction\CashTransactionSplitSource;
use App\Company\Entity\Company;
use App\Tests\Builders\Cash\MoneyAccountBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Вывод команды попадает в логи и в отчёты, поэтому проверяется не только вердикт,
 * но и то, что в него не утекают обороты и полные идентификаторы прода.
 */
final class VerifyCashTransactionSplitsCommandTest extends IntegrationTestCase
{
    private ?MoneyAccount $account = null;

    public function testTotalsComparisonIsSkippedUntilBackfillCompletes(): void
    {
        $company = $this->company();
        $category = $this->category($company, 'Аренда');
        $this->transaction($company, '1000.00', $category);
        $this->em->flush();

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());

        $output = $tester->getDisplay();
        self::assertStringContainsString('Сверка итогов пропущена', $output);
        self::assertStringNotContainsString('по колонке', $output);
        self::assertStringNotContainsString('1000.00', $output, 'Обороты не должны попадать в вывод.');
        self::assertStringNotContainsString((string) $category->getId(), $output, 'Полные ID не должны попадать в вывод.');
    }

    public function testGreenRunWhenSplitsMirrorTheColumn(): void
    {
        $company = $this->company();
        $category = $this->category($company, 'Аренда');
        $transaction = $this->transaction($company, '1000.00', $category);
        $transaction->replaceSplits([
            new CashTransactionSplit($transaction, $category, '1000.00', CashTransactionSplitSource::MANUAL),
        ]);
        $this->em->flush();

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode());
        self::assertStringContainsString('Сверка пройдена', $tester->getDisplay());
    }

    /**
     * Оба кода системной «Не распределено»: на проде у одной компании остался legacy,
     * и его потеря из проверки вернула бы вечно красный гейт именно там.
     */
    #[DataProvider('unallocatedCodeProvider')]
    public function testMultiSplitDoesNotBreakTheGate(string $systemCode): void
    {
        $company = $this->company();
        $unallocated = $this->category($company, 'Не распределено');
        if (CashflowCategory::isSystemCode($systemCode)) {
            $unallocated->markAsSystem($systemCode);
        } else {
            $unallocated->setSystemCode($systemCode);
        }
        $this->em->flush();

        // Так выглядит операция после формы разбивки: несколько строк, колонка
        // спроецирована в «Не распределено». До правки это делало гейт вечно красным.
        $transaction = $this->transaction($company, '1000.00', $unallocated);
        $transaction->replaceSplits([
            new CashTransactionSplit($transaction, $this->category($company, 'Аренда'), '600.00', CashTransactionSplitSource::MANUAL),
            new CashTransactionSplit($transaction, $this->category($company, 'Реклама'), '400.00', CashTransactionSplitSource::MANUAL),
        ]);
        $this->em->flush();

        $tester = $this->runCommand();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), 'Разбивка не должна валить сверку.');
        self::assertStringContainsString('совпадает с суммой операций', $tester->getDisplay());
    }

    public function testMultiSplitWithRealCategoryInColumnIsRejected(): void
    {
        $company = $this->company();
        $rent = $this->category($company, 'Аренда');

        // Колонка указывает на настоящую статью, а строк несколько — состав, который
        // код не создаёт: отчёт по колонке отнёс бы всю сумму на одну статью.
        $transaction = $this->transaction($company, '1000.00', $rent);
        $transaction->replaceSplits([
            new CashTransactionSplit($transaction, $rent, '600.00', CashTransactionSplitSource::MANUAL),
            new CashTransactionSplit($transaction, $this->category($company, 'Реклама'), '400.00', CashTransactionSplitSource::MANUAL),
        ]);
        $this->em->flush();

        $tester = $this->runCommand();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('column_projection_mismatch', $tester->getDisplay());
    }

    public function testTotalsMismatchIsReportedWithoutAmountsAndFullIds(): void
    {
        $company = $this->company();

        // Строки у операции без категории: сумма строк есть, а сама операция в разрез
        // по категориям не попадает — итоги расходятся, и это надо показать без денег.
        $orphan = $this->transaction($company, '1000.00', null);
        $orphan->replaceSplits([
            new CashTransactionSplit($orphan, $this->category($company, 'Аренда'), '1000.00', CashTransactionSplitSource::MANUAL),
        ]);
        $this->em->flush();

        $tester = $this->runCommand();
        $output = $tester->getDisplay();

        self::assertSame(Command::FAILURE, $tester->getStatusCode());
        self::assertStringContainsString('Групп с расхождением', $output);
        self::assertStringNotContainsString('1000.00', $output, 'Суммы не должны выводиться.');
        self::assertStringNotContainsString((string) $company->getId(), $output, 'Полный ID не должен выводиться.');
        self::assertStringContainsString(substr((string) $company->getId(), 0, 8), $output);
    }

    /**
     * Проверяются оба кода системной «Не распределено»: на проде у одной компании остался
     * legacy-код, и потеря его из списка исключений вернула бы вечно красный гейт.
     */
    #[DataProvider('unallocatedCodeProvider')]
    public function testUnallocatedSplitsAreExcludedFromProvenanceCheck(string $systemCode): void
    {
        $company = $this->company();

        // Так транзакция попадает в «Не распределено» на проде: fallback воркера правилом
        // не является и следа autoRules в аудите не оставляет, поэтому провенанс-резолвер
        // назовёт категорию ручной, а строка при этом помечена auto. Гейт от этого краснеть
        // не должен — категория и есть очередь на разбор.
        $unallocated = $this->category($company, 'Не распределено');

        // Каждый код ставится тем же путём, каким он появился в реальности: актуальный —
        // через markAsSystem(), legacy — обычным сеттером, потому что он предшествует
        // списку зарезервированных и markAsSystem() его не примет.
        if (CashflowCategory::isSystemCode($systemCode)) {
            $unallocated->markAsSystem($systemCode);
        } else {
            $unallocated->setSystemCode($systemCode);
        }
        $this->em->flush();

        $transaction = $this->transaction($company, '1000.00', $unallocated);
        $transaction->replaceSplits([
            new CashTransactionSplit($transaction, $unallocated, '1000.00', CashTransactionSplitSource::AUTO),
        ]);
        $this->em->flush();

        $tester = $this->runCommand();
        $output = $tester->getDisplay();

        self::assertSame(Command::SUCCESS, $tester->getStatusCode(), 'Нераспределённая строка не должна валить сверку.');
        self::assertStringContainsString('пропущено нераспределённых — 1', $output);
        self::assertStringContainsString('Провенанс source проверен у 0 строк из 0', $output);
    }

    public function testSkippedCountIsLimitedToExpandPhaseScope(): void
    {
        $company = $this->company();

        $unallocated = $this->category($company, 'Не распределено');
        $unallocated->markAsSystem(CashflowCategory::CODE_UNALLOCATED);
        $this->em->flush();

        // В области сверки: одна строка на транзакцию.
        $single = $this->transaction($company, '1000.00', $unallocated);
        $single->replaceSplits([
            new CashTransactionSplit($single, $unallocated, '1000.00', CashTransactionSplitSource::AUTO),
        ]);

        // Вне области: мультиразбивка, где одна из строк тоже нераспределённая. Она не
        // участвует в сверке провенанса, поэтому и в счётчик пропущенных попадать не должна.
        // Прежний запрос считал по всей таблице и показал бы здесь 2 вместо 1.
        $multi = $this->transaction($company, '1000.00', $unallocated);
        $multi->replaceSplits([
            new CashTransactionSplit($multi, $unallocated, '600.00', CashTransactionSplitSource::AUTO),
            new CashTransactionSplit($multi, $this->category($company, 'Аренда'), '400.00', CashTransactionSplitSource::MANUAL),
        ]);
        $this->em->flush();

        $output = $this->runCommand()->getDisplay();

        self::assertStringContainsString('пропущено нераспределённых — 1', $output);
    }

    /** @return iterable<string, array{string}> */
    public static function unallocatedCodeProvider(): iterable
    {
        yield 'актуальный код' => [CashflowCategory::CODE_UNALLOCATED];
        yield 'legacy-код с прода' => [CashflowCategory::SYSTEM_UNALLOCATED];
    }

    private function runCommand(): CommandTester
    {
        $application = new Application(self::$kernel);
        $tester = new CommandTester($application->find('app:cash:verify-transaction-splits'));
        $tester->execute([]);

        return $tester;
    }

    private function company(): Company
    {
        $owner = UserBuilder::aUser()->withId(Uuid::uuid4()->toString())->build();
        $company = CompanyBuilder::aCompany()
            ->withId(Uuid::uuid4()->toString())
            ->withOwner($owner)
            ->build();

        $this->em->persist($owner);
        $this->em->persist($company);
        $this->em->flush();

        return $company;
    }

    private function category(Company $company, string $name): CashflowCategory
    {
        $category = new CashflowCategory(Uuid::uuid4()->toString(), $company);
        $category->setName($name);
        $this->em->persist($category);
        $this->em->flush();

        return $category;
    }

    private function transaction(Company $company, string $amount, ?CashflowCategory $category): CashTransaction
    {
        // Счёт создаётся один на компанию: у money_account уникальны (company_id, name),
        // а билдер даёт всем одинаковое имя по умолчанию.
        if (null === $this->account) {
            $this->account = MoneyAccountBuilder::aMoneyAccount()
                ->withId(Uuid::uuid4()->toString())
                ->forCompany($company)
                ->build();
            $this->em->persist($this->account);
            $this->em->flush();
        }
        $account = $this->account;

        $transaction = new CashTransaction(
            Uuid::uuid4()->toString(),
            $company,
            $account,
            CashDirection::OUTFLOW,
            $amount,
            'RUB',
            new \DateTimeImmutable('2026-01-15'),
        );
        $transaction->setCashflowCategory($category);
        $this->em->persist($transaction);

        return $transaction;
    }
}
