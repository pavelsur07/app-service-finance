<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Api;

use App\Marketplace\Application\AssignListingTagAction;
use App\Marketplace\Application\DTO\ListingTagPayload;
use App\Marketplace\Exception\InvalidListingTagPayloadException;
use App\Marketplace\Exception\ListingTagNotFoundException;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    '/api/marketplace/listings/tags/assign',
    name: 'api_marketplace_listing_tags_assign',
    methods: ['POST'],
)]
#[IsGranted('ROLE_USER')]
final class ListingTagAssignController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly AssignListingTagAction $assignListingTag,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();

        try {
            $payload = ListingTagPayload::forAssign(json_decode($request->getContent(), true));
        } catch (InvalidListingTagPayloadException $e) {
            return $this->error($e->errorCode, $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $result = ($this->assignListingTag)((string) $company->getId(), $payload);
        } catch (ListingTagNotFoundException $e) {
            return $this->error('tag_not_found', $e->getMessage(), Response::HTTP_NOT_FOUND);
        }

        return $this->json([
            'tagId' => $result->tagId,
            'tagName' => $result->tagName,
            'assigned' => $result->assigned,
        ]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return $this->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
