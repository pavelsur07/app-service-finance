<?php

declare(strict_types=1);

namespace App\Analytics\Infrastructure\Cache;

use App\Cash\Domain\Event\CashTransactionsChanged;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Сбрасывает кэш дашборда компании, когда меняются её данные ДДС.
 *
 * Раньше Cash вызывал SnapshotCacheInvalidator напрямую, и модули зависели
 * друг от друга по кругу (Cash↔Analytics). Теперь Cash только публикует событие.
 */
#[AsEventListener(event: CashTransactionsChanged::class)]
final readonly class InvalidateSnapshotOnCashChangeListener
{
    public function __construct(private SnapshotCacheInvalidator $invalidator)
    {
    }

    public function __invoke(CashTransactionsChanged $event): void
    {
        $this->invalidator->invalidateForCompanyId($event->companyId);
    }
}
