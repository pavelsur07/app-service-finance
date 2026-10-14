<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\Flush;

use Doctrine\ORM\EntityManagerInterface;

final class ReportService
{
    public function __construct(private readonly ?EntityManagerInterface $em)
    {
    }

    public function save(): void
    {
        $this->em?->flush();
        $this->em?->wrapInTransaction(static fn (): null => null);
    }
}
