<?php

declare(strict_types=1);

namespace App\Catalog\Application;

use App\Catalog\Application\Command\ImportProductsCommand;
use App\Catalog\Domain\InternalArticleGenerator;
use App\Catalog\DTO\ImportResult;
use App\Catalog\DTO\ImportRowError;
use App\Catalog\DTO\ParsedProductRow;
use App\Catalog\DTO\SetPurchasePriceCommand;
use App\Catalog\Entity\Product;
use App\Catalog\Entity\ProductBarcode;
use App\Catalog\Enum\ProductStatus;
use App\Catalog\Infrastructure\ProductRepository;
use App\Catalog\Infrastructure\Repository\ProductBarcodeRepository;
use App\Catalog\Infrastructure\Repository\ProductImportRepository;
use App\Catalog\Infrastructure\XlsProductRowParser;
use App\Company\Entity\Company;
use App\Company\Facade\CompanyFacade;
use App\Shared\Service\Storage\StorageService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Импортирует товары из XLS-файла.
 *
 * Правила:
 * - companyId приходит как string из команды (ActiveCompanyService запрещён здесь)
 * - Обращение к модулю Company только через CompanyFacade
 * - Дедубликация по vendorSku ИЛИ баркоду (OR-логика)
 * - Дубли пропускаются, собирается отчёт
 * - Цена создаётся через SetPurchasePriceAction если указана в строке (включая 0)
 * - Один flush для батча товаров и баркодов — не N+1
 */
final class ImportProductsFromXlsAction
{
    public function __construct(
        private readonly XlsProductRowParser $parser,
        private readonly ProductRepository $productRepository,
        private readonly ProductBarcodeRepository $barcodeRepository,
        private readonly ProductImportRepository $importRepository,
        private readonly InternalArticleGenerator $articleGenerator,
        private readonly SetPurchasePriceAction $setPurchasePriceAction,
        private readonly CompanyFacade $companyFacade,
        private readonly StorageService $storageService,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(ImportProductsCommand $command): ImportResult
    {
        $import = $this->importRepository->getByIdOrFail($command->importId);
        $import->markProcessing();
        $this->importRepository->save($import);

        try {
            $company = $this->companyFacade->findById($command->companyId);
            if (null === $company) {
                throw new \DomainException(sprintf('Компания "%s" не найдена.', $command->companyId));
            }

            $filePath = $this->storageService->getAbsolutePath($import->getFilePath());
            $rows     = $this->parser->parse($filePath);
            $result   = $this->processRows($rows, $command->companyId, $company);

            $import->markDone(
                rowsTotal:   $result->total(),
                rowsCreated: $result->created,
                rowsSkipped: $result->skipped,
                errors:      $result->errorsToArray(),
            );
            $this->importRepository->save($import);

            return $result;
        } catch (\Throwable $e) {
            $import->markFailed($e->getMessage());
            $this->importRepository->save($import);
            throw $e;
        }
    }

    private function processRows(array $rows, string $companyId, Company $company): ImportResult
    {
        // Prefetch существующих vendorSku и баркодов одним запросом каждый — защита от N+1
        $existingVendorSkus = $this->productRepository->findVendorSkuSetByCompany($companyId);
        $existingBarcodes   = $this->barcodeRepository->findBarcodeSetByCompany($companyId);

        $created = 0;
        $skipped = 0;
        $errors  = [];

        /** @var array<array{product: Product, row: ParsedProductRow}> $batch */
        $batch = [];

        foreach ($rows as $row) {
            try {
                $this->validateRow($row);

                if ($this->isDuplicate($row, $existingVendorSkus, $existingBarcodes)) {
                    $errors[] = ImportRowError::duplicate(
                        $row->rowNumber,
                        sprintf(
                            'Строка %d: товар с артикулом "%s" или баркодом уже существует.',
                            $row->rowNumber,
                            $row->vendorSku ?? '',
                        ),
                    );
                    ++$skipped;
                    continue;
                }

                $product = $this->buildProduct($row, $companyId, $company);
                $this->entityManager->persist($product);

                foreach ($row->parseBarcodes() as $index => $barcode) {
                    $pb = new ProductBarcode(
                        id:        Uuid::uuid7()->toString(),
                        companyId: $companyId,
                        product:   $product,
                        barcode:   $barcode,
                        type:      ProductBarcode::TYPE_EAN13,
                        isPrimary: 0 === $index,
                    );
                    $this->entityManager->persist($pb);

                    // Обновляем локальный set — дедубликация дублей внутри одного файла
                    $existingBarcodes[$barcode] = true;
                }

                if (null !== $row->vendorSku) {
                    $existingVendorSkus[strtolower($row->vendorSku)] = true;
                }

                $batch[] = ['product' => $product, 'row' => $row];
                ++$created;
            } catch (\DomainException $e) {
                $errors[] = ImportRowError::validation($row->rowNumber, $e->getMessage());
                ++$skipped;
            }
        }

        // Один flush для всех товаров и баркодов батча
        $this->entityManager->flush();

        // Цены создаём после flush — нужен persisted product с id
        foreach ($batch as ['product' => $product, 'row' => $row]) {
            if ($row->hasPrice()) {
                $this->createPrice($product, $row, $companyId);
            }
        }

        return new ImportResult($created, $skipped, $errors);
    }

    private function buildProduct(ParsedProductRow $row, string $companyId, Company $company): Product
    {
        $internalArticle = $this->articleGenerator->generate($companyId);

        // SKU: используем vendorSku если задан, иначе внутренний артикул
        $sku = (null !== $row->vendorSku && '' !== trim($row->vendorSku))
            ? trim($row->vendorSku)
            : $internalArticle;

        $product = new Product(Uuid::uuid7()->toString(), $company);
        $product
            ->setName(trim((string) $row->name))
            ->setSku($sku)
            ->setVendorSku($row->vendorSku)
            ->setStatus(ProductStatus::ACTIVE)
            ->assignInternalArticle($internalArticle);

        return $product;
    }

    private function createPrice(Product $product, ParsedProductRow $row, string $companyId): void
    {
        $cmd                = new SetPurchasePriceCommand();
        $cmd->companyId     = $companyId;
        $cmd->productId     = $product->getId();
        $cmd->effectiveFrom = new \DateTimeImmutable('today');
        $cmd->priceAmount   = $row->priceAmount ?? '0.00';
        $cmd->currency      = $row->resolvedCurrency();
        $cmd->note          = 'Импорт из XLS';

        ($this->setPurchasePriceAction)($cmd);
    }

    private function validateRow(ParsedProductRow $row): void
    {
        if (null === $row->name || '' === trim($row->name)) {
            throw new \DomainException(
                sprintf('Строка %d: наименование обязательно.', $row->rowNumber)
            );
        }

        if ($row->hasPrice() && (!is_numeric($row->priceAmount) || (float) $row->priceAmount < 0)) {
            throw new \DomainException(
                sprintf('Строка %d: цена должна быть числом >= 0.', $row->rowNumber)
            );
        }
    }

    private function isDuplicate(
        ParsedProductRow $row,
        array $existingVendorSkus,
        array $existingBarcodes,
    ): bool {
        if (null !== $row->vendorSku && isset($existingVendorSkus[strtolower($row->vendorSku)])) {
            return true;
        }

        foreach ($row->parseBarcodes() as $barcode) {
            if (isset($existingBarcodes[$barcode])) {
                return true;
            }
        }

        return false;
    }
}
