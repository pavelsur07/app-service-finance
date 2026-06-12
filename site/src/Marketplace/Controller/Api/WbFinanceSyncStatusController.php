<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Api;

use App\Marketplace\Enum\FinancialReportSyncStatus;
use App\Marketplace\Infrastructure\Query\WbFinanceSyncStatusListQuery;
use App\Shared\Service\ActiveCompanyService;
use Pagerfanta\Doctrine\DBAL\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * История загрузки финансовых отчётов WB по дням: статус и причина ошибки.
 */
#[IsGranted('ROLE_USER')]
final class WbFinanceSyncStatusController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly WbFinanceSyncStatusListQuery $listQuery,
    ) {
    }

    #[Route('/api/marketplace/wb-finance/sync-statuses', name: 'api_marketplace_wb_finance_sync_statuses', methods: ['GET'])]
    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();

        $limit = $request->query->getInt('limit', 50);
        if ($limit < 1 || $limit > 200) {
            return $this->json([
                'error' => [
                    'code' => 'invalid_pagination_limit',
                    'message' => 'Параметр limit должен быть от 1 до 200',
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $days = $request->query->getInt('days', 14);
        if ($days < 1 || $days > 366) {
            return $this->json([
                'error' => [
                    'code' => 'invalid_days_window',
                    'message' => 'Параметр days должен быть от 1 до 366',
                ],
            ], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $from = (new \DateTimeImmutable('today'))->modify(sprintf('-%d days', $days));
        $qb = $this->listQuery->createByCompanyQueryBuilder((string) $company->getId(), $from);

        $adapter = new QueryAdapter(
            $qb,
            static function (\Doctrine\DBAL\Query\QueryBuilder $countQb): void {
                $countQb->select('COUNT(*)')->resetOrderBy();
            },
        );
        $pagerfanta = new Pagerfanta($adapter);
        $pagerfanta->setMaxPerPage($limit);
        $pagerfanta->setCurrentPage(max(1, $request->query->getInt('page', 1)));

        $items = [];
        foreach ($pagerfanta->getCurrentPageResults() as $row) {
            $status = FinancialReportSyncStatus::from((string) $row['status']);

            $items[] = [
                'id' => $row['id'],
                'connection_id' => $row['connection_id'],
                'marketplace' => $row['marketplace'],
                'report_type' => $row['report_type'],
                'business_date' => substr((string) $row['business_date'], 0, 10),
                'status' => $status->value,
                'status_label' => $status->getLabel(),
                'mode' => $row['mode'],
                'records_count' => (int) $row['records_count'],
                'attempts' => (int) $row['attempts'],
                'next_retry_at' => $row['next_retry_at'],
                'error_class' => $row['last_error_class'],
                'error_message' => $row['last_error_message'],
                'http_status' => null !== $row['last_error_status_code'] ? (int) $row['last_error_status_code'] : null,
                'started_at' => $row['started_at'],
                'finished_at' => $row['finished_at'],
                'updated_at' => $row['updated_at'],
            ];
        }

        return $this->json([
            'items' => $items,
            'total' => $pagerfanta->getNbResults(),
            'pages' => $pagerfanta->getNbPages(),
            'page' => $pagerfanta->getCurrentPage(),
            'per_page' => $pagerfanta->getMaxPerPage(),
        ]);
    }
}
