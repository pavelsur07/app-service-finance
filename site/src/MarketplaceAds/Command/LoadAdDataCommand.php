<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Command;

use App\Company\Facade\CompanyFacade;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Facade\MarketplaceFacade;
use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Infrastructure\Api\Contract\AdPlatformClientInterface;
use App\MarketplaceAds\Message\ProcessAdRawDocumentMessage;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use App\Shared\Service\AppLogger;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Cron-команда: загружает рекламные отчёты маркетплейсов за дату (по умолчанию — за вчера)
 * и ставит задачи на их обработку через Messenger.
 *
 * Для каждой пары (company × marketplace):
 *   1) берём JSON через AdPlatformClientInterface;
 *   2) upsert-им AdRawDocument (уникальный ключ company + marketplace + report_date);
 *   3) копим подготовленный документ, а единый flush делаем в конце прохода по всем парам,
 *      чтобы на N компаниях × M площадках не получить N*M отдельных транзакций.
 *   4) только после успешного flush отправляем ProcessAdRawDocumentMessage в async-транспорт —
 *      иначе воркер заберёт сообщение до того, как документ появится в БД.
 *
 * Ошибки API/отсутствия клиента/подключения логируются, но не прерывают цикл —
 * cron должен попробовать обработать всё, что может.
 */
#[AsCommand(
    name: 'marketplace-ads:load',
    description: 'Загружает рекламную статистику маркетплейсов за дату и ставит обработку в очередь.',
)]
final class LoadAdDataCommand extends Command
{
    private const MARKETPLACE_ALL = 'all';

    /** @var array<string, MarketplaceType> псевдонимы CLI → MarketplaceType */
    private const MARKETPLACE_ALIASES = [
        'wb' => MarketplaceType::WILDBERRIES,
        'wildberries' => MarketplaceType::WILDBERRIES,
        'ozon' => MarketplaceType::OZON,
    ];

