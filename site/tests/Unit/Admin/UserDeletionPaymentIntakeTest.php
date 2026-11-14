<?php

declare(strict_types=1);

namespace App\Tests\Unit\Admin;

use App\Admin\Service\UserDeletionService;
use App\Company\Entity\Company;
use App\Company\Entity\User;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\Platforms\PostgreSQLPlatform;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\TestCase;
use Ramsey\Uuid\Uuid;

final class UserDeletionPaymentIntakeTest extends TestCase
{
    public function testDeletingOwnerRemovesIntakeTextsOnlyForOwnedCompanies(): void
    {
        $user = new User(Uuid::uuid7()->toString());
        $company = new Company(Uuid::uuid7()->toString(), $user);
        $user->addCompany($company);
        $queries = [];
        $connection = $this->createMock(Connection::class);
        $connection->method('getDatabasePlatform')->willReturn(new PostgreSQLPlatform());
        $connection->method('executeStatement')->willReturnCallback(static function (string $sql, array $params = []) use (&$queries): int {
            $queries[] = [$sql, $params];

            return 1;
        });
        $em = $this->createMock(EntityManagerInterface::class);
        $em->method('wrapInTransaction')->willReturnCallback(static fn (callable $callback) => $callback($em));
        $em->expects(self::once())->method('flush');

        (new UserDeletionService($connection, $em))->deleteUser($user);

        foreach (['cash_payment_intake_draft', 'telegram_payment_intake_session'] as $table) {
            $matching = array_values(array_filter($queries, static fn (array $query): bool => str_contains($query[0], 'DELETE FROM "'.$table.'"')));
            self::assertCount(1, $matching, $table);
            self::assertSame(['company_ids' => [$company->getId()]], $matching[0][1]);
        }
    }
}
