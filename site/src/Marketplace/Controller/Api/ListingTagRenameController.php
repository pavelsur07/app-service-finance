<?php

declare(strict_types=1);

namespace App\Marketplace\Controller\Api;

use App\Company\Security\ModuleAccess;
use App\Marketplace\Application\RenameListingTagAction;
use App\Marketplace\Entity\MarketplaceListingTag;
use App\Marketplace\Exception\ListingTagNameConflictException;
use App\Marketplace\Exception\ListingTagNotFoundException;
use App\Shared\Service\ActiveCompanyService;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[Route(
    '/api/marketplace/listings/tags/{tagId}/rename',
    name: 'api_marketplace_listing_tags_rename',
    methods: ['POST'],
)]
#[IsGranted('ROLE_USER')]
final class ListingTagRenameController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly RenameListingTagAction $renameListingTag,
    ) {
    }

    #[IsGranted(ModuleAccess::MARKETPLACE_WRITE)]
    public function __invoke(string $tagId, Request $request): JsonResponse
    {
        $company = $this->activeCompanyService->getActiveCompany();

        $data = json_decode($request->getContent(), true);
        $name = is_array($data) && isset($data['name']) && is_string($data['name']) ? trim($data['name']) : '';

        if ('' === $name || \mb_strlen($name) > MarketplaceListingTag::NAME_MAX_LENGTH) {
            return $this->error('tag_name_invalid', sprintf('Название тега должно быть от 1 до %d символов.', MarketplaceListingTag::NAME_MAX_LENGTH), Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        try {
            $tag = ($this->renameListingTag)((string) $company->getId(), $tagId, $name);
        } catch (ListingTagNotFoundException $e) {
            return $this->error('tag_not_found', $e->getMessage(), Response::HTTP_NOT_FOUND);
        } catch (ListingTagNameConflictException $e) {
            return $this->error('tag_name_conflict', $e->getMessage(), Response::HTTP_CONFLICT);
        }

        return $this->json(['id' => $tag->getId(), 'name' => $tag->getName()]);
    }

    private function error(string $code, string $message, int $status): JsonResponse
    {
        return $this->json(['error' => ['code' => $code, 'message' => $message]], $status);
    }
}
