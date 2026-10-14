<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\Flush;

use Doctrine\ORM\EntityManagerInterface;

final class InvoiceCleanupCommand
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function execute(): void
    {
        $this->em->flush();
    }
}
