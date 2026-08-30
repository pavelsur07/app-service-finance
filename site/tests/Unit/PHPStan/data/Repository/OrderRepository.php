<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\Repository;

use App\Tests\Unit\PHPStan\data\DTO\DocumentFilter;
use App\Tests\Unit\PHPStan\data\Entity\Order;
use Doctrine\ORM\EntityRepository;

/**
 * Фикстура правила RepositoryCompanyScopeRule: tenant-scoped репозиторий.
 *
 * @extends EntityRepository<Order>
 */
final class OrderRepository extends EntityRepository
{
    /** Нарушение: метод-запрос без идентификатора компании. */
    public function findByStatus(string $status): array
    {
        return [$status];
    }

    /** Нарушение: префикс `is` тоже запрос — первая редакция его пропускала. */
    public function isArchived(string $orderId): bool
    {
        return '' !== $orderId;
    }

    /** Нарушения нет: параметр $companyId присутствует. */
    public function findByCompany(string $companyId, string $status): array
    {
        return [$companyId, $status];
    }

    /** Нарушения нет: сущность компании тоже ограничивает выборку. */
    public function countForCompany(object $company): int
    {
        return $company instanceof \stdClass ? 1 : 0;
    }

    /** Нарушение: параметр правильно назван, но nullable — гарантии нет. */
    public function findByNullableCompanyId(?string $companyId): array
    {
        return [$companyId];
    }

    /** Нарушение: параметр правильно назван, но без типа. */
    public function findByUntypedCompanyId($companyId): array
    {
        return [$companyId];
    }

    /**
     * Нарушение: тег закрыт на своей же строке, причины нет.
     *
     * @companyScopeExempt */
    public function findWithTagClosedOnSameLine(string $id): array
    {
        return [$id];
    }

    /** Нарушение: компания спрятана в DTO — правило требует явный параметр. */
    public function findByFilter(DocumentFilter $filter): array
    {
        return [$filter];
    }

    /**
     * Нарушения нет: явный отказ с причиной.
     *
     * @companyScopeExempt агрегат по платформе для биллинга, компания не применима
     */
    public function sumAcrossAllCompanies(): int
    {
        return 0;
    }

    /**
     * Нарушение: тег отказа без причины.
     *
     * @companyScopeExempt
     */
    public function findWithBareExemptTag(string $id): array
    {
        return [$id];
    }

    /**
     * Нарушение: тег упомянут в прозе, а не как отказ.
     * Здесь мог бы стоять @companyScopeExempt, но причины нет и тег не в начале.
     */
    public function findMentioningTagInProse(string $id): array
    {
        return [$id];
    }

    /** Нарушения нет: не метод-запрос. */
    public function save(object $order): void
    {
    }

    /** Нарушения нет: непубличный метод. */
    private function findInternal(string $id): array
    {
        return [$id];
    }
}