    /**
     * @param iterable<AdPlatformClientInterface> $platformClients
     */
    public function __construct(
        private readonly CompanyFacade $companyFacade,
        private readonly MarketplaceFacade $marketplaceFacade,
        private readonly AdRawDocumentRepository $rawDocumentRepository,
        private readonly iterable $platformClients,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
        private readonly AppLogger $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption(
                'date',
                null,
                InputOption::VALUE_OPTIONAL,
                'Дата отчёта (YYYY-MM-DD). По умолчанию — вчера.',
                'yesterday',
            )
            ->addOption(
                'marketplace',
                null,
                InputOption::VALUE_OPTIONAL,
                'Маркетплейс: wb, ozon, all.',
                self::MARKETPLACE_ALL,
            )
            ->addOption(
                'company-id',
                null,
                InputOption::VALUE_OPTIONAL,
                'UUID компании. Если не указан — берутся все активные компании.',
                null,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $reportDate = $this->parseDate((string) $input->getOption('date'));
        } catch (\Throwable) {
            $output->writeln('<error>Неверный формат --date. Ожидается YYYY-MM-DD или "yesterday".</error>');

            return Command::FAILURE;
        }

        try {
            $marketplaces = $this->resolveMarketplaces((string) $input->getOption('marketplace'));
        } catch (\InvalidArgumentException $e) {
            $output->writeln(sprintf('<error>%s</error>', $e->getMessage()));

            return Command::FAILURE;
        }

        $companyIdOption = $input->getOption('company-id');
        if (null !== $companyIdOption && '' !== $companyIdOption) {
            $companyId = (string) $companyIdOption;
            // Валидируем наличие компании на границе CLI → иначе при опечатке UUID
            // команда молча напишет «нет активного подключения» по каждому маркетплейсу,
            // и оператор не поймёт, что ошибка в аргументе.
            if (null === $this->companyFacade->findById($companyId)) {
                $output->writeln(sprintf(
                    '<error>Компания не найдена: --company-id=%s.</error>',
                    $companyId,
                ));

                return Command::FAILURE;
            }
            $companyIds = [$companyId];
        } else {
            $companyIds = $this->companyFacade->getAllActiveCompanyIds();
        }

        if ([] === $companyIds) {
            $output->writeln('<comment>Нет компаний для загрузки.</comment>');

            return Command::SUCCESS;
        }

        /** @var list<AdRawDocument> $preparedDocuments */
        $preparedDocuments = [];
        $skipped = 0;
        $failed = 0;

        foreach ($companyIds as $companyId) {
            foreach ($marketplaces as $marketplace) {
                $result = $this->prepareForCompanyAndMarketplace($companyId, $marketplace, $reportDate, $output);

                if ($result instanceof AdRawDocument) {
                    $preparedDocuments[] = $result;
                } elseif ('failed' === $result) {
                    ++$failed;
                } else {
                    ++$skipped;
                }
            }
        }

        // Один flush на весь прогон — N*M подготовленных документов фиксируются
        // в единой транзакции. Если flush упадёт, ни одно сообщение не уйдёт
        // в очередь, и следующий cron повторит цикл чисто.
        if ([] !== $preparedDocuments) {
            $this->entityManager->flush();
        }

        $loaded = 0;
        foreach ($preparedDocuments as $document) {
            $this->bus->dispatch(new ProcessAdRawDocumentMessage(
                companyId: $document->getCompanyId(),
                adRawDocumentId: $document->getId(),
            ));
            ++$loaded;
        }

        $output->writeln(sprintf(
            '<info>Готово. Загружено: %d, пропущено: %d, ошибок: %d.</info>',
            $loaded,
            $skipped,
            $failed,
        ));

        // Частичный провал (есть и успехи, и ошибки) не поднимаем до FAILURE —
        // cron-мониторинг не должен алертить, если 1 компания из 100 провалилась.
        // FAILURE возвращаем только когда всё упало и нечего было загружать.
        if ($failed > 0 && 0 === $loaded) {
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Готовит (persist/updatePayload) AdRawDocument без flush.
     *
     * @return AdRawDocument|'skipped'|'failed'
     */
    private function prepareForCompanyAndMarketplace(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $reportDate,
        OutputInterface $output,
    ): AdRawDocument|string {
        // Клиент выбирается первым, потому что он определяет тип подключения
        // для credentials: WB — Seller API (один токен на всё), Ozon — отдельный
        // Performance API (OAuth). Без клиента знать, какой тип искать, невозможно.
        $client = $this->selectClient($marketplace->value);

        if (null === $client) {
            $this->logger->warning('Отсутствует AdPlatformClient для маркетплейса', [
                'companyId' => $companyId,
                'marketplace' => $marketplace->value,
            ]);

            return 'skipped';
        }

        $connectionType = $client->getRequiredConnectionType();
        $credentials = $this->marketplaceFacade->getConnectionCredentials(
            $companyId,
            $marketplace,
            $connectionType,
        );

        if (null === $credentials) {
            // getConnectionCredentials возвращает только активные подключения,
            // так что null = «нет подключения нужного типа или оно отключено».
            $this->logger->info('Нет активного подключения для загрузки рекламы', [
                'companyId' => $companyId,
                'marketplace' => $marketplace->value,
                'connectionType' => $connectionType->value,
            ]);
            $output->writeln(sprintf(
                '<comment>[%s / %s] пропуск: нет активного подключения (%s).</comment>',
                $companyId,
                $marketplace->value,
                $connectionType->value,
            ));

            return 'skipped';
        }

        try {
            $payload = $client->fetchAdStatistics($companyId, $reportDate);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Ошибка загрузки рекламной статистики',
                $e,
                [
                    'companyId' => $companyId,
                    'marketplace' => $marketplace->value,
                    'reportDate' => $reportDate->format('Y-m-d'),
                ],
            );
            $output->writeln(sprintf(
                '<error>[%s / %s] ошибка API: %s</error>',
                $companyId,
                $marketplace->value,
                $e->getMessage(),
            ));

            return 'failed';
        }

        $existing = $this->rawDocumentRepository->findByMarketplaceAndDate(
            $companyId,
            $marketplace->value,
            $reportDate,
        );

        if (null !== $existing) {
            $existing->updatePayload($payload);
            $rawDocument = $existing;
        } else {
            $rawDocument = new AdRawDocument(
                companyId: $companyId,
                marketplace: $marketplace,
                reportDate: $reportDate,
                rawPayload: $payload,
            );
            $this->rawDocumentRepository->save($rawDocument);
        }

        $output->writeln(sprintf(
            '<info>[%s / %s] подготовлен (doc=%s).</info>',
            $companyId,
            $marketplace->value,
            $rawDocument->getId(),
        ));

        return $rawDocument;
    }

    private function parseDate(string $value): \DateTimeImmutable
    {
        if ('yesterday' === $value || '' === $value) {
            return new \DateTimeImmutable('yesterday');
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        // createFromFormat нормализует несуществующие даты (например, 2026-02-31 → 2026-03-03)
        // и возвращает DateTimeImmutable, а не false. Roundtrip-проверка отсекает такие
        // случаи: валидная дата должна сериализоваться обратно в исходную строку.
        if (false === $date || $date->format('Y-m-d') !== $value) {
            throw new \InvalidArgumentException(sprintf('Invalid date: %s', $value));
        }

        return $date;
    }

    /**
     * @return list<MarketplaceType>
     */
    private function resolveMarketplaces(string $value): array
    {
        $value = strtolower($value);

        if (self::MARKETPLACE_ALL === $value) {
            return [MarketplaceType::WILDBERRIES, MarketplaceType::OZON];
        }

        if (!isset(self::MARKETPLACE_ALIASES[$value])) {
            throw new \InvalidArgumentException(sprintf('Неизвестный --marketplace=%s. Допустимо: wb, ozon, all.', $value));
        }

        return [self::MARKETPLACE_ALIASES[$value]];
    }

    private function selectClient(string $marketplace): ?AdPlatformClientInterface
    {
        foreach ($this->platformClients as $client) {
            if ($client->supports($marketplace)) {
                return $client;
            }
        }

        return null;
    }
}
