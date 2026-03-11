<?php

declare(strict_types=1);

namespace App\Catalog\Controller;

use App\Catalog\Entity\ProductImport;
use App\Catalog\Infrastructure\Repository\ProductImportRepository;
use App\Catalog\Message\ImportProductsMessage;
use App\Shared\Service\ActiveCompanyService;
use App\Shared\Service\Storage\StorageService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

final class ProductImportController extends AbstractController
{
    /**
     * Страница загрузки XLS-файла.
     */
    #[Route('/catalog/products/import', name: 'catalog_products_import', methods: ['GET', 'POST'])]
    #[IsGranted('ROLE_USER')]
    public function import(
        Request $request,
        ActiveCompanyService $activeCompanyService,
        StorageService $storageService,
        ProductImportRepository $importRepository,
        MessageBusInterface $bus,
    ): Response {
        // ✅ ActiveCompanyService — только в Controller
        $companyId = $activeCompanyService->getActiveCompany()->getId();

        if (!$request->isMethod('POST')) {
            return $this->render('catalog/product/import.html.twig');
        }

        $file = $request->files->get('file');
        if (null === $file) {
            $this->addFlash('error', 'Файл не выбран.');
            return $this->render('catalog/product/import.html.twig');
        }

        $extension = strtolower($file->getClientOriginalExtension());
        if (!in_array($extension, ['xls', 'xlsx'], true)) {
            $this->addFlash('error', 'Допустимые форматы: xls, xlsx.');
            return $this->render('catalog/product/import.html.twig');
        }

        $importId     = Uuid::uuid7()->toString();
        $relativePath = sprintf('product_imports/%s/%s.%s', $companyId, $importId, $extension);

        $stored = $storageService->storeUploadedFile($file, $relativePath);

        $import = new ProductImport(
            id:           $importId,
            companyId:    $companyId,
            filePath:     $stored['storagePath'],
            originalName: $stored['originalFilename'],
        );
        $importRepository->save($import);

        // ✅ companyId передаётся как string — ActiveCompanyService в Handler запрещён
        $bus->dispatch(new ImportProductsMessage(
            companyId: $companyId,
            importId:  $importId,
        ));

        return $this->redirectToRoute('catalog_products_import_status', ['importId' => $importId]);
    }

    /**
     * Страница статуса импорта с polling.
     */
    #[Route('/catalog/products/import/{importId}', name: 'catalog_products_import_status', methods: ['GET'])]
    #[IsGranted('ROLE_USER')]
    public function status(
        string $importId,
        ActiveCompanyService $activeCompanyService,
        ProductImportRepository $importRepository,
    ): Response {
        // ✅ ActiveCompanyService — только в Controller
        $companyId = $activeCompanyService->getActiveCompany()->getId();

        $import = $importRepository->findById($importId);

        if (null === $import || $import->getCompanyId() !== $companyId) {
            throw $this->createNotFoundException('Импорт не найден.');
        }

        return $this->render('catalog/product/import_status.html.twig', [
            'import' => $import,
        ]);
    }
}
