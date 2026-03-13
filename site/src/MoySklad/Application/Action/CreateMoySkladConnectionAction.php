<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Action;

use App\MoySklad\Application\Command\CreateMoySkladConnectionCommand;
use App\MoySklad\Entity\MoySkladConnection;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;
use Ramsey\Uuid\Uuid;

final readonly class CreateMoySkladConnectionAction
{
    public function __construct(private MoySkladConnectionWriteRepository $repository)
    {
    }

    public function __invoke(CreateMoySkladConnectionCommand $command): MoySkladConnection
    {
        if ($this->repository->existsByName($command->companyId, $command->name)) {
            throw new \DomainException('Подключение с таким именем уже существует.');
        }

        $connection = new MoySkladConnection(
            Uuid::uuid4()->toString(),
            $command->companyId,
            $command->name,
            $command->baseUrl,
        );

        $connection
            ->setLogin($command->login)
            ->setAccessToken($command->accessToken)
            ->setRefreshToken($command->refreshToken)
            ->setTokenExpiresAt($command->tokenExpiresAt)
            ->setIsActive($command->isActive);

        $this->repository->save($connection);

        return $connection;
    }
}
