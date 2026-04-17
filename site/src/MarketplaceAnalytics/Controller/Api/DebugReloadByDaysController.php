<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Message\ProcessDayReportMessage;
use App\Marketplace\Service\Integration\MarketplaceAdapterRegistry;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * @internal Debug controller, to be removed after data recovery.
 *
 * Загружает данные из Ozon API по дням (один день — один raw-документ)
 * за указанный период, затем диспатчит pipeline обработки для каждого дня.
 *
 *   GET /api/debug/reload-by-days
 *       ?companyId=UUID&marketplace=ozon&from=2026-01-01&to=2026-01-31
 *       [&preview=1]  — только план (по умолчанию)
 *       [&confirm=1]  — выполнить загрузку + обработку
 */
#[Route(
    path: '/api/debug/reload-by-days',
    name: 'api_debug_reload_by_days',
    methods: ['GET'],
)]
#[IsGranted('ROLE_COMPANY_USER')]
final class DebugReloadByDaysController extends AbstractController
{
    public function __construct(
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly MarketplaceAdapterRegistry $adapterRegistry,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $companyId      = (string) $request->query->get('companyId', '');
        $marketplaceStr = (string) $request->query->get('marketplace', '');
        $fromStr        = (string) $request->query->get('from', '');
        $toStr          = (string) $request->query->get('to', '');
        $confirm        = (string) $request->query->get('confirm', '0') === '1';

        if ($companyId === '' || $marketplaceStr === '' || $fromStr === '' || $toStr === '') {
            return $this->json(['error' => 'companyId, marketplace, from, to are required'], 422);
        }

        $marketplace = MarketplaceType::tryFrom($marketplaceStr);
        if ($marketplace === null) {
            return $this->json(['error' => 'Unknown marketplace: ' . $marketplaceStr], 422);
        }

        if ($marketplace !== MarketplaceType::OZON) {
            return $this->json(['error' => 'Only ozon is supported at the moment'], 422);
        }

        $from = $this->parseStrictDate($fromStr);
        $to   = $this->parseStrictDate($toStr);
        if ($from === null || $to === null) {
            return $this->json(['error' => 'Invalid date format (Y-m-d expected, must be a real calendar date)'], 422);
        }

        if ($from > $to) {
            return $this->json(['error' => 'from must be <= to'], 422);
        }

        $company = $this->em->find(Company::class, $companyId);
        if ($company === null) {
            return $this->json(['error' => 'Company not found: ' . $companyId], 404);
        }

        $days          = $this->buildDaysList($from, $to);
        $existingDays  = $this->findExistingDays($companyId, $marketplace, $from, $to);
        $toLoad        = array_filter($days, static fn (string $d): bool => !isset($existingDays[$d]));
        $skippedCount  = count($days) - count($toLoad);

        if (!$confirm) {
            return $this->json([
                'mode'        => 'preview',
                'companyId'   => $companyId,
                'marketplace' => $marketplace->value,
                'from'        => $from->format('Y-m-d'),
                'to'          => $to->format('Y-m-d'),
                'plan'        => [
                    'total_days'       => count($days),
                    'to_load'          => count($toLoad),
                    'skipped_existing' => $skippedCount,
                ],
                'dispatched' => 0,
            ]);
        }

        $adapter    = $this->adapterRegistry->get($marketplace);
        $dispatched = 0;
        $errors     = [];

        foreach ($toLoad as $dayStr) {
            $dayDate = new \DateTimeImmutable($dayStr);

            try {
                $rawData = $adapter->fetchRawReport($company, $dayDate, $dayDate);

                if (empty($rawData)) {
                    $this->logger->info('[DebugReloadByDays] Empty report for day', [
                        'company_id' => $companyId,
                        'day'        => $dayStr,
                    ]);
                    continue;
                }

                $rawDoc = new MarketplaceRawDocument(
                    Uuid::uuid4()->toString(),
                    $company,
                    $marketplace,
                    'sales_report',
                );
                $rawDoc->setPeriodFrom($dayDate);
                $rawDoc->setPeriodTo($dayDate);
                $rawDoc->setApiEndpoint($adapter->getApiEndpointName());
                $rawDoc->setRawData($rawData);
                $rawDoc->setRecordsCount(count($rawData));

                $this->em->persist($rawDoc);
                $this->em->flush();

                $this->messageBus->dispatch(new ProcessDayReportMessage(
                    companyId: $companyId,
                    rawDocumentId: $rawDoc->getId(),
                ));

                $dispatched++;

                $this->em->clear();
                $company = $this->em->getReference(Company::class, $companyId);
            } catch (\Throwable $e) {
                $errors[] = ['day' => $dayStr, 'error' => $e->getMessage()];
                $this->logger->error('[DebugReloadByDays] Failed to load day', [
                    'company_id' => $companyId,
                    'day'        => $dayStr,
                    'error'      => $e->getMessage(),
                ]);
            }
        }

        $response = [
            'mode'        => 'executed',
            'companyId'   => $companyId,
            'marketplace' => $marketplace->value,
            'from'        => $from->format('Y-m-d'),
            'to'          => $to->format('Y-m-d'),
            'plan'        => [
                'total_days'       => count($days),
                'to_load'          => count($toLoad),
                'skipped_existing' => $skippedCount,
            ],
            'dispatched' => $dispatched,
        ];

        if ($errors !== []) {
            $response['errors'] = $errors;
        }

        return $this->json($response);
    }

    /**
     * @return list<string> Dates in Y-m-d format
     */
    private function buildDaysList(\DateTimeImmutable $from, \DateTimeImmutable $to): array
    {
        $days = [];
        $current = $from;

        while ($current <= $to) {
            $days[] = $current->format('Y-m-d');
            $current = $current->modify('+1 day');
        }

        return $days;
    }

    /**
     * @return array<string, true> Existing day dates as keys
     */
    private function findExistingDays(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $from,
        \DateTimeImmutable $to,
    ): array {
        $rows = $this->connection->fetchFirstColumn(
            "SELECT DISTINCT period_from::text
             FROM marketplace_raw_documents
             WHERE company_id = :cid
               AND marketplace = :mp
               AND document_type = 'sales_report'
               AND period_from = period_to
               AND period_from >= :from
               AND period_to <= :to",
            [
                'cid'  => $companyId,
                'mp'   => $marketplace->value,
                'from' => $from->format('Y-m-d'),
                'to'   => $to->format('Y-m-d'),
            ],
        );

        return array_fill_keys($rows, true);
    }

    private function parseStrictDate(string $value): ?\DateTimeImmutable
    {
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if ($date === false || $date->format('Y-m-d') !== $value) {
            return null;
        }

        return $date;
    }
}
