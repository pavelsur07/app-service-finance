<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\Repository;

use App\Tests\Unit\PHPStan\data\Entity\LoanScheduleItem;
use Doctrine\ORM\EntityRepository;

/**
 * Фикстура: владение компанией транзитивное. Редакция, смотревшая только на
 * собственные поля сущности, считала такой репозиторий глобальным.
 *
 * @extends EntityRepository<LoanScheduleItem>
 */
final class LoanScheduleItemRepository extends EntityRepository
{
    /** Нарушение: сущность владеет компанией через Order. */
    public function findUnpaid(string $orderId): array
    {
        return [$orderId];
    }

    /** Нарушения нет: префикс stream тоже читающий, но параметр есть. */
    public function streamAll(string $companyId): iterable
    {
        return [$companyId];
    }

    /** Нарушение: stream без параметра — префикс добавлен после находки ревью. */
    public function streamByFilters(?string $marketplace): iterable
    {
        return [$marketplace];
    }

    /** Нарушения нет: однострочный отказ с причиной. */
    /** @companyScopeExempt служебный обход всех компаний из CLI */
    public function findAcrossCompanies(): array
    {
        return [];
    }
}
