<?php

declare(strict_types=1);

namespace App\Finance\Command;

use App\Company\Enum\BusinessProfile;
use App\Company\Exception\CompanyNotFoundException;
use App\Company\Facade\CompanyFacade;
use App\Finance\Application\Action\ApplyDefaultPLTemplateAction;
use App\Finance\Application\DeletePLCategoryAction;
use App\Finance\Entity\PLCategory;
use App\Finance\Enum\PLTemplateApplicationSource;
use App\Finance\Infrastructure\Provider\DefaultPLCategoryTreeProvider;
use App\Finance\Infrastructure\Query\PLCategoryUsageQuery;
use App\Finance\Repository\PLCategoryRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Разовая: приводит дерево ОПиУ компании, созданное шаблоном «Базовый» v2–v3, к v4 (08.11.2026):
 * переименования групп, снятые статьи «Фото, видео, дизайн» и «Сервисы и подписки (коммерческие)»,
 * новая «Штрафы, пени и разовые убытки». Название, изменённое пользователем, не трогается; статья,
 * на которую что-то ссылается, не удаляется. Дерево с кодами вне шаблона (статьи маркетплейса) —
 * отказ. Повтор безопасен. Удалить команду после применения к компаниям прода.
 */
#[AsCommand(
    name: 'app:finance:pl-tree:upgrade-universal-v4',
    description: 'Разовая: приводит дерево ОПиУ «Базовый» к шаблону v4 (без --execute — только план).',
)]
final class UpgradeUniversalPlTreeV4Command extends Command
{
    /** Код → [название v3, название v4]. */
    private const array RENAMES = [
        'OVERHEAD_TOTAL' => ['Операционные расходы', 'Косвенные расходы'],
        'OVERHEAD_PROD_TOTAL' => ['Производственные', 'Помещения и производство'],
    ];

    private const array REMOVED = ['OVERHEAD_SALES_CONTENT', 'OVERHEAD_SALES_SUBS'];

    public function __construct(
        private readonly CompanyFacade $companies,
        private readonly PLCategoryRepository $categories,
        private readonly PLCategoryUsageQuery $usage,
        private readonly DeletePLCategoryAction $delete,
        private readonly ApplyDefaultPLTemplateAction $applyTemplate,
        private readonly DefaultPLCategoryTreeProvider $templates,
        private readonly EntityManagerInterface $em,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addArgument('companyId', InputArgument::REQUIRED, 'UUID компании')
            ->addOption('execute', null, InputOption::VALUE_NONE, 'Применить; без опции — только план');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $companyId = (string) $input->getArgument('companyId');
        $execute = (bool) $input->getOption('execute');
        if (!Uuid::isValid($companyId)) {
            $output->writeln(\sprintf('<error>Не UUID: %s</error>', $companyId));

            return Command::INVALID;
        }

        try {
            $profile = $this->companies->getBusinessProfile($companyId);
        } catch (CompanyNotFoundException) {
            $output->writeln('<error>Компания не найдена.</error>');

            return Command::FAILURE;
        }
        if (BusinessProfile::UNIVERSAL !== $profile) {
            $output->writeln(\sprintf('<error>Профиль компании %s, нужен UNIVERSAL.</error>', $profile->value));

            return Command::FAILURE;
        }

        /** @var list<PLCategory> $all */
        $all = $this->categories->findBy(['company' => $companyId]);
        /** @var array<string, PLCategory> $byCode */
        $byCode = [];
        foreach ($all as $category) {
            if (null !== $category->getCode() && '' !== $category->getCode()) {
                $byCode[$category->getCode()] = $category;
            }
        }
        $allowed = [...array_map(static fn ($n): ?string => $n->code, $this->templates->nodes(BusinessProfile::UNIVERSAL)), ...self::REMOVED];
        $foreign = array_values(array_diff(array_keys($byCode), $allowed));
        if ([] !== $foreign) {
            $output->writeln(\sprintf('<error>В дереве коды вне шаблона «Базовый»: %s — не трогаю.</error>', implode(', ', $foreign)));

            return Command::FAILURE;
        }

        /** @var list<array{PLCategory, string}> $renames */
        $renames = [];
        foreach (self::RENAMES as $code => [$old, $new]) {
            $name = isset($byCode[$code]) ? $byCode[$code]->getName() : null;
            if ($old === $name) {
                $renames[] = [$byCode[$code], $new];
                $output->writeln(\sprintf('rename  %-26s «%s» → «%s»', $code, $old, $new));
            } elseif (null !== $name && $new !== $name) {
                $output->writeln(\sprintf('keep    %-26s «%s» — название изменено пользователем', $code, $name));
            }
        }

        /** @var list<PLCategory> $removals */
        $removals = [];
        foreach (self::REMOVED as $code) {
            if (!isset($byCode[$code])) {
                continue;
            }
            $id = (string) $byCode[$code]->getId();
            $used = ($this->usage)($companyId, $id)[$id] ?? null;
            if (null !== $used && $used->isUsed()) {
                $output->writeln(\sprintf('keep    %-26s «%s» — используется: %s', $code, $byCode[$code]->getName(), $used->summary()));
                continue;
            }
            // Бюджет и маппинг затрат PLCategoryUsageQuery не видит: у бюджета нет FK, у маппинга — SET NULL.
            $refs = (int) $this->em->getConnection()->fetchOne(
                'SELECT (SELECT COUNT(*) FROM budget_lines WHERE company_id = :c AND pl_category_id = :id)
                      + (SELECT COUNT(*) FROM budget_rules WHERE company_id = :c AND (pl_category_id = :id OR base_category_id = :id))
                      + (SELECT COUNT(*) FROM marketplace_cost_categories WHERE company_id = :c AND pl_category_id = :id)',
                ['c' => $companyId, 'id' => $id],
            );
            if ($refs > 0) {
                $output->writeln(\sprintf('keep    %-26s «%s» — используется в бюджете или маппинге затрат: %d', $code, $byCode[$code]->getName(), $refs));
                continue;
            }
            $removals[] = $byCode[$code];
            $output->writeln(\sprintf('delete  %-26s «%s»', $code, $byCode[$code]->getName()));
        }

        // Весь сценарий — одной транзакцией: вложенные транзакции Action становятся точками сохранения,
        // сбой любого шага откатывает и переименования, и удаления.
        $result = $execute
            ? $this->em->wrapInTransaction(function () use ($renames, $removals, $companyId) {
                foreach ($renames as [$category, $name]) {
                    $category->setName($name);
                }
                $this->em->flush();
                foreach ($removals as $category) {
                    ($this->delete)($category, $companyId);
                }

                return ($this->applyTemplate)($companyId, false, PLTemplateApplicationSource::COMMAND);
            })
            : ($this->applyTemplate)($companyId, true, PLTemplateApplicationSource::COMMAND);
        foreach ($result->created as $row) {
            $output->writeln(\sprintf('create  %-26s %s', $row->code ?? '', $row->path));
        }

        $output->writeln(\sprintf(
            '%s: rename %d, delete %d, create %d',
            $execute ? 'applied' : 'plan (dry run)',
            \count($renames),
            \count($removals),
            \count($result->created),
        ));
        if ($execute) {
            $this->logger->info('Universal P&L tree upgraded to v4', [
                'companyId' => $companyId,
                'renamed' => \count($renames),
                'deleted' => \count($removals),
                'created' => \count($result->created),
            ]);
        }

        return Command::SUCCESS;
    }
}
