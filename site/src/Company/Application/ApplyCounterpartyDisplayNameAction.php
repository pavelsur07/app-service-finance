<?php

declare(strict_types=1);

namespace App\Company\Application;

use App\Company\Domain\Service\CounterpartyDisplayNameFormatter;
use App\Company\Domain\Service\CounterpartyDisplayNameSuggestion;
use App\Company\Enum\CounterpartyDisplayNameSource;
use App\Company\Exception\CounterpartyDisplayNameNotApplicableException;
use App\Company\Exception\CounterpartyNotFoundException;
use App\Company\Infrastructure\Persistence\CounterpartyDisplayNameWriter;
use App\Company\Repository\CounterpartyRepository;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;

/**
 * «Применить» предложенное название одного контрагента (docs/plan/ds-counterparties.md,
 * «Подсказка нормализации»). Предложение пересчитывается на сервере из сырого name — значение
 * от клиента не принимается. Записывается как ручное (manual): пересчёт правил его не тронет.
 * Запись — условным UPDATE: параллельная правка не затирается. Повтор с тем же результатом — успех.
 */
final class ApplyCounterpartyDisplayNameAction
{
    public function __construct(
        private readonly CounterpartyRepository $repository,
        private readonly CounterpartyDisplayNameSuggestion $suggestion,
        private readonly CounterpartyDisplayNameFormatter $formatter,
        private readonly CounterpartyDisplayNameWriter $writer,
        private readonly LoggerInterface $logger,
    ) {
    }

    /** @return string применённое название */
    public function __invoke(string $companyId, string $counterpartyId): string
    {
        $counterparty = Uuid::isValid($counterpartyId) ? $this->repository->findOneByIdAndCompany($counterpartyId, $companyId) : null;
        if (null === $counterparty) {
            throw new CounterpartyNotFoundException();
        }

        if ($counterparty->isArchived()) {
            throw new CounterpartyDisplayNameNotApplicableException('Контрагент в архиве — верните его из архива, чтобы изменить название.');
        }

        $suggested = $this->suggestion->for($counterparty);

        if (null === $suggested) {
            if (CounterpartyDisplayNameSource::MANUAL === $counterparty->getDisplayNameSource()) {
                // Повторный клик по уже применённому — не ошибка; своё ручное название — не трогаем.
                $current = $counterparty->getDisplayName();
                if (null !== $current && $current === $this->formatter->format($counterparty->getName())) {
                    return $current;
                }

                throw new CounterpartyDisplayNameNotApplicableException('Название уже задано вручную — предложение не применяется.');
            }

            throw new CounterpartyDisplayNameNotApplicableException('Предложение больше не актуально.');
        }

        if (!$this->writer->writeManualIfUnchanged($counterparty->getId(), $companyId, $counterparty->getName(), $suggested)) {
            throw new CounterpartyDisplayNameNotApplicableException('Контрагент изменён — обновите страницу и проверьте предложение.');
        }

        $this->logger->info('Counterparty display name applied from suggestion.', [
            'companyId' => $companyId,
            'counterpartyId' => $counterparty->getId(),
        ]);

        return $suggested;
    }
}
