<?php

declare(strict_types=1);

namespace App\MarketplaceAnalytics\Controller\Api;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAnalytics\Infrastructure\Export\UnitExtendedExportRequest;
use App\MarketplaceAnalytics\Infrastructure\Export\UnitExtendedXlsxExporter;
use App\Shared\Service\ActiveCompanyService;
use App\Shared\Service\RateLimiter\ReportsApiRateLimiter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    '/api/marketplace-analytics/unit-extended/export',
    name: 'marketplace_analytics_api_unit_extended_export',
    methods: ['GET'],
)]
#[IsGranted('ROLE_COMPANY_USER')]
final class UnitExtendedExportController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly UnitExtendedXlsxExporter $exporter,
        private readonly ReportsApiRateLimiter $rateLimiter,
    ) {
    }

    public function __invoke(Request $request): Response
    {
        $company = $this->companyService->getActiveCompany();
        $companyId = (string) $company->getId();

        if (!$this->rateLimiter->consume($companyId)) {
            return new JsonResponse(['error' => 'Too many requests'], 429);
        }

        $marketplace = $request->query->get('marketplace');
        if (null === $marketplace || '' === $marketplace) {
            $marketplace = null;
        } else {
            $validValues = array_map(
                static fn (MarketplaceType $t): string => $t->value,
                MarketplaceType::cases(),
            );
            if (!in_array($marketplace, $validValues, true)) {
                return new JsonResponse([
                    'error' => 'Invalid marketplace. Allowed: '.implode(', ', $validValues),
                ], 400);
            }
        }

        $periodFrom = (string) $request->query->get('periodFrom', '');
        $periodTo = (string) $request->query->get('periodTo', '');

        if ('' === $periodFrom || '' === $periodTo) {
            return new JsonResponse(['error' => 'periodFrom and periodTo are required'], 400);
        }

        if (!$this->isValidIsoDate($periodFrom) || !$this->isValidIsoDate($periodTo)) {
            return new JsonResponse(['error' => 'periodFrom and periodTo must be in YYYY-MM-DD format'], 400);
        }

        if ($periodFrom > $periodTo) {
            return new JsonResponse(['error' => 'periodFrom must be <= periodTo'], 400);
        }

        $exportRequest = new UnitExtendedExportRequest(
            companyId: $companyId,
            marketplace: $marketplace,
            periodFrom: $periodFrom,
            periodTo: $periodTo,
        );

        $exporter = $this->exporter;
        $response = new StreamedResponse(static function () use ($exporter, $exportRequest): void {
            $tmpFile = tempnam(sys_get_temp_dir(), 'unit_export_');
            if (false === $tmpFile) {
                throw new \RuntimeException('Unable to create temporary file for export');
            }

            try {
                $exporter->export($exportRequest, $tmpFile);
                readfile($tmpFile);
            } finally {
                if (file_exists($tmpFile)) {
                    unlink($tmpFile);
                }
            }
        });

        $response->headers->set('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $response->headers->set(
            'Content-Disposition',
            sprintf('attachment; filename="%s"', $exportRequest->buildFilename()),
        );
        $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
        $response->headers->set('X-Accel-Buffering', 'no');

        return $response;
    }

    private function isValidIsoDate(string $value): bool
    {
        $date = \DateTimeImmutable::createFromFormat('Y-m-d', $value);

        return $date instanceof \DateTimeImmutable && $date->format('Y-m-d') === $value;
    }
}
