<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\Flush;

use Doctrine\ORM\EntityManagerInterface;

final class InvoiceImportHandler
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(): void
    {
        $this->em->flush();
    }
}
