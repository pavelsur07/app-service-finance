<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Inventory;

use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Message\ImportInventoryCostPriceMessage;
use App\Marketplace\Message\SyncOzonListingBarcodesMessage;
use App\Shared\Service\ActiveCompanyService;
use App\Shared\Service\Storage\StorageService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/marketplace/inventory')]
#[IsGranted('ROLE_USER')]
final class InventoryImportController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly StorageService       $storageService,
        private readonly MessageBusInterface  $messageBus,
    ) {
    }

    #[Route('/import-cost-price', name: 'marketplace_inventory_import_cost_price', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $company   = $this->companyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $file          = $request->files->get('cost_file');
        $effectiveFrom = (string) $request->request->get('effective_from', '');
        $marketplace   = (string) $request->request->get('marketplace', '');

        $marketplaceType = $marketplace ? MarketplaceType::tryFrom($marketplace) : null;
        if ($marketplaceType === null) {
            $this->addFlash('error', 'Укажите маркетплейс.');

            return $this->redirectToRoute('marketplace_inventory_index');
        }

        if (!$file || !$file->isValid()) {
            $this->addFlash('error', 'Файл не загружен или повреждён.');

            return $this->redirectToRoute('marketplace_inventory_index');
        }

        $ext = strtolower($file->getClientOriginalExtension());
        if (!in_array($ext, ['xls', 'xlsx'], true)) {
            $this->addFlash('error', 'Допустимые форматы: xls, xlsx.');

            return $this->redirectToRoute('marketplace_inventory_index');
        }

        try {
            new \DateTimeImmutable($effectiveFrom);
        } catch (\Exception) {
            $this->addFlash('error', 'Некорректная дата.');

            return $this->redirectToRoute('marketplace_inventory_index');
        }

        $relativePath = sprintf(
            'inventory/cost-import/%s/%s.%s',
            $companyId,
            Uuid::uuid4()->toString(),
            $ext,
        );

        try {
            $stored = $this->storageService->storeUploadedFile($file, $relativePath);
        } catch (\Exception $e) {
            $this->addFlash('error', 'Ошибка сохранения файла: ' . $e->getMessage());

            return $this->redirectToRoute('marketplace_inventory_index');
        }

        $this->messageBus->dispatch(new ImportInventoryCostPriceMessage(
            companyId:        $companyId,
            storagePath:      $stored['storagePath'],
            originalFilename: $stored['originalFilename'],
            effectiveFrom:    $effectiveFrom,
            marketplace:      $marketplaceType->value,
        ));

        $this->addFlash('success', sprintf(
            'Файл "%s" принят в обработку. Себестоимость будет обновлена в течение нескольких секунд.',
            $stored['originalFilename'],
        ));

        return $this->redirectToRoute('marketplace_inventory_index');
    }


// -------------------------------------------------------------------------
// Добавить в InventoryController:
// 1. В use-блок:
//      use App\Marketplace\Message\SyncOzonListingBarcodesMessage;
//      use Symfony\Component\Messenger\MessageBusInterface;
// 2. В конструктор добавить:
//      private readonly MessageBusInterface $messageBus,
// 3. Метод ниже добавить в тело класса
// -------------------------------------------------------------------------

    #[Route('/sync-barcodes', name: 'marketplace_inventory_sync_barcodes', methods: ['POST'])]
    public function syncBarcodes(): Response
    {
        $company = $this->companyService->getActiveCompany();
        $companyId = (string)$company->getId();

        $this->messageBus->dispatch(new SyncOzonListingBarcodesMessage(
            companyId: $companyId,
        ));

        $this->addFlash('success', 'Синхронизация баркодов Ozon запущена. Данные обновятся в течение нескольких секунд.');

        return $this->redirectToRoute('marketplace_inventory_index');
    }
}
