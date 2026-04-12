<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Command;

use App\Company\Facade\CompanyFacade;
use App\Marketplace\Enum\MarketplaceType;
use App\Marketplace\Repository\MarketplaceConnectionRepository;
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
 *   3) отправляем ProcessAdRawDocumentMessage в async-транспорт.
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
        'wb'          => MarketplaceType::WILDBERRIES,
        'wildberries' => MarketplaceType::WILDBERRIES,
        'ozon'        => MarketplaceType::OZON,
    ];

    /**
     * @param iterable<AdPlatformClientInterface> $platformClients
     */
    public function __construct(
        private readonly CompanyFacade $companyFacade,
        private readonly MarketplaceConnectionRepository $connectionRepository,
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
        $companyIds      = $companyIdOption !== null && $companyIdOption !== ''
            ? [(string) $companyIdOption]
            : $this->companyFacade->getAllActiveCompanyIds();

        if ($companyIds === []) {
            $output->writeln('<comment>Нет компаний для загрузки.</comment>');

            return Command::SUCCESS;
        }

        $loaded  = 0;
        $skipped = 0;
        $failed  = 0;

        foreach ($companyIds as $companyId) {
            foreach ($marketplaces as $marketplace) {
                $result = $this->loadForCompanyAndMarketplace($companyId, $marketplace, $reportDate, $output);

                match ($result) {
                    'loaded'  => ++$loaded,
                    'skipped' => ++$skipped,
                    'failed'  => ++$failed,
                };
            }
        }

        $output->writeln(sprintf(
            '<info>Готово. Загружено: %d, пропущено: %d, ошибок: %d.</info>',
            $loaded,
            $skipped,
            $failed,
        ));

        return $failed > 0 ? Command::FAILURE : Command::SUCCESS;
    }

    /**
     * @return 'loaded'|'skipped'|'failed'
     */
    private function loadForCompanyAndMarketplace(
        string $companyId,
        MarketplaceType $marketplace,
        \DateTimeImmutable $reportDate,
        OutputInterface $output,
    ): string {
        $connection = $this->connectionRepository->findByCompanyIdAndMarketplace($companyId, $marketplace);

        if ($connection === null || !$connection->isActive()) {
            $output->writeln(sprintf(
                '<comment>[%s / %s] пропуск: нет активного подключения.</comment>',
                $companyId,
                $marketplace->value,
            ));

            return 'skipped';
        }

        $client = $this->selectClient($marketplace->value);

        if ($client === null) {
            $this->logger->warning('Отсутствует AdPlatformClient для маркетплейса', [
                'companyId'   => $companyId,
                'marketplace' => $marketplace->value,
            ]);

            return 'skipped';
        }

        try {
            $payload = $client->fetchAdStatistics($companyId, $reportDate);
        } catch (\Throwable $e) {
            $this->logger->error(
                'Ошибка загрузки рекламной статистики',
                $e,
                [
                    'companyId'   => $companyId,
                    'marketplace' => $marketplace->value,
                    'reportDate'  => $reportDate->format('Y-m-d'),
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

        if ($existing !== null) {
            $existing->updatePayload($payload);
            $rawDocument = $existing;
        } else {
            $rawDocument = new AdRawDocument(
                companyId:   $companyId,
                marketplace: $marketplace,
                reportDate:  $reportDate,
                rawPayload:  $payload,
            );
            $this->rawDocumentRepository->save($rawDocument);
        }

        $this->entityManager->flush();

        $this->bus->dispatch(new ProcessAdRawDocumentMessage(
            companyId:       $companyId,
            adRawDocumentId: $rawDocument->getId(),
        ));

        $output->writeln(sprintf(
            '<info>[%s / %s] загружено (doc=%s).</info>',
            $companyId,
            $marketplace->value,
            $rawDocument->getId(),
        ));

        return 'loaded';
    }

    private function parseDate(string $value): \DateTimeImmutable
    {
        if ($value === 'yesterday' || $value === '') {
            return new \DateTimeImmutable('yesterday');
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false) {
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

        if ($value === self::MARKETPLACE_ALL) {
            return [MarketplaceType::WILDBERRIES, MarketplaceType::OZON];
        }

        if (!isset(self::MARKETPLACE_ALIASES[$value])) {
            throw new \InvalidArgumentException(sprintf(
                'Неизвестный --marketplace=%s. Допустимо: wb, ozon, all.',
                $value,
            ));
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
