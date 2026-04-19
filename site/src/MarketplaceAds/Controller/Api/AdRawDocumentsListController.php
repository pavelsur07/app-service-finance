<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Controller\Api;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route('/api/marketplace-ads/raw-documents', name: 'marketplace_ads_raw_documents_list', methods: ['GET'])]
#[IsGranted('ROLE_COMPANY_USER')]
final class AdRawDocumentsListController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly AdRawDocumentRepository $rawDocumentRepository,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $dateFromStr = $request->query->get('dateFrom');
        $dateToStr = $request->query->get('dateTo');

        if (null === $dateFromStr && null === $dateToStr) {
            $dateTo = (new \DateTimeImmutable('today'))->setTime(0, 0);
            $dateFrom = $dateTo->modify('-30 days');
        } else {
            $dateFrom = $this->parseDate((string) $dateFromStr);
            $dateTo = $this->parseDate((string) $dateToStr);

            if (null === $dateFrom || null === $dateTo) {
                return $this->json(['message' => 'Неверный формат даты. Ожидается YYYY-MM-DD.'], 400);
            }
        }

        $documents = $this->rawDocumentRepository->findByCompanyMarketplaceAndDateRange(
            $companyId,
            MarketplaceType::OZON->value,
            $dateFrom,
            $dateTo,
        );

        $items = array_map(
            static fn ($doc): array => [
                'id' => $doc->getId(),
                'reportDate' => $doc->getReportDate()->format('Y-m-d'),
                'status' => $doc->getStatus()->value,
                'loadedAt' => $doc->getLoadedAt()->format('d.m.Y H:i'),
                'processingError' => $doc->getProcessingError(),
            ],
            $documents,
        );

        return $this->json(['items' => $items]);
    }

    private function parseDate(string $value): ?\DateTimeImmutable
    {
        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (false === $parsed || $parsed->format('Y-m-d') !== $value) {
            return null;
        }

        return $parsed;
    }
}
