<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Entity\MarketplaceListingTag;
use App\Marketplace\Exception\ListingTagNameConflictException;
use App\Marketplace\Exception\ListingTagNotFoundException;
use App\Marketplace\Repository\MarketplaceListingTagRepository;
use Doctrine\ORM\EntityManagerInterface;

final class RenameListingTagAction
{
    public function __construct(
        private readonly MarketplaceListingTagRepository $tagRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(string $companyId, string $tagId, string $name): MarketplaceListingTag
    {
        $tag = $this->tagRepository->findById($companyId, $tagId);
        if (null === $tag) {
            throw new ListingTagNotFoundException($tagId);
        }

        // Коллизия только с ДРУГИМ тегом: смена регистра/пробелов у самого тега — не конфликт.
        $slug = MarketplaceListingTag::slugify($name);
        $existing = $this->tagRepository->findBySlug($companyId, $slug);
        if (null !== $existing && $existing->getId() !== $tag->getId()) {
            throw new ListingTagNameConflictException(trim($name));
        }

        $tag->rename($name);
        $this->em->flush();

        return $tag;
    }
}
