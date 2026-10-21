<?php

declare(strict_types=1);

namespace App\Tests\Integration\Company\Command;

use App\Company\Command\DirectoryIntegrityCheckCommand;
use App\Company\Entity\Company;
use App\Company\Infrastructure\Repository\CompanyRepository;
use App\Finance\Application\Action\EnsureDefaultPLTreeAction;
use App\Finance\Enum\PLCategoryType;
use App\Tests\Builders\Balance\BalanceCategoryBuilder;
use App\Tests\Builders\Cash\CashflowCategoryBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Builders\Finance\PLCategoryBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Monolog\Handler\TestHandler;
use Monolog\Level;
use Monolog\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

final class DirectoryIntegrityCheckCommandTest extends IntegrationTestCase
{
    public function testDuplicatesInAllDirectoriesFailWithOneAggregatedError(): void
    {
        $company = $this->company(1);
        $revenue = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Выручка')->withCode(null)->withType(PLCategoryType::SUBTOTAL)->build();
        $costs = PLCategoryBuilder::aPLCategory()->forCompany($company)->withName('Расходы')->withCode(null)->withType(PLCategoryType::SUBTOTAL)->build();
        $this->persist(
            $revenue,
            $costs,
            PLCategoryBuilder::aPLCategory()->forCompany($company)->withParent($revenue)->withName('Прочие')->withCode(null)->build(),
            PLCategoryBuilder::aPLCategory()->forCompany($company)->withParent($revenue)->withName(' прочие ')->withCode(null)->build(),
            // Одинаковое название у разных родителей — не дубль.
            PLCategoryBuilder::aPLCategory()->forCompany($company)->withParent($costs)->withName('Прочие')->withCode(null)->build(),
            CashflowCategoryBuilder::aCashflowCategory()->withIndex(1)->withCompany($company)->withName('Аренда')->build(),
            CashflowCategoryBuilder::aCashflowCategory()->withIndex(2)->withCompany($company)->withName('Аренда')->build(),
            BalanceCategoryBuilder::aBalanceCategory()->withIndex(1)->withCompanyId((string) $company->getId())->withName('Склад')->build(),
            BalanceCategoryBuilder::aBalanceCategory()->withIndex(2)->withCompanyId((string) $company->getId())->withName('Склад')->build(),
        );
        $id = (string) $company->getId();

        [$exit, $display, $handler] = $this->runCheck();

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString("duplicate balance  $id «Склад» ×2 (корень)", $display);
        self::assertStringContainsString("duplicate dds      $id «Аренда» ×2 (корень)", $display);
        self::assertMatchesRegularExpression('/duplicate pl\s+'.$id.' «\s?[Пп]рочие\s?» ×2 в «Выручка»/u', $display);
        self::assertStringContainsString("info      pl       $id своё дерево без кодов шаблона", $display);
        self::assertStringContainsString('duplicates 3 in 1 companies', $display);

        $errors = array_values(array_filter($handler->getRecords(), static fn ($r): bool => Level::Error === $r->level));
        self::assertCount(1, $errors);
        self::assertSame(['duplicates' => 3, 'companies' => 1, 'byDirectory' => ['balance' => 1, 'dds' => 1, 'pl' => 1]], $errors[0]->context);
    }

    public function testCleanDirectoriesPassAndTemplateTreeIsNotNoted(): void
    {
        $company = $this->company(1);
        self::assertSame(72, (self::getContainer()->get(EnsureDefaultPLTreeAction::class))((string) $company->getId()));

        [$exit, $display, $handler] = $this->runCheck();

        self::assertSame(Command::SUCCESS, $exit, $display);
        self::assertStringNotContainsString('info', $display);
        self::assertStringContainsString('duplicates 0 in 0 companies', $display);
        self::assertFalse($handler->hasErrorRecords());
    }

    /**
     * @return array{int, string, TestHandler}
     */
    private function runCheck(): array
    {
        $handler = new TestHandler();
        $command = new DirectoryIntegrityCheckCommand(
            self::getContainer()->get(CompanyRepository::class),
            self::getContainer()->get(EventDispatcherInterface::class),
            new Logger('test', [$handler]),
        );
        $tester = new CommandTester($command);
        $exit = $tester->execute([]);

        return [$exit, $tester->getDisplay(), $handler];
    }

    private function company(int $index): Company
    {
        $owner = UserBuilder::aUser()->withIndex($index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($index)->withOwner($owner)->build();
        $this->persist($owner, $company);

        return $company;
    }

    private function persist(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();
    }
}
