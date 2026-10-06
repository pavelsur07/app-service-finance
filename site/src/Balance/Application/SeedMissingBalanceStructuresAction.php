<?php

declare(strict_types=1);

namespace App\Balance\Application;

use App\Balance\Infrastructure\Query\CompaniesWithoutBalanceStructureQuery;
use App\Company\Facade\CompanyFacade;
use Psr\Log\LoggerInterface;

/**
 * Разовый бэкфилл стартовой структуры баланса для компаний, созданных до того, как сид
 * стал запускаться при любом создании компании. Без $execute ничего не пишет.
 */
final readonly class SeedMissingBalanceStructuresAction
{
    public function __construct(
        private CompanyFacade $companies,
        private CompaniesWithoutBalanceStructureQuery $missing,
        private SeedBalanceStructureAction $seed,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * @return array{companies: int, candidates: list<string>, seeded: int, failed: int}
     *
     * @throws \InvalidArgumentException если --execute запущен без сверки с dry-run или данные разошлись
     */
    public function __invoke(bool $execute, ?int $expectedCount): array
    {
        $companyIds = $this->companies->getAllActiveCompanyIds();
        $candidates = ($this->missing)($companyIds);
        $result = ['companies' => \count($companyIds), 'candidates' => $candidates, 'seeded' => 0, 'failed' => 0];

        if (!$execute) {
            return $result;
        }
        if (null === $expectedCount) {
            throw new \InvalidArgumentException('Для --execute нужен --expected-count из последнего dry-run.');
        }
        if (\count($candidates) !== $expectedCount) {
            throw new \InvalidArgumentException(sprintf('Кандидатов %d, ожидалось %d: данные изменились, повторите dry-run.', \count($candidates), $expectedCount));
        }

        $this->logger->info('Бэкфилл структуры баланса: старт', ['candidates' => \count($candidates)]);
        foreach ($candidates as $companyId) {
            try {
                // Идемпотентно и в своей транзакции: гонка с ручным сидом даёт false, а не дубль.
                if (($this->seed)($companyId)) {
                    ++$result['seeded'];
                }
            } catch (\Throwable $e) {
                // Останов на первой ошибке: после сбоя EntityManager может быть закрыт; повторный запуск продолжит с оставшихся.
                ++$result['failed'];
                $this->logger->error('Бэкфилл структуры баланса прерван ошибкой', ['seeded' => $result['seeded'], 'remaining' => \count($candidates) - $result['seeded'], 'exception' => $e]);
                break;
            }
        }
        $this->logger->info('Бэкфилл структуры баланса: финиш', ['seeded' => $result['seeded'], 'failed' => $result['failed']]);

        return $result;
    }
}
