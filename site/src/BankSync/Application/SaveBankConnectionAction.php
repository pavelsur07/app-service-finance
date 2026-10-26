<?php

declare(strict_types=1);

namespace App\BankSync\Application;

use App\BankSync\Application\DTO\BankConnectionFormData;
use App\BankSync\Entity\BankConnection;
use App\BankSync\Enum\BankConnectionStatus;
use App\BankSync\Exception\BankConnectionNotFoundException;
use App\BankSync\Exception\BankTokenRequiredException;
use App\BankSync\Infrastructure\Security\BankCredentialsCodec;
use App\BankSync\Repository\BankConnectionRepository;
use Doctrine\ORM\EntityManagerInterface;
use Webmozart\Assert\Assert;

/**
 * Создание или изменение подключения. Токен шифруется до записи; пустой токен при
 * изменении оставляет прежний.
 */
final readonly class SaveBankConnectionAction
{
    public function __construct(
        private BankConnectionRepository $connections,
        private BankCredentialsCodec $credentialsCodec,
        private EntityManagerInterface $entityManager,
    ) {
    }

    /**
     * @return string id подключения
     *
     * @throws BankConnectionNotFoundException подключение не принадлежит компании
     * @throws BankTokenRequiredException новое подключение без токена
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
        } else {
            $connection = $this->connections->findOneByIdAndCompanyId($connectionId, $companyId)
                ?? throw new BankConnectionNotFoundException($connectionId);
            $connection->changeBaseUrl($data->baseUrl);
            if (null !== $token) {
                // Новый токен — новый доступ: подключение снова активно, флажок ниже решает дальше.
                $credentials = $this->credentialsCodec->encryptStaticToken($token);
                $connection->reauthorize($credentials->storageJson, $credentials->keyVersion);
            }
            $this->applyEnabledFlag($connection, $data->isActive);
        }

        $this->entityManager->flush();

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
