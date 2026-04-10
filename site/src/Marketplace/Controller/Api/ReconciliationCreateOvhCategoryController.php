<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Api;

use App\Marketplace\Application\Processor\OzonServiceCategoryMap;
use App\Marketplace\Application\Service\MarketplaceCostCategoryResolver;
use App\Marketplace\Enum\MarketplaceType;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * TEMPORARY — удалить после создания категории ozon_ovh_processing.
 *
 * Создаёт запись в marketplace_cost_categories если её ещё нет.
 */
#[IsGranted('ROLE_USER')]
final class ReconciliationCreateOvhCategoryController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MarketplaceCostCategoryResolver $categoryResolver,
        private readonly Connection $connection,
    ) {
    }

    #[Route(
        '/api/marketplace/reconciliation/debug/create-ovh-category',
        name: 'api_marketplace_reconciliation_debug_create_ovh',
        methods: ['POST'],
    )]
    public function __invoke(): JsonResponse
    {
        $company   = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        // Проверяем существует ли уже
        $existing = $this->connection->fetchAssociative(
            <<<'SQL'
            SELECT id, code, name
            FROM marketplace_cost_categories
            WHERE code = 'ozon_ovh_processing'
              AND company_id = :companyId
            SQL,
            ['companyId' => $companyId],
        );

        if ($existing !== false) {
            return $this->json([
                'created' => false,
                'message' => 'Категория уже существует',
                'id'      => $existing['id'],
                'code'    => $existing['code'],
                'name'    => $existing['name'],
            ]);
        }

        // Создаём через стандартный resolver (find-or-create + flush)
        $categoryName = OzonServiceCategoryMap::getCategoryName('ozon_ovh_processing');
        $category = $this->categoryResolver->resolve(
            $company,
            MarketplaceType::OZON,
            'ozon_ovh_processing',
            $categoryName,
        );

        return $this->json([
            'created' => true,
            'id'      => $category->getId(),
            'code'    => 'ozon_ovh_processing',
            'name'    => $categoryName,
        ]);
    }
}
