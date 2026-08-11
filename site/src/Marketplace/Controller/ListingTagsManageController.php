<?php

declare(strict_types=1);

namespace App\Marketplace\Controller;

use App\Marketplace\DTO\ListingTagDTO;
use App\Marketplace\Infrastructure\Query\ListingTagAssignmentRepository;
use App\Marketplace\Repository\MarketplaceListingTagRepository;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
final class ListingTagsManageController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MarketplaceListingTagRepository $tagRepository,
        private readonly ListingTagAssignmentRepository $assignments,
    ) {
    }

    #[Route('/marketplace/listings/tags', name: 'marketplace_listing_tags_manage', methods: ['GET'])]
    public function __invoke(): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();
        $companyId = (string) $company->getId();

        $counts = $this->assignments->countsByTag($companyId);

        $tags = array_map(
            static fn (ListingTagDTO $tag): array => [
                'id' => $tag->id,
                'name' => $tag->name,
                'listingsCount' => $counts[$tag->id] ?? 0,
            ],
            $this->tagRepository->listForCompany($companyId),
        );

        return $this->render('marketplace/listings/tags.html.twig', [
            'tags' => $tags,
            'active_tab' => 'listings_tags',
        ]);
    }
}
