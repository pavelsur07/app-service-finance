<?php

declare(strict_types=1);

namespace App\Settlement\EventSubscriber;

use App\Cash\Domain\Event\CashSettlementSourceChanging;
use App\Finance\Domain\Event\FinanceSettlementSourceChanging;
use App\Settlement\Enum\SettlementSourceType;
use App\Settlement\Exception\SettlementSourceInUseException;
use Doctrine\DBAL\Connection;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

final readonly class SettlementSourceMutationSubscriber implements EventSubscriberInterface
{
    public function __construct(private Connection $db)
    {
    }

    public static function getSubscribedEvents(): array
    {
        return [CashSettlementSourceChanging::class => 'cashChanging', FinanceSettlementSourceChanging::class => 'financeChanging'];
    }

    public function cashChanging(CashSettlementSourceChanging $event): void
    {
        $this->assertFree($event->companyId, $event->sourceId, SettlementSourceType::CASH, $event->materialChange);
    }

    public function financeChanging(FinanceSettlementSourceChanging $event): void
    {
        $this->assertFree($event->companyId, $event->sourceId, SettlementSourceType::FINANCE, $event->materialChange);
    }

    private function assertFree(string $companyId, string $sourceId, SettlementSourceType $type, bool $materialChange): void
    {
        if (!$materialChange) {
            return;
        }
        if (!$this->db->isTransactionActive()) {
            throw new \LogicException('Source mutation guard requires transaction and source lock.');
        }
        if ($this->db->fetchOne('SELECT 1 FROM settlement_source_links WHERE company_id = :company AND source_type = :type AND source_id = :source AND active_document_id IS NOT NULL', ['company' => $companyId, 'type' => $type->value, 'source' => $sourceId])) {
            throw new SettlementSourceInUseException();
        }
    }
}
