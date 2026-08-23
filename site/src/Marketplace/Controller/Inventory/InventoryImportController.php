<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Inventory;

use App\Company\Security\ModuleAccess;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Message\ImportInventoryCostPriceMessage;
use App\Shared\Service\ActiveCompanyService;
use App\Shared\Service\Storage\ObjectStorageInterface;
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
        private readonly ObjectStorageInterface $storage,
        private readonly MessageBusInterface $messageBus,
    ) {
    }

    #[Route('/import-cost-price', name: 'marketplace_inventory_import_cost_price', methods: ['POST'])]
    #[IsGranted(ModuleAccess::MARKETPLACE_WRITE)]
    public function __invoke(Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();
        $companyId = (string) $company->getId();
        $user = $this->getUser();

        if (!$this->isCsrfTokenValid('marketplace_inventory_import_cost_price', (string) $request->request->get('_token', ''))) {
            throw $this->createAccessDeniedException('Недействительный CSRF-токен');
        }

        $file = $request->files->get('cost_file');
        $effectiveFrom = (string) $request->request->get('effective_from', '');
        $marketplace = (string) $request->request->get('marketplace', '');
        $identifierType = (string) $request->request->get('identifier_type', 'barcode');

        $marketplaceType = $marketplace ? MarketplaceType::tryFrom($marketplace) : null;
        if (null === $marketplaceType) {
            $this->addFlash('error', 'Укажите маркетплейс.');

            return $this->redirectToRoute('marketplace_inventory_index');
        }

        $allowedIdentifierTypes = ['barcode', 'marketplace_sku', 'supplier_sku'];
        if (!in_array($identifierType, $allowedIdentifierTypes, true)) {
            $this->addFlash('error', 'Некорректный тип идентификатора для импорта.');

            return $this->redirectToRoute('marketplace_inventory_index');
        }

        if ('marketplace_sku' === $identifierType && MarketplaceType::OZON !== $marketplaceType) {
            $this->addFlash('error', 'Импорт по SKU маркетплейса доступен только для Ozon.');

            return $this->redirectToRoute('marketplace_inventory_index');
        }

        if (
            'supplier_sku' === $identifierType
            && !in_array($marketplaceType, [MarketplaceType::OZON, MarketplaceType::WILDBERRIES], true)
        ) {
            $this->addFlash('error', 'Импорт по артикулу продавца доступен только для Ozon и Wildberries.');

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
            $stored = $this->storage->write($relativePath, $file->getContent());
        } catch (\Exception $e) {
            $this->addFlash('error', 'Ошибка сохранения файла: '.$e->getMessage());

            return $this->redirectToRoute('marketplace_inventory_index');
        }

        $originalFilename = $file->getClientOriginalName();

        $this->messageBus->dispatch(new ImportInventoryCostPriceMessage(
            companyId: $companyId,
            storagePath: $stored->path,
            originalFilename: $originalFilename,
            effectiveFrom: $effectiveFrom,
            marketplace: $marketplaceType->value,
            identifierType: $identifierType,
            actorUserId: (string) $user->getId(),
        ));

        $this->addFlash('success', sprintf(
            'Файл "%s" принят в обработку. Себестоимость будет обновлена в течение нескольких секунд.',
            $originalFilename,
        ));

        return $this->redirectToRoute('marketplace_inventory_index');
    }
}
