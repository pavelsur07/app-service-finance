<?php

declare(strict_types=1);

namespace App\Balance\EventListener;

use App\Balance\Infrastructure\Query\BalanceArticleDuplicateNamesQuery;
use App\Company\Event\DirectoryIntegrityCheckEvent;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Ночная проверка справочников: дубли статей Баланса.
 */
#[AsEventListener]
final readonly class CheckBalanceArticleDirectory
{
    public function __construct(private BalanceArticleDuplicateNamesQuery $duplicates)
    {
    }

    public function __invoke(DirectoryIntegrityCheckEvent $event): void
    {
        foreach (($this->duplicates)($event->companyIds) as $duplicate) {
            $event->duplicate($duplicate);
        }
    }
}
