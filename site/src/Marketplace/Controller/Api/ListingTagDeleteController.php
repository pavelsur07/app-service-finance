<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Api;

use App\Marketplace\Application\DeleteListingTagAction;
use App\Marketplace\Exception\ListingTagNotFoundException;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    '/api/marketplace/listings/tags/{tagId}/delete',
    name: 'api_marketplace_listing_tags_delete',
    methods: ['POST'],
)]
#[IsGranted('ROLE_USER')]
final class ListingTagDeleteController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly DeleteListingTagAction $deleteListingTag,
    ) {
    }

    public function __invoke(string $tagId): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();

        try {
            ($this->deleteListingTag)((string) $company->getId(), $tagId);
        } catch (ListingTagNotFoundException $e) {
            return $this->json(
                ['error' => ['code' => 'tag_not_found', 'message' => $e->getMessage()]],
                Response::HTTP_NOT_FOUND,
            );
        }

        return $this->json(['deleted' => true]);
    }
}
