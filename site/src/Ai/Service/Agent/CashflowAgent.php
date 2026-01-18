<?php

declare(strict_types=1);

namespace App\Ai\Service\Agent;

use App\Ai\Dto\CashflowAgentInput;
use App\Ai\Entity\AiAgent as AiAgentEntity;
use App\Ai\Entity\AiRun;
use App\Ai\Entity\AiSuggestion;
use App\Ai\Enum\AiAgentType;
use App\Ai\Enum\AiSuggestionSeverity;
use App\Ai\Repository\AiRunRepository;
use App\Ai\Repository\AiSuggestionRepository;
use App\Ai\Service\Llm\LlmClient;
use App\Ai\Service\Llm\LlmOptions;
use App\Ai\Service\Prompt\CashflowPromptBuilder;
use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\PaymentPlan\PaymentPlanRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Entity\Company;
use App\Enum\CashDirection;
use DateInterval;
use DateTimeImmutable;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;
use Throwable;

#[AutoconfigureTag('app.ai.agent')]
final class CashflowAgent implements AiAgentInterface
{
    public function __construct(
        private readonly CashTransactionRepository $cashTransactionRepository,
        private readonly MoneyAccountRepository $moneyAccountRepository,
        private readonly PaymentPlanRepository $paymentPlanRepository,
        private readonly CashflowPromptBuilder $promptBuilder,
        private readonly LlmClient $llmClient,
        private readonly AiRunRepository $runRepository,
        private readonly AiSuggestionRepository $suggestionRepository,
    ) {
    }

    public function supports(AiAgentType $type): bool
    {
        return AiAgentType::CASHFLOW === $type;
    }

    public function run(AiAgentEntity $agent): void
    {
        if (!$this->supports($agent->getType())) {
            return;
        }

        $run = new AiRun($agent);
        $this->runRepository->save($run);

        try {
            $input = $this->buildInput($agent->getCompany());
            $run->attachInputSummary($this->summarizeInput($input));

            $messages = $this->promptBuilder->buildPrompt($input);
            $response = $this->llmClient->chat($messages, LlmOptions::forFinancialAssistant());

            $this->persistSuggestions($agent, $run, $response->getJson(), $response->getRaw());

            $run->markAsSucceeded($response->getRaw());
        } catch (Throwable $exception) {
            throw $exception;
        } finally {
            $this->runRepository->save($run, true);
        }
    }

    private function buildInput(Company $company): CashflowAgentInput
    {
        $periodEnd = new DateTimeImmutable('today');
        $periodStart = $periodEnd->sub(new DateInterval('P30D'));

        $totalsByCategory = $this->aggregateTotalsByCategory($company, $periodStart, $periodEnd);
        $dailyBalances = $this->aggregateDailyBalances($company, $periodStart, $periodEnd);
        $upcomingPayments = $this->collectUpcomingPayments($company, $periodEnd);
        [$avgRevenue, $avgExpenses] = $this->calculateMonthlyAverages($company);

        return new CashflowAgentInput(
            $totalsByCategory,
            $dailyBalances,
            $upcomingPayments,
            $avgRevenue,
            $avgExpenses,
        );
    }

    /**
     * @return array<string, array{inflow: float, outflow: float}>
     */
    private function aggregateTotalsByCategory(Company $company, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        $qb = $this->cashTransactionRepository->createQueryBuilder('transaction')
            ->select("COALESCE(category.name, 'Без категории') AS category")
            ->addSelect(sprintf(
                "SUM(CASE WHEN transaction.direction = '%s' THEN transaction.amount ELSE 0 END) AS inflow",
                CashDirection::INFLOW->value
            ))
            ->addSelect(sprintf(
                "SUM(CASE WHEN transaction.direction = '%s' THEN transaction.amount ELSE 0 END) AS outflow",
                CashDirection::OUTFLOW->value
            ))
            ->leftJoin('transaction.cashflowCategory', 'category')
            ->andWhere('transaction.company = :company')
            ->andWhere('transaction.occurredAt BETWEEN :from AND :to')
            ->setParameter('company', $company)
            ->setParameter('from', $from)
            ->setParameter('to', $to)
            ->groupBy('category')
            ->orderBy('category', 'ASC');

        $results = $qb->getQuery()->getArrayResult();
        $totals = [];
        foreach ($results as $row) {
            $categoryName = (string) ($row['category'] ?? 'Без категории');
            $totals[$categoryName] = [
                'inflow' => (float) $row['inflow'],
                'outflow' => (float) $row['outflow'],
            ];
        }

        return $totals;
    }

