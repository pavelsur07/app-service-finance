<?php

declare(strict_types=1);

namespace App\Company\Controller\ProjectDirection;

use App\Company\Application\ProjectDirection\ProjectDirectionExchange;
use App\Company\Security\ModuleAccess;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Component\HttpFoundation\HeaderUtils;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/project-directions/export', name: 'project_direction_export', methods: ['GET'])]
#[IsGranted(ModuleAccess::FINANCE_READ)]
final class ExportProjectDirectionsController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompany,
        private readonly ProjectDirectionExchange $exchange,
    ) {
    }

    public function __invoke(): JsonResponse
    {
        $companyId = (string) $this->activeCompany->getActiveCompany()->getId();
        $response = new JsonResponse(headers: [
            'Content-Disposition' => HeaderUtils::makeDisposition(HeaderUtils::DISPOSITION_ATTACHMENT, 'project-directions.json'),
            'Cache-Control' => 'private, no-store',
        ]);
        $response->setEncodingOptions(JsonResponse::DEFAULT_ENCODING_OPTIONS | \JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE);

        return $response->setData($this->exchange->export($companyId));
    }
}
