<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Marketplace\Application\Processor\OzonServiceCategoryMap;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Отладочный эндпоинт для поиска operation_type / service_name из raw-данных Ozon,
 * отсутствующих в OzonServiceCategoryMap.
 *
 * Помогает обнаружить новые типы операций (напр. «Займы и факторинг»), которые
 * API Ozon добавил, но наш маппинг пока не учитывает → расхождение с ЛК.
 *
 * Использование:
 *   GET /api/marketplace-analytics/debug/unknown-operations
 *       ?marketplace=ozon&periodFrom=2026-01-01&periodTo=2026-01-31
 */
#[Route(
    path: '/api/marketplace-analytics/debug/unknown-operations',
    name: 'api_marketplace_analytics_debug_unknown_operations',
    methods: ['GET'],
)]
#[IsGranted('ROLE_COMPANY_USER')]
final class DebugUnknownOperationsController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly Connection $connection,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $marketplace   = (string) $request->query->get('marketplace', '');
        $periodFromStr = (string) $request->query->get('periodFrom', '');
        $periodToStr   = (string) $request->query->get('periodTo', '');

        if ($marketplace !== 'ozon') {
            return $this->json(['error' => 'Only marketplace=ozon is supported'], 422);
        }

        if ($periodFromStr === '' || $periodToStr === '') {
            return $this->json(['error' => 'periodFrom and periodTo are required (Y-m-d)'], 422);
        }

        try {
            $periodFrom = new \DateTimeImmutable($periodFromStr);
            $periodTo   = new \DateTimeImmutable($periodToStr);
        } catch (\Exception) {
            return $this->json(['error' => 'Invalid date format. Expected Y-m-d'], 422);
        }

        if ($periodFrom > $periodTo) {
            return $this->json(['error' => 'periodFrom must be <= periodTo'], 422);
        }

        $company   = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $rawDocs = $this->connection->fetchAllAssociative(
            'SELECT id, raw_data
             FROM marketplace_raw_documents
             WHERE company_id = :companyId
               AND marketplace = :marketplace
               AND document_type = :documentType
               AND period_from <= :periodTo
               AND period_to >= :periodFrom',
            [
                'companyId'    => $companyId,
                'marketplace'  => 'ozon',
                'documentType' => 'sales_report',
                'periodFrom'   => $periodFrom->format('Y-m-d'),
                'periodTo'     => $periodTo->format('Y-m-d'),
            ],
        );

        /** @var array<string, array{count: int, totalAmount: float}> */
        $operationTypes = [];
        /** @var array<string, array{count: int, totalPrice: float}> */
        $serviceNames   = [];
        $totalOperations = 0;

        foreach ($rawDocs as $row) {
            $rawData = $row['raw_data'] ?? null;
            if ($rawData === null || $rawData === '') {
                continue;
            }

            $decoded = json_decode($rawData, true);
            if (!is_array($decoded)) {
                continue;
            }

            // Raw data может быть {result: {operations: [...]}} или просто массив операций
            $operations = $decoded;
            if (isset($decoded['result']['operations']) && is_array($decoded['result']['operations'])) {
                $operations = $decoded['result']['operations'];
            }

            foreach ($operations as $op) {
                if (!is_array($op)) {
                    continue;
                }

                $totalOperations++;

                $opType = (string) ($op['operation_type'] ?? '');
                if ($opType !== '') {
                    $amount = (float) ($op['amount'] ?? 0);
                    if (!isset($operationTypes[$opType])) {
                        $operationTypes[$opType] = ['count' => 0, 'totalAmount' => 0.0];
                    }
                    $operationTypes[$opType]['count']++;
                    $operationTypes[$opType]['totalAmount'] += $amount;
                }

                $services = $op['services'] ?? [];
                if (is_array($services)) {
                    foreach ($services as $service) {
                        if (!is_array($service)) {
                            continue;
                        }
                        $svcName = (string) ($service['name'] ?? '');
                        if ($svcName === '') {
                            continue;
                        }
                        $price = (float) ($service['price'] ?? 0);
                        if (!isset($serviceNames[$svcName])) {
                            $serviceNames[$svcName] = ['count' => 0, 'totalPrice' => 0.0];
                        }
                        $serviceNames[$svcName]['count']++;
                        $serviceNames[$svcName]['totalPrice'] += $price;
                    }
                }
            }
        }

        [$knownOpTypes, $unknownOpTypes]   = $this->classify($operationTypes, 'totalAmount');
        [$knownSvcNames, $unknownSvcNames] = $this->classify($serviceNames, 'totalPrice');

        $totalUnknownAmount = 0.0;
        foreach ($unknownSvcNames as $item) {
            $totalUnknownAmount += (float) $item['totalAmount'];
        }

        return $this->json([
            'period' => [
                'from' => $periodFrom->format('Y-m-d'),
                'to'   => $periodTo->format('Y-m-d'),
            ],
            'totalOperations'      => $totalOperations,
            'knownOperationTypes'  => $knownOpTypes,
            'unknownOperationTypes' => $unknownOpTypes,
            'knownServiceNames'    => $knownSvcNames,
            'unknownServiceNames'  => $unknownSvcNames,
            'summary' => [
                'totalUnknownAmount' => number_format($totalUnknownAmount, 2, '.', ''),
                'hint'               => 'Эти operation_type / service_name не маппятся в OzonServiceCategoryMap',
            ],
        ]);
    }

    /**
     * @param array<string, array{count: int, totalAmount?: float, totalPrice?: float}> $items
     * @return array{list<array{name: string, count: int, totalAmount: string}>, list<array{name: string, count: int, totalAmount: string}>}
     */
    private function classify(array $items, string $amountKey): array
    {
        $known   = [];
        $unknown = [];

        foreach ($items as $name => $stats) {
            $entry = [
                'name'        => $name,
                'count'       => $stats['count'],
                'totalAmount' => number_format($stats[$amountKey], 2, '.', ''),
            ];

            if (OzonServiceCategoryMap::isKnown($name) || OzonServiceCategoryMap::isZeroMarker($name)) {
                $known[] = $entry;
            } else {
                $unknown[] = $entry;
            }
        }

        $sortByAbsAmount = static fn (array $a, array $b): int
            => abs((float) $b['totalAmount']) <=> abs((float) $a['totalAmount']);

        usort($known, $sortByAbsAmount);
        usort($unknown, $sortByAbsAmount);

        return [$known, $unknown];
    }
}
