<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Company\Entity\Company;
use App\Marketplace\Application\Processor\OzonMutualSettlementProcessor;
use App\Marketplace\Entity\MarketplaceRawDocument;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Enum\PipelineStatus;
use App\Marketplace\Enum\PipelineStep;
use App\Marketplace\Infrastructure\Api\Ozon\OzonMutualSettlementClient;
use App\Shared\Service\AppLogger;
use App\Shared\Service\Storage\StorageService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Загружает отчёт «Взаиморасчёты» из Ozon API, сохраняет файл на диск,
 * парсит XLSX в структурированный JSON и сохраняет как raw-документ.
 *
 * Если парсинг не удаётся — файл всё равно сохраняется, документ получает
 * статус FAILED с описанием ошибки в raw_data.parsing_error.
 */
final class LoadMutualSettlementAction
{
    private const DOCUMENT_TYPE = 'mutual_settlement_report';
    private const API_ENDPOINT = '/v1/finance/mutual-settlement';
    private const STORAGE_DIR = 'marketplace-raw/ozon/mutual_settlement';

    public function __construct(
        private readonly OzonMutualSettlementClient $client,
        private readonly OzonMutualSettlementProcessor $processor,
        private readonly StorageService $storageService,
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

        $responseSize = $result['response_size'];
        $binaryContent = $result['binary_content'];
        $contentType = $result['content_type'];

        // Бинарный ответ (XLSX и т.д.) — сохраняем файл на диск и парсим
        if (null !== $binaryContent) {
            return $this->handleBinaryResponse(
                $company,
                $companyId,
                $periodFrom,
                $periodTo,
                $binaryContent,
                $contentType ?? 'application/octet-stream',
                $responseSize,
            );
        }

        // JSON-ответ — сохраняем как есть
        $rawData = $result['data'];
        $recordsCount = $result['records_count'];

        $rawDoc = $this->createRawDocument($company, $periodFrom, $periodTo, $rawData, $recordsCount);
        $rawDoc->resetProcessingStatus();

        $this->em->persist($rawDoc);
        $this->em->flush();

        $this->appLogger->info('LoadMutualSettlement: JSON-ответ сохранён', [
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

    private function handleBinaryResponse(
        Company $company,
        string $companyId,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
        string $binaryContent,
        string $contentType,
        int $responseSize,
    ): array {
        $extension = $this->guessExtension($contentType);
        $period = $periodFrom->format('Y-m');
        $relativePath = sprintf(
            '%s/%s/%s.%s',
            self::STORAGE_DIR,
            $companyId,
            $period,
            $extension,
        );

        // Сохраняем файл на диск
        $dir = sprintf('%s/%s', self::STORAGE_DIR, $companyId);
        $this->storageService->ensureDir($dir);
        $absolutePath = $this->storageService->getAbsolutePath($relativePath);
        if (false === file_put_contents($absolutePath, $binaryContent)) {
            throw new \RuntimeException(sprintf('Не удалось сохранить файл на диск: %s', $relativePath));
        }

        $this->appLogger->info('LoadMutualSettlement: файл сохранён', [
            'companyId' => $companyId,
            'path' => $relativePath,
            'size' => $responseSize,
            'content_type' => $contentType,
        ]);

        // Парсим XLSX
        $rawData = [
            'file_path' => $relativePath,
            'file_size_bytes' => $responseSize,
            'content_type' => $contentType,
        ];

        $recordsCount = 0;
        $processingStatus = PipelineStatus::COMPLETED;

        try {
            $parsed = $this->processor->parse($absolutePath);
            $rawData['parsed'] = $parsed;
            $recordsCount = $parsed['meta']['data_rows_found'] ?? 0;

            $this->appLogger->info('LoadMutualSettlement: парсинг успешен', [
                'companyId' => $companyId,
                'recordsCount' => $recordsCount,
                'sections' => count($parsed['sections'] ?? []),
            ]);
        } catch (\Throwable $e) {
            $processingStatus = PipelineStatus::FAILED;
            $rawData['parsing_error'] = $e->getMessage();

            $this->appLogger->error('LoadMutualSettlement: ошибка парсинга', $e, [
                'companyId' => $companyId,
                'path' => $relativePath,
            ]);
        }

        $rawDoc = $this->createRawDocument($company, $periodFrom, $periodTo, $rawData, $recordsCount);
        $rawDoc->resetProcessingStatus();

        if (PipelineStatus::COMPLETED === $processingStatus) {
            $rawDoc->markCompleted();
        } elseif (PipelineStatus::FAILED === $processingStatus) {
            $rawDoc->markStepFailed(PipelineStep::COSTS);
            $rawDoc->setSyncNotes('Parsing failed: ' . ($rawData['parsing_error'] ?? 'unknown'));
        }

        $this->em->persist($rawDoc);
        $this->em->flush();

        $this->appLogger->info('LoadMutualSettlement: документ сохранён', [
            'companyId' => $companyId,
            'rawDocumentId' => $rawDoc->getId(),
            'recordsCount' => $recordsCount,
            'responseSize' => $responseSize,
            'processingStatus' => $processingStatus->value,
        ]);

        return [
            'rawDocumentId' => $rawDoc->getId(),
            'recordsCount' => $recordsCount,
            'responseSize' => $responseSize,
        ];
    }

    private function createRawDocument(
        Company $company,
        \DateTimeImmutable $periodFrom,
        \DateTimeImmutable $periodTo,
        array $rawData,
        int $recordsCount,
    ): MarketplaceRawDocument {
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

        return $rawDoc;
    }

    private function guessExtension(string $contentType): string
    {
        return match (true) {
            str_contains($contentType, 'spreadsheetml') => 'xlsx',
            str_contains($contentType, 'ms-excel') => 'xls',
            str_contains($contentType, 'csv') => 'csv',
            str_contains($contentType, 'zip') => 'zip',
            default => 'bin',
        };
    }
}
