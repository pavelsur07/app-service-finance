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
use App\Cash\Facade\CashFacade;
use App\Company\Entity\Company;
use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

#[AutoconfigureTag('app.ai.agent')]
final class CashflowAgent implements AiAgentInterface
{
    public function __construct(
        private readonly CashFacade $cashFacade,
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
        } catch (\Throwable $exception) {
            throw $exception;
        } finally {
            $this->runRepository->save($run, true);
        }
    }

    private function buildInput(Company $company): CashflowAgentInput
    {
        $periodEnd = new \DateTimeImmutable('today');
        $periodStart = $periodEnd->sub(new \DateInterval('P30D'));

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
    private function aggregateTotalsByCategory(Company $company, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $totals = [];
        foreach ($this->cashFacade->sumTurnoverByCategoryNameIncludingDeleted((string) $company->getId(), $from, $to) as $row) {
            $totals[$row->categoryName] = [
                'inflow' => (float) $row->inflow,
                'outflow' => (float) $row->outflow,
            ];
        }

        return $totals;
    }

    /**
     * @return array<string, float>
     */
    private function aggregateDailyBalances(Company $company, \DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        // TODO: заменить на реальный сервис cash balance, когда он появится в проекте.
        $balances = [
            $to->format('Y-m-d') => $this->sumCurrentBalances($company),
        ];
        $running = 0.0;
        foreach ($this->cashFacade->sumNetByDayIncludingDeleted((string) $company->getId(), $from, $to) as $row) {
            $running += (float) $row->amount;
            $balances[$row->date] = round($running, 2);
        }

        return $balances;
    }

    /**
     * @return list<array{dueDate: string, amount: float, description: string, counterparty?: string|null}>
     */
    private function collectUpcomingPayments(Company $company, \DateTimeImmutable $periodEnd): array
    {
        $horizon = $periodEnd->add(new \DateInterval('P21D'));

        $payments = [];
        foreach ($this->cashFacade->listPlannedPayments((string) $company->getId(), $periodEnd, $horizon) as $plan) {
            $payments[] = [
                'dueDate' => $plan->plannedAt->format('Y-m-d'),
                'amount' => (float) $plan->amount,
                'description' => $plan->categoryName,
                'counterparty' => $plan->counterpartyName,
            ];
        }

        return $payments;
    }

    /**
     * @return array{0: float, 1: float}
     */
    private function calculateMonthlyAverages(Company $company): array
    {
        $periodEnd = new \DateTimeImmutable('today');
        $periodStart = $periodEnd->sub(new \DateInterval('P90D'));

        $totals = $this->cashFacade->sumTurnoverIncludingDeleted((string) $company->getId(), $periodStart, $periodEnd);
        $monthsInterval = $periodStart->diff($periodEnd);
        $months = max(1, (int) floor(($monthsInterval->days ?? 0) / 30));

        return [
            (float) $totals['inflow'] / $months,
            (float) $totals['outflow'] / $months,
        ];
    }

    private function summarizeInput(CashflowAgentInput $input): string
    {
        $summary = [
            'totals_by_category' => $input->totalsByCategory,
            'upcoming_payments' => $input->upcomingPayments,
        ];

        return json_encode($summary, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE) ?: '[]';
    }

    private function sumCurrentBalances(Company $company): float
    {
        $total = 0.0;
        foreach ($this->cashFacade->listMoneyAccounts((string) $company->getId(), activeOnly: false) as $account) {
            $total += (float) $account->currentBalance;
        }

        return $total;
    }

    private function persistSuggestions(
        AiAgentEntity $agent,
        AiRun $run,
        ?array $llmPayload,
        string $raw,
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
