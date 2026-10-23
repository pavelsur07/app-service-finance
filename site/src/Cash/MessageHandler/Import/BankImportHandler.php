<?php

declare(strict_types=1);

namespace App\Cash\MessageHandler\Import;

use App\Cash\Exception\BankApiRequestFailedException;
use App\Cash\Exception\BankApiUnavailableException;
use App\Cash\Message\Import\BankImportMessage;
use App\Cash\Repository\Bank\BankConnectionRepository;
use App\Cash\Service\Import\Bank\BankImportService;
use App\Cash\Service\Import\Bank\Provider\Alfa\AlfaStatementsProvider;
use App\Company\Facade\CompanyFacade;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException;

#[AsMessageHandler]
final class BankImportHandler
{
    public function __construct(
        private readonly CompanyFacade $companyFacade,
        private readonly BankConnectionRepository $connections,
        private readonly BankImportService $importService,
        private readonly AlfaStatementsProvider $alfaStatementsProvider,
        private readonly LoggerInterface $logger,
        private readonly EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(BankImportMessage $message): void
    {
        $context = [
            'import_id' => $message->getImportId(),
            'company_id' => $message->getCompanyId(),
            'bank_code' => $message->getBankCode(),
        ];
        $this->logger->info('Bank import started', $context);

        $company = $this->companyFacade->findById($message->getCompanyId())
            ?? throw $this->unrecoverable('Bank import: company not found', $context);

        $connection = $this->connections->findActiveByCompanyAndBankCode($company, $message->getBankCode())
            ?? throw $this->unrecoverable('Bank import: active bank connection not found', $context);

        $provider = match ($message->getBankCode()) {
            'alfa' => $this->alfaStatementsProvider,
            default => throw $this->unrecoverable('Bank import: unsupported bank code', $context),
        };

        try {
            $this->importService->importCompany(
                $message->getBankCode(),
                $company,
                $connection,
                $provider,
                fn () => $this->entityManager->flush(),
            );
        } catch (BankApiUnavailableException $exception) {
            // Обычное исключение, а не RecoverableMessageHandlingException:
            // Symfony ретраит Recoverable бесконечно, в обход max_retries.
            // Так действует retry_strategy транспорта, а после неё — failed.
            $this->logger->warning('Bank import: bank API temporarily unavailable', $context + [
                'status_code' => $exception->getStatusCode(),
            ]);

            throw $exception;
        } catch (BankApiRequestFailedException $exception) {
            $this->logger->error('Bank import: bank API rejected the request', $context + [
                'status_code' => $exception->getStatusCode(),
            ]);

            throw new UnrecoverableMessageHandlingException($exception->getMessage(), 0, $exception);
        }

        $this->logger->info('Bank import finished', $context);
    }

    /**
     * @param array<string, string> $context
     */
    private function unrecoverable(string $reason, array $context): UnrecoverableMessageHandlingException
    {
        $this->logger->error($reason, $context);

        return new UnrecoverableMessageHandlingException($reason);
    }
}
