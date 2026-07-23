<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Api;

use App\Marketplace\Application\DetachListingTagAction;
use App\Marketplace\Application\DTO\ListingTagPayload;
use App\Marketplace\Exception\InvalidListingTagPayloadException;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    '/api/marketplace/listings/tags/detach',
    name: 'api_marketplace_listing_tags_detach',
    methods: ['POST'],
)]
#[IsGranted('ROLE_USER')]
final class ListingTagDetachController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly DetachListingTagAction $detachListingTag,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();

        try {
            $payload = ListingTagPayload::forDetach(json_decode($request->getContent(), true));
        } catch (InvalidListingTagPayloadException $e) {
            return $this->json(
                ['error' => ['code' => $e->errorCode, 'message' => $e->getMessage()]],
                Response::HTTP_UNPROCESSABLE_ENTITY,
            );
        }

        $detached = ($this->detachListingTag)((string) $company->getId(), $payload);

        return $this->json(['detached' => $detached]);
    }
}
