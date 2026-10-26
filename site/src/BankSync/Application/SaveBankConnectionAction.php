<?php

declare(strict_types=1);

namespace App\BankSync\Application;

use App\BankSync\Application\DTO\BankConnectionFormData;
use App\BankSync\Entity\BankConnection;
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
                $data->isActive,
            );
            $this->connections->add($connection);
        } else {
            $connection = $this->connections->findOneByIdAndCompanyId($connectionId, $companyId)
                ?? throw new BankConnectionNotFoundException($connectionId);
            $connection->changeSettings($data->baseUrl, $data->isActive);
            if (null !== $token) {
                $credentials = $this->credentialsCodec->encryptStaticToken($token);
                $connection->replaceCredentials($credentials->storageJson, $credentials->keyVersion);
            }
        }

        $this->entityManager->flush();

        return $connection->getId();
    }
}
