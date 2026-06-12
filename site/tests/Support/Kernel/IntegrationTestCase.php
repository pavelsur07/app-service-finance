<?php

declare(strict_types=1);

namespace App\Tests\Support\Kernel;

use App\Tests\Support\Db\DbReset;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

abstract class IntegrationTestCase extends KernelTestCase
{
    protected EntityManagerInterface $em;

    protected function setUp(): void
    {
        self::bootKernel();

        $this->em = self::getContainer()->get(EntityManagerInterface::class);
    }

    protected function tearDown(): void
    {
        if (isset($this->em)) {
            $this->em->clear();
            $this->em->getConnection()->close();
        }

        parent::tearDown();
        self::ensureKernelShutdown();
    }

    protected function resetDb(): void
    {
        if (class_exists(\DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension::class)
            && \DAMA\DoctrineTestBundle\PHPUnit\PHPUnitExtension::$transactionStarted
        ) {
            return;
        }

        (new DbReset())->reset($this->em);
    }
}
