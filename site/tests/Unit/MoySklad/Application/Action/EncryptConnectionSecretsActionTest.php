<?php

declare(strict_types=1);

namespace App\Tests\Unit\MoySklad\Application\Action;

use App\MoySklad\Application\Action\EncryptConnectionSecretsAction;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\MoySklad\Infrastructure\Security\ConnectionTokenCodec;
use App\Shared\Security\Contract\FieldEncryptionServiceInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\TestCase;

final class EncryptConnectionSecretsActionTest extends TestCase
{
    public function testInvalidCompanyCannotReachDatabase(): void
    {
        $registry = $this->createMock(ManagerRegistry::class);
        $registry->expects(self::never())->method('getManagerForClass');
        $action = new EncryptConnectionSecretsAction(
            new MoySkladConnectionWriteRepository($registry),
            $this->createMock(EntityManagerInterface::class),
            new ConnectionTokenCodec($this->createMock(FieldEncryptionServiceInterface::class)),
        );

        $this->expectException(\InvalidArgumentException::class);
        $action('invalid-company', true);
    }
}
