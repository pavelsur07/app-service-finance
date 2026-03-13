<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Action;

use App\MoySklad\Application\Command\DeleteMoySkladConnectionCommand;
use App\MoySklad\Infrastructure\Repository\MoySkladConnectionWriteRepository;

final readonly class DeleteMoySkladConnectionAction
{
    public function __construct(private MoySkladConnectionWriteRepository $repository)
    {
    }

    public function __invoke(DeleteMoySkladConnectionCommand $command): void
    {
        $connection = $this->repository->findByIdAndCompanyId($command->id, $command->companyId);
        if ($connection === null) {
            throw new \DomainException('Подключение не найдено.');
        }

        $this->repository->remove($connection);
    }
}
