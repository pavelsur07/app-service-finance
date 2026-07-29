<?php

declare(strict_types=1);

namespace App\Marketplace\Inventory\Application;

use App\Marketplace\Entity\MarketplaceListing;
use App\Marketplace\Enum\MarketplaceType;
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
 *   Колонка A: идентификатор листинга
 *   Колонка B: Себестоимость (число, например 850.00)
 *
 * Поиск всегда ограничен companyId + marketplace.
 * Для Wildberries supplier_sku цена применяется ко всем размерам артикула.
 *
 * Строки с ненайденным идентификатором пропускаются и логируются.
 *
 * @return array{imported: int, updated_listings: int, skipped: int, errors: string[]}
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
        $wbSupplierListings = $this->indexWbSupplierListings($command, $rows);

        $imported = 0;
        $updatedListings = 0;
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

            [$listings, $resolveError] = $this->resolveListings(
                $command->companyId,
                $command->marketplace,
                $identifier,
                $command->identifierType,
                $wbSupplierListings,
            );

            if ([] === $listings) {
                $this->logger->warning('[InventoryImport] Identifier could not be resolved', [
                    'company_id'  => $command->companyId,
                    'marketplace' => $command->marketplace->value,
                    'identifier_type' => $command->identifierType,
                    'identifier'  => $identifier,
                    'row'         => $rowNum,
                ]);
                $errors[] = sprintf(
                    'Строка %d: %s',
                    $rowNum,
                    $resolveError ?? sprintf('идентификатор %s не найден', $identifier),
                );
                $skipped++;
                continue;
            }

            $updatedForRow = 0;
            foreach ($listings as $listing) {
                try {
                    ($this->setAction)(new SetInventoryCostPriceCommand(
                        companyId:     $command->companyId,
                        listingId:     $listing->getId(),
                        effectiveFrom: $command->effectiveFrom,
                        priceAmount:   $price,
                        currency:      'RUB',
                        note:          'Импорт из файла: ' . $command->originalFilename,
                    ));

                    $updatedForRow++;
                } catch (\DomainException $e) {
                    $errors[] = count($listings) > 1
                        ? sprintf(
                            'Строка %d: идентификатор %s, nmID %s, размер %s — %s',
                            $rowNum,
                            $identifier,
                            $listing->getMarketplaceSku(),
                            $listing->getSize(),
                            $e->getMessage(),
                        )
                        : sprintf(
                            'Строка %d: идентификатор %s — %s',
                            $rowNum,
                            $identifier,
                            $e->getMessage(),
                        );
                }
            }

            if ($updatedForRow > 0) {
                $imported++;
                $updatedListings += $updatedForRow;
            } else {
                $skipped++;
            }
        }

        $this->logger->info('[InventoryImport] Completed', [
            'company_id'  => $command->companyId,
            'marketplace' => $command->marketplace->value,
            'identifier_type' => $command->identifierType,
            'imported'    => $imported,
            'updated_listings' => $updatedListings,
            'skipped'     => $skipped,
            'errors'      => count($errors),
        ]);

        return [
            'imported' => $imported,
            'updated_listings' => $updatedListings,
            'skipped'  => $skipped,
            'errors'   => $errors,
        ];
    }

    /**
     * @param array<string, list<MarketplaceListing>> $wbSupplierListings
     *
     * @return array{0: list<MarketplaceListing>, 1: string|null}
     */
    private function resolveListings(
        string $companyId,
        MarketplaceType $marketplace,
        string $identifier,
        string $identifierType,
        array $wbSupplierListings,
    ): array {
        if ($identifierType === 'marketplace_sku') {
            $matches = $this->listingRepository->findAllByCompanyMarketplaceAndMarketplaceSku($companyId, $marketplace, $identifier);

            return $this->resolveSingleMatch($matches, $identifierType, $identifier);
        }

        if ($identifierType === 'supplier_sku') {
            if (MarketplaceType::WILDBERRIES === $marketplace) {
                $matches = $wbSupplierListings[$identifier] ?? [];

                return [] === $matches
                    ? [[], sprintf('идентификатор %s не найден', $identifier)]
                    : [$matches, null];
            }

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
            return [[], sprintf('идентификатор %s не найден', $identifier)];
        }

        return [[$listing], null];
    }

    /**
     * @param list<MarketplaceListing> $matches
     *
     * @return array{0: list<MarketplaceListing>, 1: string|null}
     */
    private function resolveSingleMatch(array $matches, string $identifierType, string $identifier): array
    {
        $count = count($matches);
        if ($count === 0) {
            return [[], sprintf('идентификатор %s не найден', $identifier)];
        }
        if ($count > 1) {
            return [[], sprintf('неоднозначный %s "%s": найдено %d листинга', $identifierType, $identifier, $count)];
        }

        return [[$matches[0]], null];
    }

    /**
     * @param array<int, array<int, mixed>> $rows
     *
     * @return array<string, list<MarketplaceListing>>
     */
    private function indexWbSupplierListings(
        ImportInventoryCostPriceFromFileCommand $command,
        array $rows,
    ): array {
        if (
            MarketplaceType::WILDBERRIES !== $command->marketplace
            || 'supplier_sku' !== $command->identifierType
        ) {
            return [];
        }

        $supplierSkus = [];
        foreach ($rows as $row) {
            $supplierSku = trim((string) ($row[0] ?? ''));
            if ('' !== $supplierSku) {
                $supplierSkus[] = $supplierSku;
            }
        }

        $indexed = [];
        foreach (array_chunk(array_values(array_unique($supplierSkus)), 500) as $supplierSkuChunk) {
            foreach ($this->listingRepository->findAllByCompanyMarketplaceAndSupplierSkus(
                $command->companyId,
                $command->marketplace,
                $supplierSkuChunk,
            ) as $listing) {
                $supplierSku = $listing->getSupplierSku();
                if (null !== $supplierSku) {
                    $indexed[$supplierSku][] = $listing;
                }
            }
        }

        return $indexed;
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
