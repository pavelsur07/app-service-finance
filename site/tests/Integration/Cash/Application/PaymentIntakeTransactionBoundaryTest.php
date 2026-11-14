<?php

declare(strict_types=1);

namespace App\Tests\Integration\Cash\Application;

use App\Cash\Application\AddCashTransactionAction;
use App\Cash\Application\DTO\CashTransactionDTO;
use App\Tests\Support\Kernel\IntegrationTestCase;

final class PaymentIntakeTransactionBoundaryTest extends IntegrationTestCase
{
    public function testCallerOwnedCreationRequiresExplicitTransaction(): void
    {
        $action = self::getContainer()->get(AddCashTransactionAction::class);
        // DAMA has a physical transaction, but the application owns none yet.
        $level = $this->connection->getTransactionNestingLevel();
        while ($this->connection->getTransactionNestingLevel() > 0) {
            $this->connection->rollBack();
        }
        try {
            $this->expectException(\LogicException::class);
            $action->createInCurrentTransaction(new CashTransactionDTO());
        } finally {
            for ($i = 0; $i < $level; ++$i) {
                $this->connection->beginTransaction();
            }
        }
    }
}
