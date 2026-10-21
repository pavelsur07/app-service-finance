<?php

declare(strict_types=1);

namespace App\Finance\Application\Action;

use App\Company\Enum\BusinessProfile;
use App\Finance\Enum\PLTemplateApplicationSource;
use App\Finance\Repository\PLCategoryRepository;

/**
 * Стартовое дерево статей ОПиУ по профилю бизнеса для компании без статей. Компания, у
 * которой есть хотя бы одна своя статья, не трогается: дополнить настроенное дерево
 * шаблоном — отдельное решение пользователя (экран импорта или `app:finance:pl-tree:apply-default`).
 * Применение записывается как происхождение дерева (ApplyDefaultPLTemplateAction).
 */
final readonly class EnsureDefaultPLTreeAction
{
    public function __construct(
        private PLCategoryRepository $categories,
        private ApplyDefaultPLTemplateAction $apply,
    ) {
    }

    /**
     * @param bool $dryRun только посчитать, ничего не записывая
     * @param BusinessProfile|null $profile профиль, если вызывающий его уже знает (событие создания); иначе — профиль компании
     *
     * @return int число созданных (в dry-run — недостающих) статей
     */
    public function __invoke(string $companyId, bool $dryRun = false, ?BusinessProfile $profile = null): int
    {
        if ($this->categories->existsByCompanyId($companyId)) {
            return 0;
        }

        return \count(($this->apply)($companyId, $dryRun, PLTemplateApplicationSource::ONBOARDING, $profile)->created);
    }
}
