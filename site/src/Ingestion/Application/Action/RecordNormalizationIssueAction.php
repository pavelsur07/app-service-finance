<?php

declare(strict_types=1);

namespace App\Ingestion\Application\Action;

use App\Ingestion\Application\Command\RecordNormalizationIssueCommand;
use App\Ingestion\Entity\NormalizationIssue;
use App\Ingestion\Repository\IngestRawRecordRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;

final readonly class RecordNormalizationIssueAction
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private IngestRawRecordRepository $rawRecordRepository,
        private LoggerInterface $logger,
    ) {
    }

    public function __invoke(RecordNormalizationIssueCommand $command): void
    {
        // Строка сырья блокируется ПЕРЕД созданием проблемы — общий протокол
        // с retention.
        //
        // Retention не удаляет нагрузку, на которую ссылается неразобранная
        // проблема. Но «проблемы нет» — это отсутствие строки, а его никакая
        // блокировка сырья сама по себе не запрещает: конкурент мог вставить
        // проблему сразу после проверки. Общая блокировка делает две операции
        // последовательными: либо проблема успевает до решения об очистке и
        // удерживает нагрузку, либо очистка уже идёт — и тогда проблема честно
        // фиксирует, что доказательства не будет.
        //
        // Перечитывание здесь ВЫКЛЮЧЕНО: вызывающий (нормализация) правит ту
        // же запись, и `HINT_REFRESH` затёр бы её незафлашенные изменения —
        // разбор терял бы отметку о завершении. Сериализацию даёт сама
        // блокировка, а не перечитывание.
        //
        // Блокировка требует транзакции. Вызывающие приходят и из транзакции
        // (нормализация), и без неё (обслуживающие команды), поэтому
        // wrapInTransaction: вложенный вызов станет savepoint'ом.
        $this->entityManager->wrapInTransaction(function () use ($command): void {
            $record = $this->rawRecordRepository->findOneForUpdate($command->companyId, $command->rawRecordId, refresh: false);

            if (null !== $record && null !== $record->getPayloadPrunedAt()) {
                // Честнее сказать сразу, чем оставить разбирающего гадать,
                // почему сырьё не читается.
                $this->logger->warning('Normalization issue is recorded for a raw record whose payload was already pruned.', [
                    'companyId' => $command->companyId,
                    'rawRecordId' => $command->rawRecordId,
                    'kind' => $command->kind->value,
                ]);
            }

            $this->entityManager->persist(new NormalizationIssue(
                companyId: $command->companyId,
                rawRecordId: $command->rawRecordId,
                operationGroupId: $command->operationGroupId,
                kind: $command->kind,
                details: $command->details,
            ));
        });

        $this->logger->warning('Ingestion normalization issue recorded.', [
            'companyId' => $command->companyId,
            'rawRecordId' => $command->rawRecordId,
            'operationGroupId' => $command->operationGroupId,
            'kind' => $command->kind->value,
        ]);
    }
}
