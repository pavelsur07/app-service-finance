<?php

declare(strict_types=1);

namespace App\Finance\Application;

use App\Finance\Entity\PLCategory;
use Doctrine\ORM\EntityManagerInterface;

/**
 * Статья ОПиУ из формы (создание и правка): один агрегат — одна запись, граница транзакции здесь,
 * а не в контроллере.
 */
final readonly class SavePLCategoryAction
{
    public function __construct(
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(PLCategory $category): void
    {
        $this->entityManager->persist($category);
        $this->entityManager->flush();
    }
}
