<?php

declare(strict_types=1);

namespace App\Marketplace\Application;

use App\Marketplace\Entity\MarketplaceListingTag;
use App\Marketplace\Exception\ListingTagNameConflictException;
use App\Marketplace\Repository\MarketplaceListingTagRepository;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
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

        $this->tagRepository->add($tag);

        try {
            $this->em->flush();
        } catch (UniqueConstraintViolationException $exception) {
            // Гонка между проверкой выше и flush: одноимённый тег создал параллельный
            // запрос, последнее слово за uniq_listing_tag_company_slug. Ожидаемый конфликт.
            throw new ListingTagNameConflictException(trim($name), $exception);
        }

        return $tag;
    }
}
