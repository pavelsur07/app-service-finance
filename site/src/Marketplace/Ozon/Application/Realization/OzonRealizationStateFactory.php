<?php

declare(strict_types=1);

namespace App\Marketplace\Ozon\Application\Realization;

use App\Marketplace\Entity\MarketplaceFinancialReportSyncStatus;
use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Ozon\Application\Reconciliation\ReconciliationMonth;

/**
 * Что сказать пользователю про «Реализацию» за месяц: загружена ли, применена ли, ждём ли мы её и до какого срока.
 * Чистый: на вход месяц, факт наличия отчёта, статус пары (может отсутствовать) и текущий момент.
 */
final class OzonRealizationStateFactory
{
    public function create(ReconciliationMonth $month, bool $present, ?MarketplaceFinancialReportSyncStatus $status, \DateTimeImmutable $now): OzonRealizationState
    {
        $local = $now->setTimezone(new \DateTimeZone(OzonRealizationPollWindow::TIMEZONE));

        if ($present) {
            return $this->loaded($status);
        }

        if (FinancialReportSyncStatus::AUTH_FAILED === $status?->getStatus()) {
            return new OzonRealizationState('danger', 'не получена: Ozon отклонил ключ подключения — обновите ключ, затем загрузите отчёт');
        }

        $currentMonth = ReconciliationMonth::containing($local);
        if ($month->from >= $currentMonth->from) {
            return new OzonRealizationState('secondary', 'появится после закрытия месяца: Ozon формирует отчёт в начале следующего');
        }

        $window = OzonRealizationPollWindow::at($now);
        $isReportMonth = null !== $window && $window->reportYear === (int) $month->from->format('Y') && $window->reportMonth === (int) $month->from->format('n');
        if ($isReportMonth) {
            return new OzonRealizationState('warning', 'ожидается: Ozon формирует отчёт, система проверяет его каждый час'.$this->attempts($status));
        }

        $previousMonth = ReconciliationMonth::containing($currentMonth->from->modify('-1 day'));
        if ($month->from == $previousMonth->from) {
            // Прошлый месяц, а окно ещё не открылось: сегодня 1-е число до 18:00 (со 2-го по 8-е окно открыто).
            if ((int) $local->format('j') <= OzonRealizationPollWindow::LAST_DAY) {
                return new OzonRealizationState('secondary', sprintf('проверка начнётся 1-го числа в %02d:00 по Москве', OzonRealizationPollWindow::OPENS_AT_HOUR));
            }

            return new OzonRealizationState('warning', 'не получена за время автоматических проверок (до 8-го числа): загрузите отчёт вручную');
        }

        return new OzonRealizationState('warning', 'не загружена: загрузите отчёт вручную');
    }

    private function loaded(?MarketplaceFinancialReportSyncStatus $status): OzonRealizationState
    {
        return match ($status?->getStatus()) {
            FinancialReportSyncStatus::SUCCESS => new OzonRealizationState('success', 'загружена и применена в учёт'.$this->when($status->getFinishedAt())),
            FinancialReportSyncStatus::CONFLICT => new OzonRealizationState('warning', 'загружена, но не применена в учёт: этап «Продажи и возвраты» месяца закрыт'),
            FinancialReportSyncStatus::FAILED => new OzonRealizationState('warning', 'загружена, но обработка не удалась; повтор'.$this->retryAt($status)),
            FinancialReportSyncStatus::RAW_LOADED, FinancialReportSyncStatus::PROCESSING, FinancialReportSyncStatus::QUEUED, FinancialReportSyncStatus::LOADING => new OzonRealizationState('secondary', 'загружена, применяется в учёт'),
            default => new OzonRealizationState('success', 'загружена'),
        };
    }

    private function attempts(?MarketplaceFinancialReportSyncStatus $status): string
    {
        if (null === $status || $status->getAttempts() < 1 || null === $status->getLastAttemptAt()) {
            return '';
        }

        return sprintf(' (попыток: %d, последняя в %s)', $status->getAttempts(), $this->moscow($status->getLastAttemptAt())->format('d.m H:i'));
    }

    private function when(?\DateTimeImmutable $at): string
    {
        return null === $at ? '' : ' '.$this->moscow($at)->format('d.m.Y H:i');
    }

    private function retryAt(MarketplaceFinancialReportSyncStatus $status): string
    {
        $at = $status->getNextRetryAt();

        return null === $at ? ' выполняется автоматически' : ' в '.$this->moscow($at)->format('d.m H:i');
    }

    private function moscow(\DateTimeImmutable $at): \DateTimeImmutable
    {
        return $at->setTimezone(new \DateTimeZone(OzonRealizationPollWindow::TIMEZONE));
    }
}
