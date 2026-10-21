<?php

declare(strict_types=1);

namespace App\Finance\EventListener;

use App\Company\Enum\BusinessProfile;
use App\Company\Event\DirectoryIntegrityCheckEvent;
use App\Finance\Infrastructure\Provider\DefaultPLCategoryTreeProvider;
use App\Finance\Infrastructure\Query\PLCategoryDuplicateNamesQuery;
use App\Finance\Infrastructure\Query\PLTreesWithoutTemplateCodesQuery;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;

/**
 * Ночная проверка справочников: дубли статей ОПиУ и, справочно, деревья без кодов шаблона.
 */
#[AsEventListener]
final readonly class CheckPLCategoryDirectory
{
    public function __construct(
        private PLCategoryDuplicateNamesQuery $duplicates,
        private PLTreesWithoutTemplateCodesQuery $withoutTemplateCodes,
        private DefaultPLCategoryTreeProvider $templates,
    ) {
    }

    public function __invoke(DirectoryIntegrityCheckEvent $event): void
    {
        foreach (($this->duplicates)($event->companyIds) as $duplicate) {
            $event->duplicate($duplicate);
        }

        $codes = [];
        foreach (BusinessProfile::cases() as $profile) {
            foreach ($this->templates->nodes($profile) as $node) {
                if (null !== $node->code) {
                    $codes[$node->code] = true;
                }
            }
        }
        foreach (($this->withoutTemplateCodes)($event->companyIds, array_keys($codes)) as $companyId) {
            $event->note('pl', $companyId, 'своё дерево без кодов шаблона — «Базовый шаблон ОПиУ» только после сопоставления');
        }
    }
}
