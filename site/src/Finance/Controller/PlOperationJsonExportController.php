<?php

declare(strict_types=1);

namespace App\Finance\Controller;

use App\Finance\Infrastructure\Export\PlOperationJsonExporter;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class PlOperationJsonExportController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly PlOperationJsonExporter $exporter,
    ) {
    }

    // Путь из двух сегментов: '/documents/export.json' перехватил бы маршрут
    // document_show ('/documents/{id}'), он регистрируется раньше.
    #[Route('/documents/operations/export.json', name: 'document_operations_export_json', methods: ['GET'])]
    public function __invoke(): Response
    {
        $company = $this->companyService->getActiveCompany();
        $exportedAt = new \DateTimeImmutable();

        $payload = $this->exporter->export(
            (string) $company->getId(),
            (string) $company->getName(),
            $exportedAt,
        );

        $response = new JsonResponse($payload);
        $response->setEncodingOptions(\JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);
        $response->headers->set(
            'Content-Disposition',
            \sprintf('attachment; filename="pl-operations-%s.json"', $exportedAt->format('Ymd-His')),
        );

        return $response;
    }
}
