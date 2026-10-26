<?php

declare(strict_types=1);

namespace App\BankSync\Application;

use App\BankSync\Application\DTO\BankConnectionFormData;
use App\BankSync\Entity\BankConnection;
use App\BankSync\Enum\BankConnectionStatus;
use App\BankSync\Exception\BankConnectionBusyException;
use App\BankSync\Exception\BankConnectionNotFoundException;
use App\BankSync\Exception\BankTokenRequiredException;
use App\BankSync\Infrastructure\OAuth\BankConnectionTokenLock;
use App\BankSync\Infrastructure\Security\BankCredentialsCodec;
use App\BankSync\Repository\BankConnectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Webmozart\Assert\Assert;

/**
 * Создание или изменение подключения. Токен шифруется до записи; пустой токен при
 * изменении оставляет прежний.
 *
 * Изменение — под блокировкой токенов подключения и на перечитанной строке: иначе
 * воркер, обновлявший OAuth-токены в это время, затёр бы сохранённый пользователем токен.
 */
final readonly class SaveBankConnectionAction
{
    public const TOKEN_LOCK_TIMEOUT_SECONDS = 10;

    public function __construct(
        private BankConnectionRepository $connections,
        private BankCredentialsCodec $credentialsCodec,
        private BankConnectionTokenLock $tokenLock,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return string id подключения
     *
     * @throws BankConnectionNotFoundException подключение не принадлежит компании
     * @throws BankTokenRequiredException новое подключение без токена
     * @throws BankConnectionBusyException токены подключения меняет другой процесс
     */
    public function __invoke(string $companyId, BankConnectionFormData $data, ?string $connectionId = null): string
    {
        Assert::notNull($data->bankCode);
        Assert::notNull($data->baseUrl);
        $token = null !== $data->token && '' !== trim($data->token) ? trim($data->token) : null;

        if (null === $connectionId) {
            if (null === $token) {
                throw new BankTokenRequiredException();
            }
            $credentials = $this->credentialsCodec->encryptStaticToken($token);
            $connection = new BankConnection(
                $companyId,
                $data->bankCode,
                $data->baseUrl,
                $credentials->storageJson,
                $credentials->keyVersion,
            );
            if (!$data->isActive) {
                $connection->disable();
            }
            $this->connections->add($connection);
            $this->entityManager->flush();

            return $connection->getId();
        }

        $connection = $this->connections->findOneByIdAndCompanyId($connectionId, $companyId)
            ?? throw new BankConnectionNotFoundException($connectionId);
        $credentials = null !== $token ? $this->credentialsCodec->encryptStaticToken($token) : null;

        if (!$this->tokenLock->acquire($connectionId, self::TOKEN_LOCK_TIMEOUT_SECONDS)) {
            throw new BankConnectionBusyException($connectionId);
        }
        try {
            $this->entityManager->refresh($connection);
            $connection->changeBaseUrl($data->baseUrl);
            if (null !== $credentials) {
                // Новый токен — новый доступ: подключение снова активно, флажок ниже решает дальше.
                $connection->reauthorize($credentials->storageJson, $credentials->keyVersion);
            }
            $this->applyEnabledFlag($connection, $data->isActive);
            $this->entityManager->flush();
        } finally {
            $this->tokenLock->release($connectionId);
        }

        return $connection->getId();
    }

    /**
     * Флажок «Активно» переключает только `active` ↔ `disabled`; отвергнутое банком или
     * отозванное подключение он не включает — для этого нужен новый токен или вход.
     */
    private function applyEnabledFlag(BankConnection $connection, bool $enabled): void
    {
        $status = $connection->getStatus();
        if ($enabled && BankConnectionStatus::DISABLED === $status) {
            $connection->enable();
        } elseif (!$enabled && \in_array($status, [BankConnectionStatus::ACTIVE, BankConnectionStatus::NEEDS_REAUTH], true)) {
            $connection->disable();
        }
    }
}
