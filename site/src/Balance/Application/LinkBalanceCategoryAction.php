<?php

declare(strict_types=1);

namespace App\Balance\Application;

use App\Balance\Application\DTO\LinkBalanceCategoryCommand;
use App\Balance\Entity\BalanceCategoryLink;
use App\Balance\Exception\BalanceCategoryNotFoundException;
use App\Balance\Repository\BalanceCategoryLinkRepository;
use App\Balance\Repository\BalanceCategoryRepositoryInterface;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

final readonly class LinkBalanceCategoryAction
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private BalanceCategoryRepositoryInterface $balanceCategoryRepository,
        private BalanceCategoryLinkRepository $balanceCategoryLinkRepository,
    ) {
    }

    public function __invoke(string $companyId, LinkBalanceCategoryCommand $command): string
    {
        Assert::uuid($companyId);

        $category = $this->balanceCategoryRepository->findByIdAndCompany($command->categoryId, $companyId);
        if (null === $category) {
            throw new BalanceCategoryNotFoundException($command->categoryId);
        }

        $existing = $this->balanceCategoryLinkRepository->findOneBy([
            'companyId' => $companyId,
            'category' => $category,
            'sourceType' => $command->sourceType,
            'sourceId' => $command->sourceId,
        ]);

        if (null !== $existing) {
            return $existing->getId();
        }

        $link = new BalanceCategoryLink(Uuid::uuid7()->toString(), $companyId, $category);
        $link->setSourceType($command->sourceType);
        $link->setSourceId($command->sourceId);
        $link->setSign($command->sign);
        $link->setPosition($command->position);

        $this->entityManager->persist($link);
        $this->entityManager->flush();

        return $link->getId();
    }
}
