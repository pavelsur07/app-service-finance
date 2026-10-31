<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Command;

use App\Cash\Application\AutoApplyCashCategorySuggestionsAction;
use App\Cash\Application\RequestCashAiCategorizationAction;
use App\Cash\Command\CashAiCategorizeCommand;
use App\Cash\Entity\Accounts\MoneyAccount;
use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Infrastructure\Query\CashAiCategorizationCompaniesQuery;
use App\Company\Entity\Company;
use App\ServiceGateway\Facade\AiTaskFacadeInterface;
use App\Tests\Builders\Cash\CashTransactionBuilder;
use App\Tests\Builders\Company\CompanyBuilder;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\PersistingStoreInterface;
use Symfony\Component\Lock\Store\InMemoryStore;

/**
 * Cron-режим `app:cash:ai-categorize --all-enabled` (docs/tasks/ai/stages/prod-rollout.md).
 */
final class CashAiCategorizeCommandTest extends IntegrationTestCase
{
    private int $index = 9500;

    public function testRequiresExactlyOneScope(): void
    {
        $tester = $this->tester([]);

        self::assertSame(Command::INVALID, $tester->execute([]));
        self::assertSame(Command::INVALID, $tester->execute(['--company-id' => Uuid::uuid4()->toString(), '--all-enabled' => true]));
        self::assertSame(Command::INVALID, $tester->execute(['--company-id' => 'not-a-uuid']));
    }

    public function testAllEnabledRunsOnlyAllowlistedCompaniesWithRecentOperations(): void
    {
        $enabled = $this->company('-3 days');
        $disabled = $this->company('-3 days');
        $stale = $this->company('-200 days');

        $tester = $this->tester([(string) $enabled->getId(), (string) $stale->getId()]);

        self::assertSame(Command::SUCCESS, $tester->execute(['--all-enabled' => true]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('ai-categorize: '.$enabled->getId().': ', $display);
        self::assertStringNotContainsString((string) $disabled->getId(), $display);
        self::assertStringNotContainsString((string) $stale->getId(), $display);
        self::assertStringContainsString('ai-categorize: 1 enabled companies, 0 failed', $display);
    }

    public function testFailureOfOneCompanyDoesNotStopTheOthers(): void
    {
        $broken = $this->company('-3 days');
        $healthy = $this->company('-3 days');

        $tester = $this->tester([(string) $broken->getId(), (string) $healthy->getId()], failingKey: (string) $broken->getId());

        self::assertSame(Command::FAILURE, $tester->execute(['--all-enabled' => true]));
        $display = $tester->getDisplay();
        self::assertStringContainsString('ai-categorize: '.$broken->getId().' failed', $display);
        self::assertStringContainsString('ai-categorize: '.$healthy->getId().': ', $display);
        self::assertStringContainsString('ai-categorize: 2 enabled companies, 1 failed', $display);
    }

    /** @param list<string> $allowlist */
    private function tester(array $allowlist, ?string $failingKey = null): CommandTester
    {
        $container = self::getContainer();
        $facade = new class($allowlist) implements AiTaskFacadeInterface {
            /** @param list<string> $allowlist */
            public function __construct(private array $allowlist)
            {
            }

            public function requestTask(string $companyId, string $taskType, array $payload, string $idempotencyKey, ?string $requestedByUserId = null): ?string
            {
                return null;
            }

            public function taskIdFor(string $companyId, string $taskType, string $idempotencyKey): string
            {
                return Uuid::uuid4()->toString();
            }

            public function isEnabledForCompany(string $companyId): bool
            {
                return \in_array($companyId, $this->allowlist, true);
            }
        };
        $store = new class(new InMemoryStore(), $failingKey) implements PersistingStoreInterface {
            public function __construct(private InMemoryStore $inner, private ?string $failingKey)
            {
            }

            public function save(Key $key): void
            {
                if (null !== $this->failingKey && str_contains((string) $key, $this->failingKey)) {
                    throw new \RuntimeException('store unavailable');
                }
                $this->inner->save($key);
            }

            public function delete(Key $key): void
            {
                $this->inner->delete($key);
            }

            public function exists(Key $key): bool
            {
                return $this->inner->exists($key);
            }

            public function putOffExpiration(Key $key, float $ttl): void
            {
                $this->inner->putOffExpiration($key, $ttl);
            }
        };

        $command = new CashAiCategorizeCommand(
            $container->get(AutoApplyCashCategorySuggestionsAction::class),
            $container->get(RequestCashAiCategorizationAction::class),
            $container->get(CashAiCategorizationCompaniesQuery::class),
            $facade,
            new LockFactory($store),
            new MockClock(),
            new NullLogger(),
        );

        return new CommandTester($command);
    }

    private function company(string $operationAt): Company
    {
        ++$this->index;
        $user = UserBuilder::aUser()->withIndex($this->index)->build();
        $company = CompanyBuilder::aCompany()->withIndex($this->index)->withOwner($user)->build();
        $account = new MoneyAccount(Uuid::uuid4()->toString(), $company, MoneyAccountType::BANK, 'Main', 'RUB');
        $account->setOpeningBalance('0');
        $account->setOpeningBalanceDate(new \DateTimeImmutable('2025-01-01'));
        $tx = CashTransactionBuilder::aCashTransaction()->withId(Uuid::uuid4()->toString())->forCompany($company)->withMoneyAccount($account)->build();
        $tx->setOccurredAt(new \DateTimeImmutable($operationAt));
        foreach ([$user, $company, $account, $tx] as $entity) {
            $this->em->persist($entity);
        }
        $this->em->flush();

        return $company;
    }
}
