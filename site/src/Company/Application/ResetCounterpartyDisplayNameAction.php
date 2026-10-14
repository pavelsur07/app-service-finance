<?php

declare(strict_types=1);

namespace App\Company\Application;

use App\Company\Exception\CounterpartyDisplayNameNotApplicableException;
use App\Company\Exception\CounterpartyNotFoundException;
use App\Company\Infrastructure\Persistence\CounterpartyDisplayNameWriter;
use App\Company\Repository\CounterpartyRepository;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * «Вернуть исходное»: снять ручное отображаемое название — снова показывается сырое name,
 * подсказка правил появляется заново. Авто-название так не снимается: следующий запуск пересчёта
 * вернул бы его.
 */
final class ResetCounterpartyDisplayNameAction
{
    public function __construct(
        private readonly CounterpartyRepository $repository,
        private readonly CounterpartyDisplayNameWriter $writer,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(string $companyId, string $counterpartyId): void
    {
        $counterparty = Uuid::isValid($counterpartyId) ? $this->repository->findOneByIdAndCompany($counterpartyId, $companyId) : null;
        if (null === $counterparty) {
            throw new CounterpartyNotFoundException();
        }

        if ($counterparty->isArchived()) {
            throw new CounterpartyDisplayNameNotApplicableException('Контрагент в архиве — верните его из архива, чтобы изменить название.');
        }

        if (!$this->writer->resetManual($counterparty->getId(), $companyId)) {
            // Решаем по свежему состоянию БД: снимок сущности при повторном клике или второй
            // вкладке ещё показывает manual.
            $current = $this->writer->currentState($counterparty->getId(), $companyId);
            if (null === $current || null === $current['display_name_source']) {
                return;
            }

            throw new CounterpartyDisplayNameNotApplicableException('Название задано правилами, а не вручную — вернуть исходное нельзя.');
        }

        $this->logger->info('Counterparty manual display name reset.', [
            'companyId' => $companyId,
            'counterpartyId' => $counterparty->getId(),
        ]);
    }
}
