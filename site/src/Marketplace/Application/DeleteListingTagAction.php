<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Exception\ListingTagNotFoundException;
use App\Marketplace\Repository\MarketplaceListingTagRepository;
use Doctrine\ORM\EntityManagerInterface;

final class DeleteListingTagAction
{
    public function __construct(
        private readonly MarketplaceListingTagRepository $tagRepository,
        private readonly EntityManagerInterface $em,
    ) {
    }

    public function __invoke(string $companyId, string $tagId): void
    {
        $tag = $this->tagRepository->findById($companyId, $tagId);
        if (null === $tag) {
            throw new ListingTagNotFoundException($tagId);
        }

        // FK ON DELETE CASCADE снимает тег со всех листингов автоматически.
        $this->tagRepository->remove($tag);
        $this->em->flush();
    }
}
