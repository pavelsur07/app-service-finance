<?php

declare(strict_types=1);

namespace App\Catalog\Controller\Api;

use App\Catalog\Application\Command\ImportProductsCommand;
use App\Catalog\Application\ImportProductsFromXlsAction;
use App\Catalog\Entity\ProductImport;
use App\Catalog\Infrastructure\Repository\ProductImportRepository;
use App\Catalog\Message\ImportProductsMessage;
use App\Shared\Service\ActiveCompanyService;
use App\Shared\Service\Storage\StorageService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ProductImportController extends AbstractController
{
    #[Route('/api/catalog/products/import', name: 'api_catalog_products_import', methods: ['POST'])]
    #[IsGranted('ROLE_USER')]
    public function import(
        Request $request,
        ActiveCompanyService $activeCompanyService,
        StorageService $storageService,
        ProductImportRepository $importRepository,
        MessageBusInterface $bus,
    ): JsonResponse {
        // ✅ ActiveCompanyService — только в Controller
        $companyId = $activeCompanyService->getActiveCompany()->getId();

        $file = $request->files->get('file');
        if (null === $file) {
            return $this->json(['error' => 'Файл не загружен.'], 422);
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ['xls', 'xlsx'], true)) {
            return $this->json(['error' => 'Допустимые форматы: xls, xlsx.'], 422);
        }

        $importId    = Uuid::uuid7()->toString();
        $relativePath = sprintf('product_imports/%s/%s.%s', $companyId, $importId, $extension);

        $stored = $storageService->storeUploadedFile($file, $relativePath);

        $import = new ProductImport(
            id:           $importId,
            companyId:    $companyId,
            filePath:     $stored['storagePath'],
            originalName: $stored['originalFilename'],
        );
        $importRepository->save($import);

        // ✅ companyId передаётся как string в Message — ActiveCompanyService в Handler запрещён
        $bus->dispatch(new ImportProductsMessage(
            companyId: $companyId,
            importId:  $importId,
        ));

        return $this->json([
            'importId' => $importId,
            'status'   => $import->getStatus(),
        ], 202);
    }

    #[Route('/api/catalog/products/import/{importId}/status', name: 'api_catalog_products_import_status', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function status(
        string $importId,
        ActiveCompanyService $activeCompanyService,
        ProductImportRepository $importRepository,
    ): JsonResponse {
        // ✅ ActiveCompanyService — только в Controller
        $companyId = $activeCompanyService->getActiveCompany()->getId();

        $import = $importRepository->findById($importId);

        if (null === $import || $import->getCompanyId() !== $companyId) {
            return $this->json(['error' => 'Импорт не найден.'], 404);
        }

        return $this->json([
            'importId'    => $import->getId(),
            'status'      => $import->getStatus(),
            'rowsTotal'   => $import->getRowsTotal(),
            'rowsCreated' => $import->getRowsCreated(),
            'rowsSkipped' => $import->getRowsSkipped(),
            'errors'      => $import->getResultJson() ?? [],
            'finishedAt'  => $import->getFinishedAt()?->format(\DateTimeInterface::ATOM),
        ]);
    }
}
