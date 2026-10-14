<?php

declare(strict_types=1);

namespace App\Company\Application;

use App\Company\Application\DTO\CounterpartyDisplayNameRefreshResult;
use App\Company\Domain\Service\CounterpartyDisplayNameFormatter;
use App\Company\Repository\CounterpartyRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Пересчёт отображаемого названия контрагентов правилами (docs/plan/counterparty-display-name.md).
 *
 * Сырое name и updatedAt не меняются, импорт не участвует. Обрабатываются только строки без
 * авто-названия текущей версии правил; ручные — никогда. Идемпотентно: повторный прогон после
 * записи ничего не находит. Порциями с flush и clear — память не растёт с размером справочника.
 */
final class RefreshCounterpartyDisplayNamesAction
{
    private const BATCH_SIZE = 200;
    private const MAX_LENGTH = 255;

    public function __construct(
        private readonly CounterpartyRepository $repository,
        private readonly CounterpartyDisplayNameFormatter $formatter,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
    }

    public function __invoke(bool $dryRun, int $sampleLimit = 50): CounterpartyDisplayNameRefreshResult
    {
        $version = CounterpartyDisplayNameFormatter::RULES_VERSION;
        $result = new CounterpartyDisplayNameRefreshResult();

        $this->logger->info('Counterparty display name refresh started.', ['dryRun' => $dryRun, 'rulesVersion' => $version]);

        $afterId = null;
        do {
            $batch = $this->repository->findDisplayNameBacklog($version, $afterId, self::BATCH_SIZE);

            foreach ($batch as $counterparty) {
                ++$result->processed;
                $afterId = $counterparty->getId();

                $after = $this->formatter->format($counterparty->getName());
                // Результат длиннее колонки (ОПФ и «ёлочки» к названию в 255 символов) — не уверены.
                if (null !== $after && mb_strlen($after) > self::MAX_LENGTH) {
                    $after = null;
                }

                $before = $counterparty->getDisplayName();

                if (null === $after) {
                    ++$result->unrecognised;
                    if (\count($result->unrecognisedSamples) < $sampleLimit) {
                        $result->unrecognisedSamples[] = ['id' => $counterparty->getId(), 'companyId' => (string) $counterparty->getCompany()->getId(), 'raw' => $counterparty->getName()];
                    }
                }

                if ($after === $before) {
                    ++$result->unchanged;
                } else {
                    ++$result->changed;
                    if (null !== $after && \count($result->changedSamples) < $sampleLimit) {
                        $result->changedSamples[] = ['id' => $counterparty->getId(), 'companyId' => (string) $counterparty->getCompany()->getId(), 'raw' => $counterparty->getName(), 'before' => $before, 'after' => $after];
                    }
                }

                if (!$dryRun) {
                    $counterparty->applyAutoDisplayName($after, $version);
                }
            }

            if (!$dryRun) {
                $this->em->flush();
            }
            // В ORM 3 clear() отцепляет всё — здесь других управляемых сущностей нет.
            $this->em->clear();
        } while (self::BATCH_SIZE === \count($batch));

        $this->logger->info('Counterparty display name refresh finished.', [
            'dryRun' => $dryRun,
            'rulesVersion' => $version,
            'processed' => $result->processed,
            'changed' => $result->changed,
            'unchanged' => $result->unchanged,
            'unrecognised' => $result->unrecognised,
        ]);

        return $result;
    }
}
