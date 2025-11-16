<?php

declare(strict_types=1);

namespace App\Ai\Service\Prompt;

use App\Ai\Dto\CashflowAgentInput;

final class CashflowPromptBuilder
{
    /**
     * Пример структур:
     *  - totalsByCategory: ['Маркетинг' => ['inflow' => 120000.0, 'outflow' => 80000.0]]
     *  - dailyBalances: ['2025-11-01' => 123456.78, '2025-11-02' => 121000.12]
     *  - upcomingPayments: [{"dueDate":"2025-11-10","amount":150000,"description":"Аренда","counterparty":"ООО \"Аренда\""}]
     *
     * @return list<array{role: string, content: string}>
     */
    public function buildPrompt(CashflowAgentInput $input): array
    {
        $systemMessage = 'Ты — финансовый ассистент малого бизнеса. '
            .'Анализируй кэш-фло, платежный календарь и помогай предотвратить кассовые разрывы.';

        $payload = [
            'totals_by_category' => $input->totalsByCategory,
            'daily_balances' => $input->dailyBalances,
            'upcoming_payments' => $input->upcomingPayments,
            'avg_monthly_revenue' => $input->averageMonthlyRevenue,
            'avg_monthly_expenses' => $input->averageMonthlyExpenses,
        ];

        $userContent = <<<TEXT
Данные компании в формате JSON:
%s
Сформируй до 5 конкретных рекомендаций. Верни JSON массив структур вида {"title":"...","description":"...","severity":"low|medium|high","relatedEntityType":"?","relatedEntityId":"?"}.
Учитывай срочность платежей и уровни риска кассовых разрывов.
TEXT;

        return [
            ['role' => 'system', 'content' => $systemMessage],
            ['role' => 'user', 'content' => sprintf($userContent, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE))],
        ];
    }
}