    /**
     * @return array<string, float>
     */
    private function aggregateDailyBalances(Company $company, DateTimeImmutable $from, DateTimeImmutable $to): array
    {
        // TODO: заменить на реальный сервис cash balance, когда он появится в проекте.
        $qb = $this->cashTransactionRepository->createQueryBuilder('transaction')
            ->select('transaction.occurredAt AS day')
            ->addSelect(sprintf(
                "SUM(CASE WHEN transaction.direction = '%s' THEN transaction.amount ELSE transaction.amount * -1) AS net",
                CashDirection::INFLOW->value
            ))
            ->andWhere('transaction.company = :company')
            ->andWhere('transaction.occurredAt BETWEEN :from AND :to')
            ->groupBy('day')
            ->orderBy('day', 'ASC')
            ->setParameter('company', $company)
            ->setParameter('from', $from)
            ->setParameter('to', $to);

        $rows = $qb->getQuery()->getArrayResult();
        $balances = [
            $to->format('Y-m-d') => $this->sumCurrentBalances($company),
        ];
        $running = 0.0;
        foreach ($rows as $row) {
            $day = $row['day'];
            $key = $day instanceof DateTimeImmutable ? $day->format('Y-m-d') : (string) $day;
            $running += (float) $row['net'];
            $balances[$key] = round($running, 2);
        }

        return $balances;
    }

    /**
     * @return list<array{dueDate: string, amount: float, description: string, counterparty?: string}>
     */
    private function collectUpcomingPayments(Company $company, DateTimeImmutable $periodEnd): array
    {
        $horizon = $periodEnd->add(new DateInterval('P21D'));
        $plans = $this->paymentPlanRepository->findPlannedByCompanyAndPeriod($company, $periodEnd, $horizon);

        $payments = [];
        foreach ($plans as $plan) {
            $payments[] = [
                'dueDate' => $plan->getPlannedAt()->format('Y-m-d'),
                'amount' => (float) $plan->getAmount(),
                'description' => $plan->getCashflowCategory()->getName(),
                'counterparty' => $plan->getCounterparty()?->getName(),
            ];
        }

        return $payments;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function calculateMonthlyAverages(Company $company): array
    {
        $periodEnd = new DateTimeImmutable('today');
        $periodStart = $periodEnd->sub(new DateInterval('P90D'));

        $qb = $this->cashTransactionRepository->createQueryBuilder('transaction')
            ->select(sprintf(
                "SUM(CASE WHEN transaction.direction = '%s' THEN transaction.amount ELSE 0 END) AS inflow",
                CashDirection::INFLOW->value
            ))
            ->addSelect(sprintf(
                "SUM(CASE WHEN transaction.direction = '%s' THEN transaction.amount ELSE 0 END) AS outflow",
                CashDirection::OUTFLOW->value
            ))
            ->andWhere('transaction.company = :company')
            ->andWhere('transaction.occurredAt BETWEEN :from AND :to')
            ->setParameter('company', $company)
            ->setParameter('from', $periodStart)
            ->setParameter('to', $periodEnd);

        $totals = $qb->getQuery()->getSingleResult();
        $monthsInterval = $periodStart->diff($periodEnd);
        $months = max(1, (int) floor(($monthsInterval->days ?? 0) / 30));

        return [
            (float) ($totals['inflow'] ?? 0) / $months,
            (float) ($totals['outflow'] ?? 0) / $months,
        ];
    }

    private function summarizeInput(CashflowAgentInput $input): string
    {
        $summary = [
            'totals_by_category' => $input->totalsByCategory,
            'upcoming_payments' => $input->upcomingPayments,
        ];

        return json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    private function sumCurrentBalances(Company $company): float
    {
        $accounts = $this->moneyAccountRepository->findBy(['company' => $company]);
        $total = 0.0;
        foreach ($accounts as $account) {
            $total += (float) $account->getCurrentBalance();
        }

        return $total;
    }

    private function persistSuggestions(
        AiAgentEntity $agent,
        AiRun $run,
        ?array $llmPayload,
        string $raw
    ): void {
        $company = $agent->getCompany();

        $suggestionsData = $this->normalizeResponse($llmPayload, $raw);
        if ([] === $suggestionsData) {
            $suggestionsData[] = [
                'title' => 'Нет структурированных рекомендаций',
                'description' => 'LLM не вернул JSON-ответ. Проверьте логи и промпт.',
                'severity' => AiSuggestionSeverity::LOW->value,
            ];
        }

        foreach ($suggestionsData as $item) {
            $severity = AiSuggestionSeverity::tryFrom(strtolower((string) ($item['severity'] ?? 'low')))
                ?? AiSuggestionSeverity::LOW;

            $suggestion = new AiSuggestion(
                $company,
                $agent,
                $run,
                (string) ($item['title'] ?? 'Рекомендация'),
                (string) ($item['description'] ?? ''),
                $severity,
            );
            $suggestion->relateTo(
                isset($item['relatedEntityType']) ? (string) $item['relatedEntityType'] : null,
                isset($item['relatedEntityId']) ? (string) $item['relatedEntityId'] : null,
            );

            $this->suggestionRepository->save($suggestion);
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function normalizeResponse(?array $payload, string $raw): array
    {
        if (isset($payload[0]) && \is_array($payload[0])) {
            return $payload;
        }

        if (isset($payload['suggestions']) && \is_array($payload['suggestions'])) {
            return $payload['suggestions'];
        }

        $decoded = json_decode($raw, true);
        if (\is_array($decoded)) {
            return $decoded;
        }

        return [];
    }
}
