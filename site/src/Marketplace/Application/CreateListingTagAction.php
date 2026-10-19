<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Entity\MarketplaceListingTag;
use App\Marketplace\Exception\ListingTagNameConflictException;
use App\Marketplace\Repository\MarketplaceListingTagRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

/**
 * Создаёт тег без назначений — кнопка «Добавить» на странице управления тегами.
 */
final class CreateListingTagAction
{
    public function __construct(
        private readonly MarketplaceListingTagRepository $tagRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(string $companyId, string $name): MarketplaceListingTag
    {
        if (null !== $this->tagRepository->findBySlug($companyId, MarketplaceListingTag::slugify($name))) {
            throw new ListingTagNameConflictException(trim($name));
        }

        $tag = new MarketplaceListingTag(Uuid::uuid7()->toString(), $companyId, $name);

        // ponytail: гонка двух одновременных созданий упрётся в uniq_listing_tag_company_slug
        // и вернёт 500 без порчи данных — как в AssignListingTagAction::findOrCreate().
        $this->tagRepository->add($tag);
        $this->em->flush();

        return $tag;
    }
}
