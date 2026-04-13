<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Command;

use App\Company\Facade\CompanyFacade;
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
 * Чтобы исключить случайный прогон «всего-по-всем-компаниям» при опечатке в CLI,
 * команда требует либо хотя бы один фильтр, либо явный --all.
 *
 * Обработка идёт батчами: статусы обновляются и flush'атся пачками по BATCH_SIZE,
 * после flush вызывается EntityManager::clear() — это удерживает identity map
 * в ограниченном размере даже при десятках тысяч документов. Выборка из
 * репозитория получается через {@see AdRawDocumentRepository::streamByFilters()}
 * (Doctrine toIterable) — документы читаются из курсора по одному и не
 * материализуются все сразу в память, поэтому clear() между батчами не
 * оставляет «хвост» detached-сущностей из предзагруженного массива.
 * Сообщения в очередь отправляются только после того, как все статусы
 * закоммичены в БД, иначе воркер мог бы забрать документ раньше, чем увидит
 * его DRAFT-статус.
 */
#[AsCommand(
    name: 'marketplace-ads:reprocess',
    description: 'Переобрабатывает уже загруженные AdRawDocument по фильтрам (дата, площадка, компания).',
)]
final class ReprocessAdDataCommand extends Command
{
    /** @var array<string, MarketplaceType> псевдонимы CLI → MarketplaceType */
    private const MARKETPLACE_ALIASES = [
        'wb' => MarketplaceType::WILDBERRIES,
        'wildberries' => MarketplaceType::WILDBERRIES,
        'ozon' => MarketplaceType::OZON,
    ];

    /**
     * Размер пачки для flush+clear. Эмпирически: на 50 объектах накладные
     * расходы на лишние flush незначительны, зато identity map Doctrine
     * не разрастается при очень больших выборках.
     */
    private const BATCH_SIZE = 50;

    public function __construct(
        private readonly AdRawDocumentRepository $rawDocumentRepository,
        private readonly CompanyFacade $companyFacade,
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
            )
            ->addOption(
                'all',
                null,
                InputOption::VALUE_NONE,
                'Разрешить переобработку без единого фильтра (все компании × все даты × все площадки).',
            );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dateOption = $input->getOption('date');
        $reportDate = null;
        if (null !== $dateOption && '' !== $dateOption) {
            $dateValue = (string) $dateOption;
            $reportDate = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateValue);
            // createFromFormat нормализует несуществующие даты (например, 2026-02-31 → 2026-03-03)
            // и возвращает DateTimeImmutable, а не false. Roundtrip-проверка отсекает такие
            // случаи: валидная дата должна сериализоваться обратно в исходную строку.
            if (false === $reportDate || $reportDate->format('Y-m-d') !== $dateValue) {
                $output->writeln('<error>Неверный формат --date. Ожидается YYYY-MM-DD.</error>');

                return Command::FAILURE;
            }
        }

        $marketplaceOption = $input->getOption('marketplace');
        $marketplace = null;
        if (null !== $marketplaceOption && '' !== $marketplaceOption) {
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
        $companyId = null;
        if (null !== $companyIdOption && '' !== $companyIdOption) {
            $companyId = (string) $companyIdOption;
            // Валидируем наличие компании — иначе при опечатке UUID команда молча
            // напишет «документы не найдены» и оператор решит, что всё обработано.
            if (null === $this->companyFacade->findById($companyId)) {
                $output->writeln(sprintf(
                    '<error>Компания не найдена: --company-id=%s.</error>',
                    $companyId,
                ));

                return Command::FAILURE;
            }
        }

        $allFlag = (bool) $input->getOption('all');

        // Без хотя бы одного фильтра команда переобработала бы всю историю по всем
        // компаниям — слишком большой blast radius для опечатки. Требуем явное
        // согласие через --all.
        if (null === $companyId && null === $marketplace && null === $reportDate && !$allFlag) {
            $output->writeln(
                '<error>Требуется хотя бы один фильтр (--company-id / --marketplace / --date) '
                .'или явный флаг --all для переобработки всей истории.</error>',
            );

            return Command::FAILURE;
        }

        // Стримим документы из репозитория через toIterable() — не материализуем
        // весь результат в памяти и не держим «хвост» detached-сущностей после
        // EntityManager::clear() (каждая следующая yield-нутая entity приходит
        // из курсора уже после clear и попадает в identity map свежей managed).
        $stream = $this->rawDocumentRepository->streamByFilters(
            companyId: $companyId,
            marketplace: $marketplace,
            reportDate: $reportDate,
        );

        // Собираем пары (companyId, id) для dispatch — в момент clear() объекты
        // Entity станут detached, поэтому запоминаем scalar-идентификаторы заранее.
        /** @var list<array{companyId: string, id: string}> $toDispatch */
        $toDispatch = [];
        $batchIndex = 0;

        foreach ($stream as $document) {
            if (AdRawDocumentStatus::DRAFT !== $document->getStatus()) {
                $document->resetToDraft();
            }

            $toDispatch[] = [
                'companyId' => $document->getCompanyId(),
                'id' => $document->getId(),
            ];

            if (0 === ++$batchIndex % self::BATCH_SIZE) {
                $this->entityManager->flush();
                $this->entityManager->clear();
            }
        }

        if (0 === $batchIndex) {
            $output->writeln('<comment>Документы по указанным фильтрам не найдены.</comment>');

            return Command::SUCCESS;
        }

        // Финальный flush для хвоста последней неполной пачки. clear()
        // здесь делаем тоже — у команды дальше нет операций с identity map,
        // а воркер в любом случае перечитает документ по id + companyId.
        $this->entityManager->flush();
        $this->entityManager->clear();

        $dispatched = 0;
        foreach ($toDispatch as $ids) {
            $this->bus->dispatch(new ProcessAdRawDocumentMessage(
                companyId: $ids['companyId'],
                adRawDocumentId: $ids['id'],
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
