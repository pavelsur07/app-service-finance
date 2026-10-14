<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\Flush;

use Doctrine\ORM\EntityManagerInterface;

final class InvoiceService
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function save(object $invoice): void
    {
        $this->em->persist($invoice);
        $this->em->flush();
    }
}
