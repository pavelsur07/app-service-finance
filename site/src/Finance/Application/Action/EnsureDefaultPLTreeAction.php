<?php

declare(strict_types=1);

namespace App\Finance\Application\Action;

use App\Company\Enum\BusinessProfile;
use App\Company\Facade\CompanyFacade;
use App\Finance\Application\Command\ImportPLCategoryTreeCommand;
use App\Finance\Infrastructure\Provider\DefaultPLCategoryTreeProvider;
use App\Finance\Repository\PLCategoryRepository;
use Psr\Log\LoggerInterface;

/**
 * Стартовое дерево статей ОПиУ по профилю бизнеса для компании без статей. Компания, у
 * которой есть хотя бы одна своя статья, не трогается: дополнить настроенное дерево
 * шаблоном — отдельное решение пользователя (экран импорта или `app:finance:pl-tree:apply-default`).
 */
final readonly class EnsureDefaultPLTreeAction
{
    public function __construct(
        private PLCategoryRepository $categories,
        private DefaultPLCategoryTreeProvider $defaultTree,
        private ImportPLCategoryTreeAction $import,
        private CompanyFacade $companies,
        private LoggerInterface $logger,
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

        $profile ??= $this->companies->getBusinessProfile($companyId);
        $result = ($this->import)(new ImportPLCategoryTreeCommand(
            $this->defaultTree->nodes($profile),
            $companyId,
            dryRun: $dryRun,
            onlyCreate: true,
        ));

        if (!$dryRun) {
            $this->logger->info('Default P&L tree applied to company', [
                'companyId' => $companyId,
                'profile' => $profile->value,
                'templateVersion' => $this->defaultTree->templateVersion($profile),
                'created' => \count($result->created),
            ]);
        }

        return \count($result->created);
    }
}
