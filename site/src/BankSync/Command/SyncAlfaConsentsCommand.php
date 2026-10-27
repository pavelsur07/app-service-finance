<?php

declare(strict_types=1);

namespace App\BankSync\Command;

use App\BankSync\Domain\Consent\BankConsent;
use App\BankSync\Domain\Consent\ConsentExpiryPolicy;
use App\BankSync\Entity\BankConnection;
use App\BankSync\Enum\BankCode;
use App\BankSync\Enum\BankConnectionStatus;
use App\BankSync\Enum\ConsentState;
use App\BankSync\Exception\BankApiRequestFailedException;
use App\BankSync\Exception\BankApiUnavailableException;
use App\BankSync\Exception\BankAuthorizationNotConfiguredException;
use App\BankSync\Exception\BankChannelNotConfiguredException;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaConsentsClient;
use App\BankSync\Infrastructure\Bank\Alfa\AlfaIdConfig;
use App\BankSync\Infrastructure\OAuth\BankConnectionTokenLock;
use App\BankSync\Repository\BankConnectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\EntityNotFoundException;
use Psr\Log\LoggerInterface;
use Symfony\Component\Clock\ClockInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Ночная сверка сроков согласий Alfa ID: по каждой настроенной среде, где есть подключения
 * с известным пользователем банка, — все согласия приложения одним списком (HTTP до записи
 * в БД), затем по каждому подключению `ConsentExpiryPolicy`:
 * - действует — срок в `consent_expires_at`; осталось 30 дней и меньше — в агрегированный
 *   `warning` (клиент видит предупреждение в списке подключений);
 * - все согласия неактивны или истекли — `revoked` (`consent_expired`) под блокировкой
 *   токенов подключения на перечитанной строке;
 * - банк о пользователе не сообщил — ничего не меняем, счётчик «не найдено».
 *
 * Отказ в токене приложения или ненастроенный канал — `error`, exit 1; банк недоступен —
 * `warning`, exit 1, данные не тронуты. В логи и вывод — ID подключений и счётчики, без
 * `sub`, хэшей организаций и ФИО. Действия при срабатывании — `docs/workflow/health-gates.md`.
 */
#[AsCommand(name: 'bank-sync:alfa:consents-sync', description: 'Сверить сроки согласий Alfa ID и отключить подключения с истёкшим согласием')]
final class SyncAlfaConsentsCommand extends Command
{
    public const STATUS_REASON = 'consent_expired';
    private const TOKEN_LOCK_TIMEOUT_SECONDS = 10;
    private const FLUSH_EVERY = 100;

    public function __construct(
        private readonly AlfaIdConfig $config,
        private readonly AlfaConsentsClient $consentsClient,
        private readonly BankConnectionRepository $connections,
        private readonly ConsentExpiryPolicy $policy,
        private readonly BankConnectionTokenLock $tokenLock,
        private readonly EntityManagerInterface $entityManager,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $configured = $this->config->configuredBaseUrls();
        if ([] === $configured) {
            $output->writeln('Alfa ID is not configured in any environment, nothing to check.');
            $this->logger->info('Alfa consents sync skipped: Alfa ID is not configured');

            return Command::SUCCESS;
        }

        $result = ['checked' => 0, 'updated' => 0, 'expiring' => [], 'revoked' => [], 'not_found' => [], 'busy' => []];
        $failed = [];
        $unavailable = [];
        foreach ($configured as $baseUrl) {
            $connections = $this->connections->findWithExternalSubjectByBankAndBaseUrl(BankCode::ALFA, $baseUrl);
            if ([] === $connections) {
                $output->writeln(sprintf('%s: no connections to check', $baseUrl));
                continue;
            }

            try {
                $consents = $this->consentsClient->fetchAll($this->config->environment($baseUrl));
            } catch (BankApiUnavailableException $exception) {
                $unavailable[$baseUrl] = $exception->getStatusCode() ?? 'transport';
                $output->writeln(sprintf('<error>%s: bank is temporarily unavailable, nothing changed</error>', $baseUrl));
                continue;
            } catch (BankApiRequestFailedException|BankAuthorizationNotConfiguredException|BankChannelNotConfiguredException $exception) {
                $failed[$baseUrl] = $exception->getMessage();
                $output->writeln(sprintf('<error>%s: %s</error>', $baseUrl, $exception->getMessage()));
                continue;
            }

            $this->apply($connections, $this->bySubject($consents), $result);
            $output->writeln(sprintf('%s: %d connection(s), %d consent(s) from the bank', $baseUrl, \count($connections), \count($consents)));
        }

        $summary = [
            'checked' => $result['checked'],
            'updated' => $result['updated'],
            'expiring_within_30_days' => \count($result['expiring']),
            'revoked' => \count($result['revoked']),
            'not_found' => \count($result['not_found']),
            'busy' => \count($result['busy']),
        ];
        $output->writeln(sprintf(
            'Checked %d connection(s): %d date(s) updated, %d expiring within %d days, %d revoked, %d not found in the bank, %d busy.',
            $summary['checked'],
            $summary['updated'],
            $summary['expiring_within_30_days'],
            ConsentExpiryPolicy::WARNING_DAYS,
            $summary['revoked'],
            $summary['not_found'],
            $summary['busy'],
        ));

        $details = [
            'expiring_connection_ids' => $result['expiring'],
            'revoked_connection_ids' => $result['revoked'],
            'not_found_connection_ids' => $result['not_found'],
            'busy_connection_ids' => $result['busy'],
        ];
        if ([] !== $failed) {
            // Текст постоянный: одно событие в GlitchTip, детали — в контексте.
            $this->logger->error('Alfa consents sync failed: application credentials or channel rejected', $summary + $details + ['environments' => $failed]);

            return Command::FAILURE;
        }
        if ([] !== $unavailable) {
            $this->logger->warning('Alfa consents sync: bank temporarily unavailable', $summary + $details + ['environments' => $unavailable]);

            return Command::FAILURE;
        }
        if ([] !== $result['expiring'] || [] !== $result['not_found'] || [] !== $result['busy'] || [] !== $result['revoked']) {
            $this->logger->warning('Alfa consents sync: consents expiring, expired or not found', $summary + $details);
        } else {
            $this->logger->info('Alfa consents sync finished', $summary);
        }

        return Command::SUCCESS;
    }

