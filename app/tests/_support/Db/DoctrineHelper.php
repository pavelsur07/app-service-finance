<?php

declare(strict_types=1);

namespace App\Tests\_support\Db;

use Doctrine\ORM\EntityManagerInterface;

/**
 * Хелпер для работы с Doctrine в тестах (builders-first);
 * работа с БД остаётся явной и не скрывается.
 */
final class DoctrineHelper
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function persistAndFlush(object ...$entities): void
    {
        foreach ($entities as $entity) {
            $this->em->persist($entity);
        }

        $this->em->flush();
    }

    public function flushAndClear(): void
    {
        $this->em->flush();
        $this->em->clear();
    }

    public function refresh(object $entity): void
    {
        $this->em->refresh($entity);
    }
}
