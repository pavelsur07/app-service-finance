<?php

declare(strict_types=1);

namespace App\Tests\Integration\OneC;

use App\OneC\Application\HandshakeAction;
use App\OneC\Entity\OneCConnection;
use App\Tests\Builders\Company\UserBuilder;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Doctrine\ORM\EntityManager;
use Ramsey\Uuid\Uuid;

final class HandshakeLockTest extends IntegrationTestCase
{
    public function testHandshakeLocksBeforeDecidingWhetherTheBaseMatches(): void
    {
        $writer = DriverManager::getConnection($this->connection->getParams());
        $em = new EntityManager($writer, $this->em->getConfiguration());
        $id = Uuid::uuid7()->toString();
        $ownerId = Uuid::uuid7()->toString();
        $token = bin2hex(random_bytes(32));
        $owner = UserBuilder::aUser()->withId($ownerId)->withEmail($id.'@example.test')->build();
        $connection = new OneCConnection($id, $ownerId, 'Lock test', hash('sha256', $token));
        $connection->handshake(Uuid::uuid4()->toString(), [], true, new \DateTimeImmutable());
        $em->persist($owner);
        $em->persist($connection);
        $em->flush();

        try {
            $writer->beginTransaction();
            $writer->executeQuery('SELECT id FROM onec_connection WHERE id = ? FOR UPDATE', [$id]);
            $this->connection->executeStatement("SET LOCAL lock_timeout = '250ms'");
            $action = self::getContainer()->get(HandshakeAction::class);
            $body = json_encode(['base_id' => Uuid::uuid4()->toString(), 'config_name' => 'БухгалтерияПредприятия', 'config_version' => '3.0', 'platform_version' => '8.3', 'processor_version' => '1', 'organizations' => []], \JSON_THROW_ON_ERROR);
            try {
                $action($token, $body);
                self::fail('Ожидалась блокировка подключения.');
            } catch (DriverException $exception) {
                self::assertSame('55P03', $exception->getSQLState());
            }
        } finally {
            if ($writer->isTransactionActive()) {
                $writer->rollBack();
            }
            $writer->executeStatement('DELETE FROM "user" WHERE id = ?', [$ownerId]);
            $writer->close();
        }
    }
}
