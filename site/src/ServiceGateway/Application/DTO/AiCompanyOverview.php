<?php

declare(strict_types=1);

namespace App\ServiceGateway\Application\DTO;

/**
 * ИИ кабинета для админки: подключение, лимиты и расход месяца, токены, последние запуски.
 */
final readonly class AiCompanyOverview
{
    /**
     * @param list<AiRunView> $runs последние запуски, новые первыми
     */
    public function __construct(
        public bool $serviceEnabled,
        /** null — компания не подключена. */
        public ?\DateTimeImmutable $enabledAt,
        public AiCompanyBudget $budget,
        public int $resultsThisMonth,
        public int $inputTokensThisMonth,
        public int $outputTokensThisMonth,
        public array $runs,
    ) {
    }

    public function connected(): bool
    {
        return null !== $this->enabledAt;
    }

    public function lastRun(): ?AiRunView
    {
        return $this->runs[0] ?? null;
    }
}
