<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Action;

use App\MoySklad\Application\Command\UpdateMoySkladConnectionCommand;
use App\MoySklad\Entity\MoySkladConnection;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;

final readonly class UpdateMoySkladConnectionAction
{
    public function __construct(private MoySkladConnectionWriteRepository $repository)
    {
    }

    public function __invoke(UpdateMoySkladConnectionCommand $command): MoySkladConnection
    {
        $connection = $this->repository->findByIdAndCompanyId($command->id, $command->companyId);
        if ($connection === null) {
            throw new \DomainException('Подключение не найдено.');
        }

        if ($this->repository->existsByName($command->companyId, $command->name, $connection->getId())) {
            throw new \DomainException('Подключение с таким именем уже существует.');
        }

        $connection
            ->setName($command->name)
            ->setBaseUrl($command->baseUrl)
            ->setLogin($command->login)
            ->setAccessToken($command->accessToken)
            ->setRefreshToken($command->refreshToken)
            ->setTokenExpiresAt($command->tokenExpiresAt)
            ->setIsActive($command->isActive);

        $this->repository->save($connection);

        return $connection;
    }
}
