<?php

declare(strict_types=1);

namespace App\Telegram\Command;

use App\Cash\Repository\Accounts\MoneyAccountRepository;
use App\Cash\Repository\Transaction\CashTransactionRepository;
use App\Entity\Company;
use App\Telegram\Entity\ReportSubscription;
use App\Telegram\Repository\TelegramBotRepository;
use DateInterval;
use DateTimeImmutable;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Throwable;

#[AsCommand(
    name: 'app:telegram:send-reports',
    description: 'Отправляет отчеты по активным подпискам ReportSubscription. Cron будет запускать по расписанию (периодичность добавим позже).',
)]
final class SendTelegramReportsCommand extends Command
{
    public function __construct(
        private readonly EntityManagerInterface $entityManager,
        private readonly TelegramBotRepository $botRepository,
        private readonly HttpClientInterface $httpClient,
        private readonly MoneyAccountRepository $moneyAccountRepository,
        private readonly CashTransactionRepository $cashTransactionRepository,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $bot = $this->botRepository->findActiveBot();
        if (null === $bot) {
            $output->writeln('<error>Активный Telegram-бот не найден.</error>');

            return Command::FAILURE;
        }

        $subscriptionRepo = $this->entityManager->getRepository(ReportSubscription::class);
        $subscriptions = $subscriptionRepo->findBy(['isEnabled' => true]);

        if ([] === $subscriptions) {
            $output->writeln('<comment>Активных подписок нет.</comment>');

            return Command::SUCCESS;
        }

        foreach ($subscriptions as $subscription) {
            if (!$subscription instanceof ReportSubscription) {
                continue;
            }

            try {
                $text = $this->buildReportText($subscription);
                $chatId = $subscription->getTelegramUser()->getTgUserId();

                $response = $this->httpClient->request('POST', sprintf('https://api.telegram.org/bot%s/sendMessage', $bot->getToken()), [
                    'json' => [
                        'chat_id' => $chatId,
                        'text' => $text,
                    ],
                ]);

                $statusCode = $response->getStatusCode();
                if ($statusCode >= 200 && $statusCode < 300) {
                    $output->writeln(sprintf('<info>Отчет отправлен: %s -> %s</info>', $subscription->getCompany()->getName(), $chatId));
                } else {
                    $output->writeln(sprintf('<error>Не удалось отправить отчет для %s (chat %s). Код %d</error>', $subscription->getCompany()->getName(), $chatId, $statusCode));
                }
            } catch (Throwable $exception) {
                $output->writeln(sprintf('<error>Ошибка при отправке отчета для %s: %s</error>', $subscription->getCompany()->getName(), $exception->getMessage()));
            }
        }

        return Command::SUCCESS;
    }

    private function buildReportText(ReportSubscription $subscription): string
    {
        $company = $subscription->getCompany();
        $metricsMask = $subscription->getMetricsMask();

        $lines = [
            sprintf('Отчет по компании: %s', $company->getName()),
            '---',
        ];

        if (0 === $metricsMask) {
            $lines[] = 'Подписка не содержит выбранных метрик.';

            return implode("\n", $lines);
        }

        if (($metricsMask & 1) === 1) {
            $lines[] = $this->buildBalanceLine($company);
        }

        if (($metricsMask & 2) === 2) {
            $lines[] = $this->buildCashflowLine($company);
        }

        if (($metricsMask & 4) === 4) {
            // Место для будущего расчета топа расходов. Сейчас оставляем MVP-строку.
            $lines[] = 'Топ расходов: MVP: метрика пока не реализована';
        }

        return implode("\n", $lines);
    }

    private function buildBalanceLine(Company $company): string
    {
        $totalsByCurrency = $this->calculateBalances($company);
        if ([] === $totalsByCurrency) {
            return 'Баланс: нет активных счетов';
        }

        $parts = [];
        foreach ($totalsByCurrency as $currency => $amount) {
            $parts[] = sprintf('%s %s', $this->formatAmount($amount), $currency);
        }

        return sprintf('Баланс: %s', implode(', ', $parts));
    }

    private function buildCashflowLine(Company $company): string
    {
        $periodEnd = new DateTimeImmutable('today');
        $periodStart = $periodEnd->sub(new DateInterval('P7D'));

        $accounts = $this->moneyAccountRepository->findBy(['company' => $company, 'isActive' => true]);
        if ([] === $accounts) {
            return 'ДДС: нет активных счетов';
        }

        $accountIds = array_map(static fn ($account) => $account->getId(), $accounts);
        $currencyByAccountId = [];
        foreach ($accounts as $account) {
            $currencyByAccountId[$account->getId()] = $account->getCurrency();
        }

        $turnoversByAccount = $this->cashTransactionRepository->sumByAccountAndPeriod($company, $accountIds, $periodStart, $periodEnd);
        if ([] === $turnoversByAccount) {
            return 'ДДС за 7 дней: нет данных';
        }

        $totalsByCurrency = [];
        foreach ($turnoversByAccount as $accountId => $values) {
            $currency = $currencyByAccountId[$accountId] ?? 'RUB';
            if (!isset($totalsByCurrency[$currency])) {
                $totalsByCurrency[$currency] = ['inflow' => '0.00', 'outflow' => '0.00'];
            }

            $totalsByCurrency[$currency]['inflow'] = bcadd($totalsByCurrency[$currency]['inflow'], (string) ($values['inflow'] ?? '0'), 2);
            $totalsByCurrency[$currency]['outflow'] = bcadd($totalsByCurrency[$currency]['outflow'], (string) ($values['outflow'] ?? '0'), 2);
        }

        $parts = [];
        foreach ($totalsByCurrency as $currency => $values) {
            $parts[] = sprintf(
                '%s: приход %s, расход %s',
                $currency,
                $this->formatAmount($values['inflow']),
                $this->formatAmount($values['outflow'])
            );
        }

        return sprintf('ДДС за 7 дней: %s', implode('; ', $parts));
    }

    private function calculateBalances(Company $company): array
    {
        $accounts = $this->moneyAccountRepository->findBy(['company' => $company, 'isActive' => true]);
        if ([] === $accounts) {
            return [];
        }

        $totals = [];
        foreach ($accounts as $account) {
            $currency = $account->getCurrency();
            if (!isset($totals[$currency])) {
                $totals[$currency] = '0.00';
            }

            $totals[$currency] = bcadd($totals[$currency], (string) $account->getCurrentBalance(), 2);
        }

        return $totals;
    }

    private function formatAmount(string $amount): string
    {
        [$integerPart, $fractionalPart] = array_pad(explode('.', $amount, 2), 2, '00');
        $fractionalPart = rtrim($fractionalPart, '0');
        $fractionalPart = '' === $fractionalPart ? '00' : str_pad($fractionalPart, 2, '0');

        return sprintf('%s.%s', number_format((int) $integerPart, 0, '', ' '), $fractionalPart);
    }
}
