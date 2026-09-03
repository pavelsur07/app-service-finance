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

    /**
     * Пачка проблем за ОДИН проход по блокировкам.
     *
     * Поштучный вызов внутри цикла — прямой N+1: каждая проблема брала бы свою
     * блокировку и свой запрос отметок, а часовой прогон заводит их до тысячи
     * за раз и держит при этом блокировки всей пачки. Уникальные строки сырья
     * блокируются одним запросом и в порядке идентификатора — тем же, что
     * задаёт общий порядок блокировок в проекте.
     *
     * @param list<RecordNormalizationIssueCommand> $commands
     */
    public function recordMany(array $commands): void
    {
        if ([] === $commands) {
            return;
        }

        $recorded = [];

        $this->entityManager->wrapInTransaction(function () use ($commands, &$recorded): void {
            $byCompany = [];
            foreach ($commands as $command) {
                $byCompany[$command->companyId][$command->rawRecordId] = true;
            }

            // Блокировка и отметки — по одному запросу на компанию, а не на
            // проблему. Компаний в пачке столько же, сколько кабинетов у
            // прогона, то есть единицы.
            $marks = [];
            foreach ($byCompany as $companyId => $rawRecordIds) {
                foreach ($this->rawRecordRepository->lockManyForUpdate(array_keys($rawRecordIds)) as $rawRecordId) {
                    $marks[$companyId][$rawRecordId] = $this->rawRecordRepository->payloadMarks((string) $companyId, $rawRecordId);
                }
            }

            foreach ($commands as $command) {
                if (!isset($marks[$command->companyId][$command->rawRecordId])) {
                    $this->reportMissingRecord($command);

                    continue;
                }

                $this->reportPayloadState($command, $marks[$command->companyId][$command->rawRecordId]);
                $this->entityManager->persist($this->issue($command));

                $recorded[] = $command;
            }
        });

        foreach ($recorded as $command) {
            $this->reportRecorded($command);
        }
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
        $recorded = false;

        $this->entityManager->wrapInTransaction(function () use ($command, &$recorded): void {
            $record = $this->rawRecordRepository->findOneForUpdate($command->companyId, $command->rawRecordId, refresh: false);

            if (null === $record) {
                $this->reportMissingRecord($command);

                return;
            }

            // Отметки читаются СВЕЖИМ скалярным запросом, а не с сущности.
            //
            // Перечитывать сущность нельзя — вызывающий правит её прямо сейчас,
            // и `HINT_REFRESH` затёр бы незафлашенные изменения, — но и верить
            // её полям нельзя: она могла быть загружена задолго до блокировки,
            // а retention успел закоммитить своё решение. Скалярный запрос
            // отвечает на вопрос «что в базе» и ничего не трогает.
            $this->reportPayloadState($command, $this->rawRecordRepository->payloadMarks($command->companyId, $command->rawRecordId));
            $this->entityManager->persist($this->issue($command));

            $recorded = true;
        });

        if (!$recorded) {
            return;
        }

        $this->reportRecorded($command);
    }

    private function issue(RecordNormalizationIssueCommand $command): NormalizationIssue
    {
        return new NormalizationIssue(
            companyId: $command->companyId,
            rawRecordId: $command->rawRecordId,
            operationGroupId: $command->operationGroupId,
            kind: $command->kind,
            details: $command->details,
        );
    }

    /**
     * Проблема без своей строки сырья не заводится — и дело не только в том,
     * что разбирающему нечего открыть.
     *
     * Удержание сырья ищется по паре `(компания, сырьё)`, ровно по ключу той
     * блокировки, которую мы только что не смогли взять. Строка, которой в
     * компании нет, блокировку не даёт: протокол с retention на такой проблеме
     * не работает вовсе, а её `rawRecordId` при этом попадал бы в чужую
     * компанию — соседний арендатор удерживал бы нагрузку из-за нашей записи.
     *
     * Уровень ERROR: состояние само не лечится и означает дефект вызывающего,
     * а не ожидаемый ход событий.
     */
    private function reportMissingRecord(RecordNormalizationIssueCommand $command): void
    {
        $this->logger->error('Normalization issue was not recorded: the raw record does not exist in this company.', [
            'companyId' => $command->companyId,
            'rawRecordId' => $command->rawRecordId,
            'kind' => $command->kind->value,
        ]);
    }

    /**
     * @param array{prunedAt: ?\DateTimeImmutable, deletedAt: ?\DateTimeImmutable}|null $marks
     */
    private function reportPayloadState(RecordNormalizationIssueCommand $command, ?array $marks): void
    {
        if (null !== ($marks['deletedAt'] ?? null)) {
            // Доказательства уже нет, и оно не вернётся. Retention об этом
            // промолчал не по злому умыслу: проблемы в момент удаления ещё не
            // существовало, и он не мог посчитать её потерянной. Здесь
            // последний и единственный наблюдатель — значит здесь ERROR.
            $this->logger->error('Normalization issue is recorded for a raw record whose payload is already deleted; it will have to be triaged without evidence.', [
                'companyId' => $command->companyId,
                'rawRecordId' => $command->rawRecordId,
                'kind' => $command->kind->value,
            ]);

            return;
        }

        if (null !== ($marks['prunedAt'] ?? null)) {
            // Решение принято, объект пока на месте: у retention ещё есть шанс
            // увидеть проблему и отменить удаление. Это WARNING — состояние
            // поправимое.
            $this->logger->warning('Normalization issue is recorded for a raw record whose payload is marked for pruning.', [
                'companyId' => $command->companyId,
                'rawRecordId' => $command->rawRecordId,
                'kind' => $command->kind->value,
            ]);
        }
    }

    private function reportRecorded(RecordNormalizationIssueCommand $command): void
    {
        $this->logger->warning('Ingestion normalization issue recorded.', [
            'companyId' => $command->companyId,
            'rawRecordId' => $command->rawRecordId,
            'operationGroupId' => $command->operationGroupId,
            'kind' => $command->kind->value,
        ]);
    }
}
