<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Api;

use App\Marketplace\Application\MergeListingTagsAction;
use App\Marketplace\Exception\ListingTagNotFoundException;
use App\Shared\Service\ActiveCompanyService;
use Ramsey\Uuid\Uuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    '/api/marketplace/listings/tags/merge',
    name: 'api_marketplace_listing_tags_merge',
    methods: ['POST'],
)]
#[IsGranted('ROLE_USER')]
final class ListingTagMergeController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly MergeListingTagsAction $mergeListingTags,
    ) {
    }

    public function __invoke(Request $request): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();

        $data = json_decode($request->getContent(), true);
        $sourceTagId = is_array($data) && isset($data['sourceTagId']) && is_string($data['sourceTagId']) ? $data['sourceTagId'] : '';
        $targetTagId = is_array($data) && isset($data['targetTagId']) && is_string($data['targetTagId']) ? $data['targetTagId'] : '';

        if (!Uuid::isValid($sourceTagId) || !Uuid::isValid($targetTagId)) {
            return $this->error('tag_id_invalid', 'sourceTagId и targetTagId должны быть uuid.', Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            ($this->mergeListingTags)((string) $company->getId(), $sourceTagId, $targetTagId);
        } catch (\InvalidArgumentException $e) {
            return $this->error('tag_merge_invalid', $e->getMessage(), Response::HTTP_UNPROCESSABLE_ENTITY);
        } catch (ListingTagNotFoundException $e) {
            return $this->error('tag_not_found', $e->getMessage(), Response::HTTP_NOT_FOUND);
        }

        return $this->json(['merged' => true]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return $this->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
