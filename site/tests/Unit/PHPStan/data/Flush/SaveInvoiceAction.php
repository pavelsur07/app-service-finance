<?php

declare(strict_types=1);

namespace App\Tests\Unit\PHPStan\data\Flush;

use Doctrine\ORM\EntityManagerInterface;

final class SaveInvoiceAction
{
    public function __construct(private readonly EntityManagerInterface $em)
    {
    }

    public function __invoke(object $invoice): void
    {
        $this->em->persist($invoice);
        $this->em->flush();
    }
}
