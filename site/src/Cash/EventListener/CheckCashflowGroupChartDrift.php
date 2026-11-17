<?php

declare(strict_types=1);

namespace App\Cash\EventListener;

use App\Cash\Application\Category\SyncCashflowGroupChartAction;
use App\Company\Event\DirectoryIntegrityCheckEvent;
use App\Company\Facade\ConsolidationGroupFacadeInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Ночная проверка справочников: расхождение дерева ДДС компании группы с эталоном (dry-run
 * синхронизации). Справочная строка, не находка: новая статья головной — штатное расхождение до
 * синхронизации. Компании вне групп не проверяются — у старых деревьев расхождения с ядром ожидаемы.
 */
#[AsEventListener]
final readonly class CheckCashflowGroupChartDrift
{
    public function __construct(
        private ConsolidationGroupFacadeInterface $groups,
        private SyncCashflowGroupChartAction $sync,
    ) {
    }

    public function __invoke(DirectoryIntegrityCheckEvent $event): void
    {
        foreach (array_keys($this->groups->headsOf($event->companyIds)) as $companyId) {
            $plan = $this->sync->plan($companyId);
            if ($plan->isEmpty() && [] === $plan->conflicts && [] === $plan->unmatched) {
                continue;
            }
            $event->note('ДДС-группа', $companyId, sprintf(
                'расхождение с эталоном: привязать %d, создать %d, конфликтов %d, без кода %d',
                \count($plan->bind),
                \count($plan->create),
                \count($plan->conflicts),
                \count($plan->unmatched),
            ));
        }
    }
}
