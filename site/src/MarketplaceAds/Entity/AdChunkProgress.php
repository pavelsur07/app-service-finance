<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Entity;

use Doctrine\ORM\Mapping as ORM;
use Ramsey\Uuid\Uuid;
use Webmozart\Assert\Assert;

/**
 * Факт успешного завершения одного чанка загрузки рекламной статистики
 * для задания {@see AdLoadJob}.
 *
 * Уникальный индекс `(job_id, date_from, date_to)` делает запись
 * идемпотентной на уровне БД: повторный вызов
 * {@see \App\MarketplaceAds\MessageHandler\FetchOzonAdStatisticsHandler}
 * для того же чанка (Messenger retry после частичного успеха) упрётся
 * в uq-нарушение и не приведёт к двойному инкременту счётчиков job'а.
 *
 * Поля неизменяемы после конструктора — прогресс чанка либо есть,
 * либо нет. Корректировка не предполагается: пересчёт выполняется
 * удалением записи и повторной загрузкой чанка.
 */
#[ORM\Entity]
#[ORM\Table(name: 'marketplace_ad_chunk_progress')]
#[ORM\UniqueConstraint(
    name: 'uq_ad_chunk_progress_job_dates',
    columns: ['job_id', 'date_from', 'date_to'],
)]
class AdChunkProgress
{
    #[ORM\Id]
    #[ORM\Column(type: 'guid', unique: true)]
    private string $id;

    #[ORM\Column(type: 'guid')]
    private string $jobId;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateFrom;

    #[ORM\Column(type: 'date_immutable')]
    private \DateTimeImmutable $dateTo;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $completedAt;

    public function __construct(
        string $jobId,
        \DateTimeImmutable $dateFrom,
        \DateTimeImmutable $dateTo,
    ) {
        $this->id = Uuid::uuid7()->toString();
        Assert::uuid($this->id);
        Assert::uuid($jobId);

        // Нормализация до 00:00 — консистентно с AdLoadJob::__construct:
        // без этого записи за «тот же чанк» с разным временем дня попадали бы
        // мимо уникального индекса и ломали идемпотентность.
        $dateFrom = $dateFrom->setTime(0, 0);
        $dateTo = $dateTo->setTime(0, 0);

        if ($dateFrom > $dateTo) {
            throw new \DomainException('dateFrom не может быть позже dateTo.');
        }

        $this->jobId = $jobId;
        $this->dateFrom = $dateFrom;
        $this->dateTo = $dateTo;
        $this->completedAt = new \DateTimeImmutable();
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getJobId(): string
    {
        return $this->jobId;
    }

    public function getDateFrom(): \DateTimeImmutable
    {
        return $this->dateFrom;
    }

    public function getDateTo(): \DateTimeImmutable
    {
        return $this->dateTo;
    }

    public function getCompletedAt(): \DateTimeImmutable
    {
        return $this->completedAt;
    }
}
