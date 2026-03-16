<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Inventory;

use App\Marketplace\Entity\MarketplaceListingBarcode;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Infrastructure\Api\Ozon\OzonProductBarcodeFetcher;
use App\Marketplace\Repository\MarketplaceListingBarcodeRepository;
use App\Marketplace\Repository\MarketplaceListingRepository;
use App\Shared\Service\ActiveCompanyService;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/marketplace/inventory')]
#[IsGranted('ROLE_USER')]
final class InventorySyncSingleBarcodeController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService            $companyService,
        private readonly MarketplaceListingRepository    $listingRepository,
        private readonly MarketplaceListingBarcodeRepository $barcodeRepository,
        private readonly OzonProductBarcodeFetcher       $barcodeFetcher,
        private readonly EntityManagerInterface          $em,
    ) {
    }

    #[Route('/{id}/sync-barcode', name: 'marketplace_inventory_sync_barcode_single', methods: ['POST'])]
    public function __invoke(string $id): Response
    {
        $company   = $this->companyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $listing = $this->listingRepository->findByIdAndCompany($id, $companyId);

        if ($listing === null) {
            throw $this->createNotFoundException('Листинг не найден.');
        }

        if ($listing->getMarketplace() !== MarketplaceType::OZON) {
            $this->addFlash('warning', 'Синхронизация баркодов доступна только для Ozon.');

            return $this->redirectToRoute('marketplace_inventory_index');
        }

        $sku      = $listing->getMarketplaceSku();
        $skuToData = $this->barcodeFetcher->fetchProductDataBySkus($companyId, [$sku]);

        if (empty($skuToData) || empty($skuToData[$sku])) {
            $this->addFlash('warning', sprintf(
                'SKU %s не найден в Ozon API. Возможно товар удалён или является уценённым.',
                $sku,
            ));

            return $this->redirectToRoute('marketplace_inventory_index');
        }

        $data    = $skuToData[$sku];
        $created = 0;

        // Обновляем supplier_sku если не заполнен
        $offerId = $data['offer_id'] ?? null;
        if ($offerId !== null && $offerId !== '' && $listing->getSupplierSku() === null) {
            $listing->setSupplierSku($offerId);
        }

        // Сохраняем новые баркоды
        foreach ($data['barcodes'] as $barcode) {
            if ($barcode === '') {
                continue;
            }

            if ($this->barcodeRepository->existsForCompany($companyId, $barcode)) {
                continue;
            }

            $this->em->persist(new MarketplaceListingBarcode(
                Uuid::uuid4()->toString(),
                $listing,
                $companyId,
                $barcode,
            ));
            $created++;
        }

        $this->em->flush();

        if ($created > 0) {
            $this->addFlash('success', sprintf(
                'Баркод для SKU %s успешно получен.',
                $sku,
            ));
        } else {
            $this->addFlash('info', sprintf(
                'Баркод для SKU %s уже актуален.',
                $sku,
            ));
        }

        return $this->redirectToRoute('marketplace_inventory_index');
    }
}
