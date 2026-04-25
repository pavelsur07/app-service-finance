<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Marketplace\Infrastructure\Query\SalesListQuery;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/marketplace')]
#[IsGranted('ROLE_USER')]
final class SalesJsonExportController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $companyService,
        private readonly SalesListQuery       $salesListQuery,
    ) {
    }

    #[Route('/sales/export.json', name: 'marketplace_sales_export_json', methods: ['GET'])]
    public function __invoke(Request $request): Response
    {
        $company     = $this->companyService->getActiveCompany();
        $companyId   = (string) $company->getId();
        $marketplace = $request->query->get('marketplace') ?: null;
        $dateFrom    = $this->parseDate($request->query->all()['date_from'] ?? null);
        $dateTo      = $this->parseDate($request->query->all()['date_to'] ?? null);

        $qb   = $this->salesListQuery->buildQueryBuilder($companyId, $marketplace, $dateFrom, $dateTo);
        $rows = $qb->executeQuery()->fetchAllAssociative();

        $payload = [
            'exported_at' => (new \DateTimeImmutable())->format(\DateTimeInterface::ATOM),
            'filters'     => [
                'marketplace' => $marketplace,
                'date_from'   => $dateFrom?->format('Y-m-d'),
                'date_to'     => $dateTo?->format('Y-m-d'),
            ],
            'count'       => \count($rows),
            'sales'       => $rows,
        ];

        $response = new JsonResponse($payload);
        $response->setEncodingOptions(\JSON_PRETTY_PRINT | \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES);

        $filename = \sprintf('marketplace-sales-%s.json', (new \DateTimeImmutable())->format('Ymd-His'));
        $response->headers->set(
            'Content-Disposition',
            \sprintf('attachment; filename="%s"', $filename),
        );

        return $response;
    }

    private function parseDate(mixed $raw): ?\DateTimeImmutable
    {
        if (!is_string($raw) || $raw === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $raw);
        if ($date === false || $date->format('Y-m-d') !== $raw) {
            return null;
        }

        return $date;
    }
}
