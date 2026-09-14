<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Action;

use App\MoySklad\Application\Command\ManageConnectionCommand;
use App\MoySklad\Entity\MoySkladConnection;
use App\MoySklad\Enum\ConnectionCheckStatus;
use App\MoySklad\Exception\ConnectionOperationException;
use App\MoySklad\Infrastructure\Api\MoySkladClient;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use App\MoySklad\Infrastructure\Security\ConnectionTokenCodec;
use Doctrine\DBAL\Exception\ForeignKeyConstraintViolationException;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\DBAL\LockMode;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\ORM\OptimisticLockException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\RateLimiter\RateLimiterFactory;

final readonly class ManageMoySkladConnectionAction
{
    public function __construct(
        private MoySkladConnectionWriteRepository $repository,
        private EntityManagerInterface $em,
        private MoySkladClient $client,
        private ConnectionTokenCodec $codec,
        #[Autowire(service: 'limiter.moysklad_connection_check')]
        private RateLimiterFactory $limiter,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(#[\SensitiveParameter] ManageConnectionCommand $command): ?MoySkladConnection
    {
        $operation = $command->operation;
        if (!in_array($operation, ['create', 'edit', 'check', 'replace-token', 'disable', 'enable', 'delete'], true)) {
            throw new \LogicException('Unknown connection operation.');
        }
        $connection = null;
        if ('create' !== $operation) {
            $connection = null !== $command->id ? $this->repository->findByIdAndCompanyId($command->id, $command->companyId) : null;
            if (null === $connection) {
                throw new ConnectionOperationException('not_found', 'Подключение не найдено.');
            }
            $this->assertVersion($connection, $command->version);
        }
        if (in_array($operation, ['create', 'edit'], true)) {
            if ('' === trim($command->name) || mb_strlen(trim($command->name)) > 255) {
                throw new ConnectionOperationException('invalid_name', 'Укажите название подключения длиной до 255 символов.');
            }
            if ($this->repository->existsByName($command->companyId, $command->name, $command->id)) {
                throw new ConnectionOperationException('duplicate_name', 'Подключение с таким именем уже существует.');
            }
        }
        if (in_array($operation, ['create', 'check', 'replace-token', 'enable'], true)) {
            $key = $command->companyId.':'.($command->id ?? 'create:'.$command->actorId);
            if (!$this->limiter->create($key)->consume()->isAccepted()) {
                throw new ConnectionOperationException('rate_limited', 'Повторите проверку через 10 секунд.');
            }
            $token = in_array($operation, ['create', 'replace-token'], true) ? trim($command->token) : $this->codec->accessTokenFor($connection);
            if (null === $token || '' === $token) {
                throw new ConnectionOperationException('missing_token', 'Укажите токен доступа.');
            }
            $result = $this->client->check($token);
            // HTTP runs without a database transaction. Reject stale answers before mutation.
            if (null !== $connection) {
                try {
                    $this->em->refresh($connection);
                } catch (\Doctrine\ORM\EntityNotFoundException) {
                    throw new ConnectionOperationException('not_found', 'Подключение удалено. Обновите страницу.');
                }
                $this->assertVersion($connection, $command->version);
            }
            if (ConnectionCheckStatus::CONNECTED !== $result->status) {
                if (null !== $connection && in_array($operation, ['check', 'enable'], true)) {
                    $connection->recordCheck($result->status, new \DateTimeImmutable());
                    $this->save($connection);
                }
                $this->logger->warning('MoySklad connection check rejected', ['companyId' => $command->companyId, 'connectionId' => $command->id, 'reason' => $result->status->value]);
                throw new ConnectionOperationException($result->status->value, $result->status->message());
            }
            if (null === $result->accountId) {
                throw new \LogicException('Successful connection check requires an account.');
            }
            if (null === $connection) {
                $connection = new MoySkladConnection(Uuid::uuid7()->toString(), $command->companyId, trim($command->name), 'https://api.moysklad.ru/api/remap/1.2');
            }
            if (null !== $connection->getAccountId() && $connection->getAccountId() !== $result->accountId) {
                throw new ConnectionOperationException('account_mismatch', 'Токен относится к другому аккаунту. Создайте отдельное подключение.');
            }
            $connection->bindAccount($result->accountId);
            if (in_array($operation, ['create', 'replace-token'], true)) {
                $this->codec->replaceAccessToken($connection, $token);
            }
            $connection->recordCheck($result->status, new \DateTimeImmutable());
            if (in_array($operation, ['create', 'enable'], true)) {
                $connection->setIsActive(true);
            }
        }
        if ('edit' === $operation) {
            $connection->setName($command->name);
        } elseif ('disable' === $operation) {
            $connection->setIsActive(false);
        } elseif ('delete' === $operation) {
            if ($connection->isActive()) {
                throw new ConnectionOperationException('active_connection', 'Сначала отключите подключение.');
            }
        }
        $this->save($connection, 'delete' !== $operation, $command->version);
        $this->logger->info('MoySklad connection changed', ['companyId' => $command->companyId, 'connectionId' => $connection->getId(), 'actorId' => $command->actorId, 'operation' => $operation]);

        return 'delete' === $operation ? null : $connection;
    }

    private function assertVersion(MoySkladConnection $connection, int $version): void
    {
        if ($version < 1 || $connection->getVersion() !== $version) {
            throw new ConnectionOperationException('stale_connection', 'Подключение изменилось. Обновите страницу и повторите действие.');
        }
    }

    private function save(MoySkladConnection $connection, bool $persist = true, int $version = 0): void
    {
        try {
            if (!$persist) {
                // Doctrine DELETE does not enforce the version column: lock and recheck.
                $this->em->wrapInTransaction(function () use ($connection, $version): void {
                    $this->em->refresh($connection, LockMode::PESSIMISTIC_WRITE);
                    $this->assertVersion($connection, $version);
                    if ($connection->isActive()) {
                        throw new ConnectionOperationException('active_connection', 'Сначала отключите подключение.');
                    }
                    $this->em->remove($connection);
                });
            } else {
                $this->em->persist($connection);
                $this->em->flush();
            }
        } catch (UniqueConstraintViolationException) {
            throw new ConnectionOperationException('duplicate_connection', 'Этот аккаунт или название уже используется подключением.');
        } catch (OptimisticLockException) {
            throw new ConnectionOperationException('stale_connection', 'Подключение изменилось. Обновите страницу и повторите действие.');
        } catch (ForeignKeyConstraintViolationException) {
            throw new ConnectionOperationException('connection_in_use', 'Подключение связано с данными и не может быть удалено.');
        }
    }
}
