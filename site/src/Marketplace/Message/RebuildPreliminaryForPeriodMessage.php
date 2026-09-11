<?php

declare(strict_types=1);

namespace App\Marketplace\Message;

/**
 * Асинхронное сообщение для пересборки предварительного ОПиУ за период.
 * Только scalar — безопасно для Worker/сериализации.
 *
 * `stages` — какие этапы пересобирать. null означает «все», и так ходит ночной
 * пересбор текущего месяца. Исторический период приходит с точным списком:
 * он попал в очередь из-за одного конкретного предварительно закрытого этапа, и
 * трогать соседний нельзя — он может быть открыт человеком ради правок.
 */
final readonly class RebuildPreliminaryForPeriodMessage
{
    public function __construct(
        public string $companyId,
        public string $marketplace,   // MarketplaceType::value
        public int $year,
        public int $month,
        public string $actorUserId,
        /** @var list<string>|null */
        public ?array $stages = null,
    ) {
    }

    /**
     * @return list<string>|null
     */
    public function stages(): ?array
    {
        // Messenger сериализует сообщения нативно, а нативная десериализация не
        // выполняет конструктор: у сообщений, попавших в очередь до деплоя,
        // типизированное свойство осталось бы неинициализированным, и прямое
        // обращение упало бы Error ещё до обработки — то есть старые задания
        // ушли бы в failed через ретраи. Тот же приём у
        // ProcessRawDocumentStepMessage::shouldForceRefresh().
        return isset($this->stages) ? $this->stages : null;
    }
}
