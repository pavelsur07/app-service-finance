<?php

declare(strict_types=1);

namespace App\Marketplace\Inventory\Application;

use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Inventory\Application\Command\ImportInventoryCostPriceFromFileCommand;
use App\Marketplace\Inventory\Application\Command\SetInventoryCostPriceCommand;
use App\Marketplace\Repository\MarketplaceListingBarcodeRepository;
use App\Marketplace\Repository\MarketplaceListingRepository;
use OpenSpout\Reader\XLS\Reader as XlsReader;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Psr\Log\LoggerInterface;

/**
 * Парсит xls/xlsx файл и устанавливает себестоимость пакетно.
 *
 * Формат файла:
 *   Колонка A: Баркод товара
 *   Колонка B: Себестоимость (число, например 850.00)
 *
 * Поиск листинга: barcode + companyId + marketplace
 * Это гарантирует что одинаковый баркод на Ozon и WB получит
 * себестоимость только для выбранного маркетплейса.
 *
 * Строки с ненайденным баркодом пропускаются и логируются.
 *
 * @return array{imported: int, skipped: int, errors: string[]}
 */
final class ImportInventoryCostPriceFromFileAction
{
    public function __construct(
        private readonly MarketplaceListingBarcodeRepository $barcodeRepository,
        private readonly SetInventoryCostPriceAction         $setAction,
        private readonly MarketplaceListingRepository        $listingRepository,
        private readonly LoggerInterface                     $logger,
    ) {
    }

    public function __invoke(ImportInventoryCostPriceFromFileCommand $command): array
    {
        $rows = $this->parseFile($command->absoluteFilePath, $command->originalFilename);

        $imported = 0;
        $skipped  = 0;
        $errors   = [];

        foreach ($rows as $rowNum => $row) {
            $identifier = trim((string) ($row[0] ?? ''));
            $price   = trim((string) ($row[1] ?? ''));

            if ($identifier === '' || $price === '') {
                $skipped++;
                continue;
            }

            if (!is_numeric($price) || (float) $price < 0) {
                $errors[] = sprintf('Строка %d: некорректная цена "%s" для идентификатора %s', $rowNum, $price, $identifier);
                $skipped++;
                continue;
            }

            [$listing, $resolveError] = $this->resolveListing($command->companyId, $command->marketplace, $identifier, $command->identifierType);

            if ($listing === null) {
                $this->logger->warning('[InventoryImport] Listing not found by barcode', [
                    'company_id'  => $command->companyId,
                    'marketplace' => $command->marketplace->value,
                    'identifier'  => $identifier,
                    'row'         => $rowNum,
                ]);
                $errors[] = $resolveError ?? sprintf('Строка %d: идентификатор %s не найден', $rowNum, $identifier);
                $skipped++;
                continue;
            }

            try {
                ($this->setAction)(new SetInventoryCostPriceCommand(
                    companyId:     $command->companyId,
                    listingId:     $listing->getId(),
                    effectiveFrom: $command->effectiveFrom,
                    priceAmount:   $price,
                    currency:      'RUB',
                    note:          'Импорт из файла: ' . $command->originalFilename,
                ));

                $imported++;
            } catch (\DomainException $e) {
                $errors[] = sprintf('Строка %d: идентификатор %s — %s', $rowNum, $identifier, $e->getMessage());
                $skipped++;
            }
        }

        $this->logger->info('[InventoryImport] Completed', [
            'company_id'  => $command->companyId,
            'marketplace' => $command->marketplace->value,
            'identifier_type' => $command->identifierType,
            'imported'    => $imported,
            'skipped'     => $skipped,
            'errors'      => count($errors),
        ]);

        return [
            'imported' => $imported,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ];
    }

    /**
     * Резолв листинга по баркоду + маркетплейс.
     * Баркод одинаков на разных маркетплейсах — marketplace гарантирует
     * что цена устанавливается только для нужного листинга.
     */
    private function resolveListing(
        string $companyId,
        \App\Marketplace\Enum\MarketplaceType $marketplace,
        string $identifier,
        string $identifierType,
    ): array {
        if ($identifierType === 'marketplace_sku') {
            $matches = $this->listingRepository->findAllByCompanyMarketplaceAndMarketplaceSku($companyId, $marketplace, $identifier);

            return $this->resolveSingleMatch($matches, $identifierType, $identifier);
        }

        if ($identifierType === 'supplier_sku') {
            $matches = $this->listingRepository->findAllByCompanyMarketplaceAndSupplierSku($companyId, $marketplace, $identifier);

            return $this->resolveSingleMatch($matches, $identifierType, $identifier);
        }

        $barcodeEntity = $this->barcodeRepository->findByBarcode(
            $companyId,
            $identifier,
            $marketplace,
        );

        $listing = $barcodeEntity?->getListing();
        if ($listing === null) {
            return [null, sprintf('идентификатор %s не найден', $identifier)];
        }

        return [$listing, null];
    }

    /**
     * @param MarketplaceListing[] $matches
     * @return array{0: MarketplaceListing|null, 1: string|null}
     */
    private function resolveSingleMatch(array $matches, string $identifierType, string $identifier): array
    {
        $count = count($matches);
        if ($count === 0) {
            return [null, sprintf('идентификатор %s не найден', $identifier)];
        }
        if ($count > 1) {
            return [null, sprintf('неоднозначный %s "%s": найдено %d листинга', $identifierType, $identifier, $count)];
        }

        return [$matches[0], null];
    }

    /**
     * Парсит xls или xlsx файл.
     * Первая строка пропускается как заголовок только если и identifier, и price нечисловые.
     *
     * @return array<int, array<int, mixed>>
     */
    private function parseFile(string $filePath, string $originalFilename): array
    {
        $ext = strtolower(pathinfo($originalFilename, PATHINFO_EXTENSION));

        $reader = match ($ext) {
            'xlsx'  => new XlsxReader(),
            'xls'   => new XlsReader(),
            default => throw new \InvalidArgumentException(
                sprintf('Неподдерживаемый формат файла: %s. Ожидается xls или xlsx.', $ext)
            ),
        };

        $reader->open($filePath);

        $rows   = [];
        $rowNum = 0;

        foreach ($reader->getSheetIterator() as $sheet) {
            foreach ($sheet->getRowIterator() as $row) {
                $cells = $row->getCells();
                $rowNum++;

                $firstCell = trim((string) ($cells[0]?->getValue() ?? ''));

                $secondCell = trim((string) ($cells[1]?->getValue() ?? ''));
                if ($rowNum === 1 && !is_numeric($firstCell) && !is_numeric($secondCell)) {
                    continue;
                }

                $rows[$rowNum] = [
                    $cells[0]?->getValue() ?? '',
                    $cells[1]?->getValue() ?? '',
                ];
            }
            // Читаем только первый лист
            break;
        }

        $reader->close();

        return $rows;
    }
}
