<?php

namespace App\Marketplace\Controller;

use App\Marketplace\Infrastructure\Query\UnmappedListingsQuery;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Страница unmapped листингов (без привязки к продуктам)
 */
#[Route('/marketplace/unmapped')]
#[IsGranted('ROLE_USER')]
class UnmappedListingsController extends AbstractController
{
    public function __construct(
        private readonly UnmappedListingsQuery $unmappedListingsQuery,
        private readonly ActiveCompanyService $activeCompanyService
    ) {
    }

    /**
     * Список unmapped листингов
     */
    #[Route('', name: 'marketplace_unmapped_listings_index', methods: ['GET'])]
    public function index(): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();

        // Получаем unmapped листинги
        $listings = $this->unmappedListingsQuery->fetchAllForCompany($company->getId());
        $unmappedCount = $this->unmappedListingsQuery->countUnmappedForCompany($company->getId());

        return $this->render('marketplace/unmapped/index.html.twig', [
            'listings' => $listings,
            'unmapped_count' => $unmappedCount,
        ]);
    }
}
