<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Application\DTO\ListingTagAssignResult;
use App\Marketplace\Application\DTO\ListingTagPayload;
use App\Marketplace\Entity\MarketplaceListingTag;
use App\Marketplace\Exception\ListingTagNotFoundException;
use App\Marketplace\Infrastructure\Query\ListingTagAssignmentRepository;
use App\Marketplace\Repository\MarketplaceListingTagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Назначает тег набору листингов: либо существующий по tagId,
 * либо создаваемый на лету по name (чип «Создать» в реестре).
 */
final class AssignListingTagAction
{
    public function __construct(
        private readonly MarketplaceListingTagRepository $tagRepository,
        private readonly ListingTagAssignmentRepository $assignments,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(string $companyId, ListingTagPayload $payload): ListingTagAssignResult
    {
        $tag = null !== $payload->tagId
            ? $this->requireTag($companyId, $payload->tagId)
            : $this->findOrCreate($companyId, (string) $payload->tagName);

        $assigned = $this->assignments->assign($companyId, $payload->listingIds, $tag->getId());

        return new ListingTagAssignResult($tag->getId(), $tag->getName(), $assigned);
    }

    private function requireTag(string $companyId, string $tagId): MarketplaceListingTag
    {
        $tag = $this->tagRepository->findById($companyId, $tagId);

        if (null === $tag) {
            throw new ListingTagNotFoundException($tagId);
        }

        return $tag;
    }

    private function findOrCreate(string $companyId, string $name): MarketplaceListingTag
    {
        $existing = $this->tagRepository->findBySlug($companyId, MarketplaceListingTag::slugify($name));

        if (null !== $existing) {
            return $existing;
        }

        $tag = new MarketplaceListingTag(Uuid::uuid7()->toString(), $companyId, $name);

        // ponytail: одновременное создание одноимённого тега двумя запросами упрётся
        // в uniq_listing_tag_company_slug и вернёт 500 — данные при этом целы, повтор
        // запроса находит уже созданный тег. Ловить гонку здесь дороже, чем она стоит.
        $this->tagRepository->add($tag);
        $this->em->flush();

        return $tag;
    }
}
