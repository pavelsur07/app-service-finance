<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Command;

use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\MarketplaceAds\Message\ProcessAdRawDocumentMessage;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Переобработка AdRawDocument: находит raw-документы по фильтрам
 * (--date, --marketplace, --company-id — все опциональные, любую комбинацию),
 * сбрасывает их статус в DRAFT и ставит ProcessAdRawDocumentMessage в очередь.
 *
 * Используется, когда алгоритм обработки/распределения затрат изменился
 * и нужно перегнать исторические данные без повторного обращения к API маркетплейса.
 */
#[AsCommand(
    name: 'marketplace-ads:reprocess',
    description: 'Переобрабатывает уже загруженные AdRawDocument по фильтрам (дата, площадка, компания).',
)]
final class ReprocessAdDataCommand extends Command
{
    /** @var array<string, MarketplaceType> псевдонимы CLI → MarketplaceType */
    private const MARKETPLACE_ALIASES = [
        'wb'          => MarketplaceType::WILDBERRIES,
        'wildberries' => MarketplaceType::WILDBERRIES,
        'ozon'        => MarketplaceType::OZON,
    ];

    public function __construct(
        private readonly AdRawDocumentRepository $rawDocumentRepository,
        private readonly EntityManagerInterface $entityManager,
        private readonly MessageBusInterface $bus,
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
                'Дата отчёта (YYYY-MM-DD). Если не указана — любая дата.',
                null,
            )
            ->addOption(
                'marketplace',
                null,
                InputOption::VALUE_OPTIONAL,
                'Маркетплейс: wb или ozon. Если не указан — все площадки.',
                null,
            )
            ->addOption(
                'company-id',
                null,
                InputOption::VALUE_OPTIONAL,
                'UUID компании. Если не указан — все компании.',
                null,
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dateOption = $input->getOption('date');
        $reportDate = null;
        if ($dateOption !== null && $dateOption !== '') {
            $dateValue  = (string) $dateOption;
            $reportDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue);
            // createFromFormat нормализует несуществующие даты (например, 2026-02-31 → 2026-03-03)
            // и возвращает DateTimeImmutable, а не false. Roundtrip-проверка отсекает такие
            // случаи: валидная дата должна сериализоваться обратно в исходную строку.
            if ($reportDate === false || $reportDate->format('Y-m-d') !== $dateValue) {
                $output->writeln('<error>Неверный формат --date. Ожидается YYYY-MM-DD.</error>');

                return Command::FAILURE;
            }
        }

        $marketplaceOption = $input->getOption('marketplace');
        $marketplace       = null;
        if ($marketplaceOption !== null && $marketplaceOption !== '') {
            $key = strtolower((string) $marketplaceOption);
            if (!isset(self::MARKETPLACE_ALIASES[$key])) {
                $output->writeln(sprintf(
                    '<error>Неизвестный --marketplace=%s. Допустимо: wb, ozon.</error>',
                    $marketplaceOption,
                ));

                return Command::FAILURE;
            }
            $marketplace = self::MARKETPLACE_ALIASES[$key]->value;
        }

        $companyIdOption = $input->getOption('company-id');
        $companyId       = $companyIdOption !== null && $companyIdOption !== ''
            ? (string) $companyIdOption
            : null;

        $documents = $this->rawDocumentRepository->findByFilters(
            companyId:   $companyId,
            marketplace: $marketplace,
            reportDate:  $reportDate,
        );

        if ($documents === []) {
            $output->writeln('<comment>Документы по указанным фильтрам не найдены.</comment>');

            return Command::SUCCESS;
        }

        // Сначала обновляем статусы всех документов, затем один flush,
        // и только потом диспатч — это снимает N flush'ей на N документов
        // и гарантирует, что статусы согласованно лежат в БД до того,
        // как воркеры начнут их забирать.
        foreach ($documents as $document) {
            if ($document->getStatus() !== AdRawDocumentStatus::DRAFT) {
                $document->resetToDraft();
            }
        }

        $this->entityManager->flush();

        $dispatched = 0;
        foreach ($documents as $document) {
            $this->bus->dispatch(new ProcessAdRawDocumentMessage(
                companyId:       $document->getCompanyId(),
                adRawDocumentId: $document->getId(),
            ));

            ++$dispatched;
        }

        $output->writeln(sprintf(
            '<info>Переобработка запущена: отправлено %d сообщений.</info>',
            $dispatched,
        ));

        return Command::SUCCESS;
    }
}
