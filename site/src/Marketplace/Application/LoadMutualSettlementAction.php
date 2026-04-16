<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Company\Entity\Company;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Api\Ozon\OzonMutualSettlementClient;
use App\Shared\Service\AppLogger;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Загружает отчёт «Взаиморасчёты» из Ozon API и сохраняет как raw-документ.
 *
 * Парсинг и маппинг в marketplace_costs — следующий этап.
 */
final class LoadMutualSettlementAction
{
    private const DOCUMENT_TYPE = 'mutual_settlement_report';
    private const API_ENDPOINT = '/v1/finance/mutual-settlement';

    public function __construct(
        private readonly OzonMutualSettlementClient $client,
        private readonly EntityManagerInterface $em,
        private readonly AppLogger $appLogger,
    ) {
    }

    /**
     * @return array{rawDocumentId: string, recordsCount: int, responseSize: int}
     */
    public function __invoke(
        Company $company,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
    ): array {
        $companyId = (string) $company->getId();

        $this->appLogger->info('LoadMutualSettlement: начало', [
            'companyId' => $companyId,
            'periodFrom' => $periodFrom->format('Y-m-d'),
            'periodTo' => $periodTo->format('Y-m-d'),
        ]);

        $result = $this->client->fetch($companyId, $periodFrom, $periodTo);

        $rawData = $result['data'];
        $recordsCount = $result['records_count'];
        $responseSize = $result['response_size'];

        $rawDoc = new MarketplaceRawDocument(
            Uuid::uuid4()->toString(),
            $company,
            MarketplaceType::OZON,
            self::DOCUMENT_TYPE,
        );
        $rawDoc->setPeriodFrom($periodFrom);
        $rawDoc->setPeriodTo($periodTo);
        $rawDoc->setApiEndpoint(self::API_ENDPOINT);
        $rawDoc->setRawData($rawData);
        $rawDoc->setRecordsCount($recordsCount);

        // Устанавливаем pending — парсер на следующем этапе
        $rawDoc->resetProcessingStatus();

        $this->em->persist($rawDoc);
        $this->em->flush();

        $this->appLogger->info('LoadMutualSettlement: сохранено', [
            'companyId' => $companyId,
            'rawDocumentId' => $rawDoc->getId(),
            'recordsCount' => $recordsCount,
            'responseSize' => $responseSize,
        ]);

        return [
            'rawDocumentId' => $rawDoc->getId(),
            'recordsCount' => $recordsCount,
            'responseSize' => $responseSize,
        ];
    }
}
