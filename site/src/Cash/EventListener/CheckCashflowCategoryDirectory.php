<?php

declare(strict_types=1);

namespace App\Cash\EventListener;

use App\Cash\Infrastructure\Query\CashflowCategoryDuplicateNamesQuery;
use App\Company\Event\DirectoryIntegrityCheckEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Ночная проверка справочников: дубли статей ДДС.
 */
#[AsEventListener]
final readonly class CheckCashflowCategoryDirectory
{
    public function __construct(private CashflowCategoryDuplicateNamesQuery $duplicates)
    {
    }

    public function __invoke(DirectoryIntegrityCheckEvent $event): void
    {
        foreach (($this->duplicates)($event->companyIds) as $duplicate) {
            $event->duplicate($duplicate);
        }
    }
}
