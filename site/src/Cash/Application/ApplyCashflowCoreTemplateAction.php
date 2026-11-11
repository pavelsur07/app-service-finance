<?php

declare(strict_types=1);

namespace App\Cash\Application;

use App\Cash\Application\DTO\Template\CashflowCoreTemplateNode;
use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Repository\Transaction\CashflowCategoryRepository;
use App\Company\Entity\Company;
use App\Company\Facade\CompanyFacade;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Ядро статей ДДС новой компании (config/cash/cashflow_templates/core.yaml) под системными
 * группами деятельности. Применяется только к компании без несистемных статей ДДС — повтор
 * ничего не создаёт и чужое дерево не трогает.
 *
 * Только persist(): транзакцией владеет точка входа создания компании (wrapInTransaction
 * вокруг CompanyCreatedEvent), она же делает flush. Системные группы к этому моменту уже
 * записаны EnsureCashflowSystemCategoriesAction.
 */
final class ApplyCashflowCoreTemplateAction
{
    public function __construct(
        private readonly CompanyFacade $companyFacade,
        private readonly CashflowCategoryRepository $categories,
        private readonly CashflowCoreTemplateReader $reader,
        private readonly EntityManagerInterface $entityManager,
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @return int сколько статей и групп создано
     */
    public function __invoke(string $companyId): int
    {
        $company = $this->companyFacade->findById($companyId)
            ?? throw new \DomainException(sprintf('Компания %s не найдена.', $companyId));

        if ($this->categories->hasRegularByCompany($company)) {
            return 0;
        }

        // Шаблон целиком проверяется до первой записи.
        $template = $this->reader->read();

        $created = 0;
        foreach ($template as $sectionCode => $nodes) {
            $section = $this->categories->findOneByCompanyAndCode($company, $sectionCode)
                ?? throw new \DomainException(sprintf('Системная группа ДДС %s не создана.', $sectionCode));
            $created += $this->create($company, $section, $nodes);
        }

        $this->logger->info('Cashflow core template applied', ['companyId' => $companyId, 'created' => $created]);

        return $created;
    }

    /**
     * @param list<CashflowCoreTemplateNode> $nodes
     */
    private function create(Company $company, CashflowCategory $parent, array $nodes): int
    {
        $created = 0;
        foreach ($nodes as $node) {
            $category = new CashflowCategory(Uuid::uuid7()->toString(), $company);
            $category
                ->setName($node->name)
                ->setDescription($node->description)
                ->setOperationType($node->direction)
                ->setSort($node->sort)
                ->setParent($parent);
            $category->syncFlowKindWithParent();
            $this->entityManager->persist($category);
            ++$created;

            $created += $this->create($company, $category, $node->children);
        }

        return $created;
    }
}
