<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\AiCategorization\AcceptCashCategorySuggestionAction;
use App\Tests\Support\Kernel\IntegrationTestCase;
use Doctrine\DBAL\DriverManager;
use Doctrine\DBAL\Exception\DriverException;
use Ramsey\Uuid\Uuid;

/**
 * Принятие подсказки ИИ ждёт блокировку дерева компании раньше строки подсказки: загрузка дерева
 * держит дерево и переоткрывает подсказки, принятие держит подсказку и проверяет статью (FK) —
 * без общего первого ключа это взаимоблокировка.
 */
final class CashAiAcceptTreeLockTest extends IntegrationTestCase
{
    public function testAcceptWaitsForCompanyTreeLockHeldByAnotherSession(): void
    {
        $companyId = Uuid::uuid7()->toString();
        $other = DriverManager::getConnection($this->em->getConnection()->getParams());
        $other->beginTransaction();
        $other->executeQuery('SELECT pg_advisory_xact_lock(hashtext(?))', [$companyId]);
        $this->em->getConnection()->executeStatement("SET lock_timeout = '200ms'");

        try {
            self::getContainer()->get(AcceptCashCategorySuggestionAction::class)($companyId, Uuid::uuid7()->toString(), Uuid::uuid7()->toString());
            self::fail('Принятие должно ждать блокировку дерева компании.');
        } catch (DriverException $exception) {
            self::assertSame('55P03', $exception->getSQLState(), 'lock timeout');
        } finally {
            $other->rollBack();
            $other->close();
        }
    }
}