    /**
     * @param list<BankConnection>                     $connections
     * @param array<string, list<BankConsent>>         $bySubject
     * @param array{checked: int, updated: int, expiring: list<string>, revoked: list<string>, not_found: list<string>, busy: list<string>} $result
     */
    private function apply(array $connections, array $bySubject, array &$result): void
    {
        $today = $this->clock->now();
        foreach ($connections as $index => $connection) {
            ++$result['checked'];
            $decision = $this->policy->decide($bySubject[(string) $connection->getExternalSubject()] ?? [], $today);

            match ($decision->state) {
                ConsentState::ACTIVE => $this->recordExpiry($connection, $decision->expiresOn, $decision->expiresSoon, $result),
                ConsentState::EXPIRED => $this->revoke($connection, $result),
                ConsentState::NOT_FOUND => $result['not_found'][] = $connection->getId(),
            };

            if (0 === ($index + 1) % self::FLUSH_EVERY) {
                $this->entityManager->flush();
            }
        }
        $this->entityManager->flush();
    }

    /**
     * @param array{checked: int, updated: int, expiring: list<string>, revoked: list<string>, not_found: list<string>, busy: list<string>} $result
     */
    private function recordExpiry(BankConnection $connection, ?\DateTimeImmutable $expiresOn, bool $expiresSoon, array &$result): void
    {
        if (null === $expiresOn) {
            return;
        }
        $before = $connection->getConsentExpiresAt();
        $connection->updateConsentExpiresAt($expiresOn);
        if ($before != $connection->getConsentExpiresAt()) {
            ++$result['updated'];
        }
        if ($expiresSoon) {
            $result['expiring'][] = $connection->getId();
        }
    }

    /**
     * Статус — под блокировкой токенов на перечитанной строке (правило Stage 3): вход
     * клиента в эту минуту даст новое согласие, его не гасим.
     *
     * @param array{checked: int, updated: int, expiring: list<string>, revoked: list<string>, not_found: list<string>, busy: list<string>} $result
     */
    private function revoke(BankConnection $connection, array &$result): void
    {
        $usedCredentials = $connection->getCredentialsEncrypted();
        if (!$this->tokenLock->acquire($connection->getId(), self::TOKEN_LOCK_TIMEOUT_SECONDS)) {
            $result['busy'][] = $connection->getId();

            return;
        }
        try {
            try {
                $this->entityManager->refresh($connection);
            } catch (EntityNotFoundException) {
                return;
            }
            if (BankConnectionStatus::REVOKED === $connection->getStatus() || $connection->getCredentialsEncrypted() !== $usedCredentials) {
                return;
            }
            $connection->markRevoked(self::STATUS_REASON);
            $this->entityManager->flush();
            $result['revoked'][] = $connection->getId();
        } finally {
            $this->tokenLock->release($connection->getId());
        }
    }

    /**
     * @param list<BankConsent> $consents
     *
     * @return array<string, list<BankConsent>>
     */
    private function bySubject(array $consents): array
    {
        $bySubject = [];
        foreach ($consents as $consent) {
            $bySubject[$consent->subject][] = $consent;
        }

        return $bySubject;
    }
}
