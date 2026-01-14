<?php

namespace App\Cash\MessageHandler\Import;

use App\Cash\Message\Import\BankImportMessage;
use App\Cash\Repository\Bank\BankConnectionRepository;
use App\Cash\Service\Import\Bank\BankImportService;
use App\Cash\Service\Import\Bank\Provider\Alfa\AlfaStatementsProvider;
use App\Repository\CompanyRepository;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final class BankImportHandler
{
    public function __construct(
        private readonly CompanyRepository $companies,
        private readonly BankConnectionRepository $connections,
        private readonly BankImportService $importService,
        private readonly AlfaStatementsProvider $alfaStatementsProvider,
    ) {
    }

    public function __invoke(BankImportMessage $message): void
    {
        $company = $this->companies->find($message->getCompanyId());
        if (null === $company) {
            throw new \InvalidArgumentException('Company not found.');
        }

        $connection = $this->connections->findActiveByCompanyAndBankCode($company, $message->getBankCode());
        if (null === $connection) {
            throw new \InvalidArgumentException('Active bank connection not found.');
        }

        $provider = match ($message->getBankCode()) {
            'alfa' => $this->alfaStatementsProvider,
            default => throw new \InvalidArgumentException('Unsupported bank code: '.$message->getBankCode()),
        };

        $this->importService->importCompany(
            $message->getBankCode(),
            $company,
            $connection,
            $provider
        );
    }
}
