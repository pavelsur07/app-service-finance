<?php

declare(strict_types=1);

namespace App\BankSync\Infrastructure\Bank\Alfa;

use App\BankSync\Application\DTO\BankStatementDayDTO;
use App\BankSync\Domain\BankAccess;
use App\BankSync\Domain\BankProviderInterface;
use App\BankSync\Enum\BankCode;
use App\BankSync\Exception\BankApiRequestFailedException;
use Psr\Log\LoggerInterface;

final readonly class AlfaBankProvider implements BankProviderInterface
{
    /** Предохранитель от зацикленного `next`: до 1000 строк на странице — миллион строк за день. */
    public const MAX_PAGES = 1000;

    public function __construct(
        private AlfaApiClient $apiClient,
        private AlfaStatementMapper $mapper,
        private LoggerInterface $logger,
    ) {
    }

    public function code(): BankCode
    {
        return BankCode::ALFA;
    }

    public function listAccounts(BankAccess $access): array
    {
        $list = $this->mapper->mapAccounts($this->apiClient->getAccounts($access));
        if ($list->skippedAccounts > 0) {
            // Номера не пишем: счёт без номера или валюты — формат ответа банка, разбирать по trace_id.
            $this->logger->warning('Alfa accounts: skipped accounts without number or currency', [
                'bank_connection_id' => $access->connectionId,
                'skipped_accounts' => $list->skippedAccounts,
            ]);
        }

        return $list->accounts;
    }

    public function fetchStatementDay(BankAccess $access, string $accountNumber, \DateTimeImmutable $date): BankStatementDayDTO
    {
        $lines = [];
        $skipped = 0;

        for ($pageNumber = 1;; ++$pageNumber) {
            if ($pageNumber > self::MAX_PAGES) {
                throw new BankApiRequestFailedException(BankCode::ALFA->value, null);
            }

            $page = $this->mapper->mapStatementPage(
                $this->apiClient->getTransactions($access, $accountNumber, $date, $pageNumber),
            );
            array_push($lines, ...$page->lines);
            $skipped += $page->skippedLines;

            if (!$page->hasNextPage) {
                break;
            }
        }

        return new BankStatementDayDTO($date, $lines, $skipped);
    }
}
