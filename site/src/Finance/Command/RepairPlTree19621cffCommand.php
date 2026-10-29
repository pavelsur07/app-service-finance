<?php

declare(strict_types=1);

namespace App\Finance\Command;

use App\Company\Entity\Company;
use App\Company\Facade\CompanyFacade;
use App\Finance\Application\Service\PLRegisterUpdater;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Одноразовый ремонт дерева ОПиУ компании 19621cff по базовому шаблону маркетплейса
 * (аудит 29.10.2026, docs/tasks/vumjoy-pl-tree-repair/plan.md):
 *
 * 1. правила продаж и затрат маркетплейса, проценты кредитов, статья ДДС «Дивиденды» — на
 *    статьи шаблона (`config/marketplace/default_cost_mapping.yaml`);
 * 2. операции документов из старых статей — в новые; документы маркетплейса текущего месяца
 *    не трогаются: их пересобирает оперативное закрытие 04:45 уже по новым правилам;
 *    регистр ОПиУ пересчитывается за затронутые дни;
 * 3. структура дерева: расходы OPEX — под «Косвенные расходы», формула «Чистой прибыли»,
 *    коды и направления листов, удаление пустых дублей, код REV_NOT_SPP листу продаж.
 *
 * Модуль владеет деревом, документами и регистром; правила маркетплейса, кредиты и статья
 * ДДС правятся точечным SQL по ID, сверенным с продом: команда одноразовая и удаляется после
 * применения, постоянный контракт Facade под неё не заводится.
 *
 * Всё — одной транзакцией. Строка не в ожидаемом состоянии — откат целиком, exit 1. Чистый
 * итог регистра (доходы − расходы) за каждый затронутый день до и после переноса операций
 * обязан совпасть. Статья, на которую ещё ссылаются документы текущего месяца, не
 * удаляется — повторный запуск после 04:45 удалит её. Повтор ничего не меняет.
 * Без --execute транзакция откатывается: вывод — ровно то, что будет записано.
 *
 * Удалить после применения на проде.
 */
#[AsCommand(
    name: 'app:finance:repair-pl-tree-19621cff',
    description: 'Одноразово: дерево ОПиУ компании 19621cff к базовому шаблону маркетплейса (без --execute — предпросмотр с откатом)',
)]
final class RepairPlTree19621cffCommand extends Command
{
    public const string COMPANY_ID = '19621cff-b028-45d9-9193-11f47ad9a8b2';

    // Статьи ОПиУ компании.
    public const string COGS_PRODUCT = '55d59732-4f8f-4c0b-a49f-0f641f97e32c';
    public const string COGS_PRODUCT_REV = '01a188d0-7c5e-72fa-9bc7-5a54f1015125';
    public const string COGS_PRODUCT_RET = '01a188d0-7c5f-72dd-99fa-aea4922d2a65';
    public const string OLD_REV_NOT_SPP = '883e8f5a-316a-480b-875c-402110d1a890';
    public const string REV_NOT_SPP_SALES = '83ad7980-8ed1-4f2e-b994-8527437c8292';
    public const string MP_STORAGE = '8889e4eb-94c8-4296-9235-e26a3885fae5';
    public const string MP_OTHER_DEDUCTIONS = '2ab90e88-a6cd-404b-956f-349ad60ddf73';
    public const string WH_STORAGE = '01a188d0-7c66-722a-8c6c-834fc37ea298';
    public const string WH_RECEIVING = '01a188d0-7c66-722a-8c6c-834fc40a9d7a';
    public const string WH_MP_DEDUCTIONS = '01a188d0-7c67-7190-a524-3d46b88b11b1';
    public const string WH_INSURANCE = '01a188d0-7c68-71c4-98c2-b1f4f7b6b339';
    public const string MP_COMMISSION = '0b7ce745-5fd4-442d-9a04-12a4cdc7ba81';
    public const string OVERHEAD_TOTAL = '01a188d0-7c6d-723f-926c-aae5f946315c';
    public const string OVERHEAD_PROD_CERT = '01a188d0-7c70-7018-a085-5bb5e8744217';
    public const string OVERHEAD_SALES_TOTAL = '01a188d0-7c79-718a-b282-e0251bebdd75';
    public const string OPEX_TOTAL = '27d378b7-6129-4a5f-b9c2-061676bdff3e';
    public const string OPEX_PROD_FIXED = '51be5181-a00b-498d-ad36-836b17ce057c';
    public const string OPEX_PROD_COMPLIANCE = '9d91e682-4ef0-48cd-9364-cd25e506bf29';
    public const string OPEX_PROD_OTHER = '333bc95e-b2e4-44ef-bfdc-bfb43a2fa919';
    public const string OPEX_GNA = '489c990d-b378-4550-91cd-b6a254497981';
    public const string OPEX_GNA_OTHER = '4638aa79-8b46-498e-af87-d663f3c52af0';
    public const string OPEX_MKT = '5a9caae6-f7ee-4418-a2c6-80e8f32a523c';
    public const string OPEX_MKT_WAGES = 'de0f8082-db68-4b1e-8785-a9a4f0291b6b';
    public const string SALES_BONUS = '01a188d0-7c7b-721b-b576-1c58501ec6e3';
    public const string OTHER_INCOME = '01a188d0-7c82-7299-ad93-61ba4c204593';
    public const string NET_PROFIT = '01a188d0-7c83-717b-95ac-993bbb5ebd5b';
    public const string BELOW_EBITDA_INTEREST = '01a188d0-7c81-70d1-b1dc-6627253b0072';
    public const string INTEREST_EXPENSES = 'fb65adfc-681d-4399-ac3c-272d869d729f';
    public const string PROFIT_DISTR_DIVIDENDS = '01a188d0-7c84-713b-8a08-8f353209d3da';
    public const string DIVIDENDS_ROOT = 'e46de814-956f-4ef6-8a5f-bd38f88e4be3';

    public const string NET_PROFIT_FORMULA_OLD = 'EBITDA+BELOW_EBITDA_TOTAL';
    public const string NET_PROFIT_FORMULA = 'EBITDA + BELOW_EBITDA_TOTAL + BELOW_EBITDA_INCOME_TOTAL';

    /** Правило продаж → [подпись, строка ввода]; сейчас все смотрят на группу «Себестоимость». */
    public const array SALE_RULES = [
        '07dbf485-1cb6-4de7-a897-c86903fb882b' => ['ozon sale_cost_price', self::COGS_PRODUCT_REV],
        '4679ac0c-90fd-4685-b049-b35b7b73c8bc' => ['wildberries sale_cost_price', self::COGS_PRODUCT_REV],
        '01a158ef-ebef-7097-8ef9-b16946504d0f' => ['ozon return_cost_price', self::COGS_PRODUCT_RET],
        '01a158ef-ebf2-7399-bea8-9674549e6274' => ['wildberries return_cost_price', self::COGS_PRODUCT_RET],
    ];

    /** Правило затрат → [маркетплейс, код категории затрат, статья сейчас, статья шаблона]. */
    public const array COST_RULES = [
        '01a12899-591b-7340-9113-b0a9e0330328' => ['ozon', 'ozon_correction_point', self::MP_OTHER_DEDUCTIONS, self::WH_MP_DEDUCTIONS],
        '01a12899-591a-72cb-965d-9d353b94c523' => ['ozon', 'ozon_decompensation', self::MP_OTHER_DEDUCTIONS, self::WH_MP_DEDUCTIONS],
        'b1adb93c-f4d4-406a-96c8-7ba88b6ac345' => ['ozon', 'ozon_disposal', self::MP_OTHER_DEDUCTIONS, self::WH_MP_DEDUCTIONS],
        '01a12899-5921-70b1-bfc4-781da7d20ab0' => ['ozon', 'ozon_fines_cancellation', self::MP_OTHER_DEDUCTIONS, self::WH_MP_DEDUCTIONS],
        '01a12899-591d-73f8-abfe-547322ef8ad5' => ['ozon', 'ozon_fines_shipment_delay_rated', self::MP_OTHER_DEDUCTIONS, self::WH_MP_DEDUCTIONS],
        'dadfd9b4-145c-4fb3-9eb3-b5adaaffbb74' => ['ozon', 'ozon_other_service', self::MP_OTHER_DEDUCTIONS, self::WH_MP_DEDUCTIONS],
        '01a12899-591d-73f8-abfe-5473224b3e3d' => ['ozon', 'ozon_ovh_processing', self::MP_OTHER_DEDUCTIONS, self::WH_MP_DEDUCTIONS],
        '847c1a4e-01e2-44a8-b3e3-74bfd46c3591' => ['ozon', 'ozon_premium_correction', self::MP_OTHER_DEDUCTIONS, self::WH_MP_DEDUCTIONS],
        '01a12899-591b-7340-9113-b0a9e096c92e' => ['ozon', 'ozon_seller_correction', self::MP_OTHER_DEDUCTIONS, self::WH_MP_DEDUCTIONS],
        '17661e31-5ef2-413d-a05b-b83e66a370ed' => ['ozon', 'ozon_service_correction', self::MP_OTHER_DEDUCTIONS, self::WH_MP_DEDUCTIONS],
        '01a12899-591d-73f8-abfe-547322614303' => ['ozon', 'ozon_service_fee_rfbs', self::MP_OTHER_DEDUCTIONS, self::WH_MP_DEDUCTIONS],
        '01a12dbf-b3d8-714c-ad83-78e9655d419f' => ['wildberries', 'wb_otchet_ob_utilizirovannom_tovare_po_skladu_za_i', self::MP_OTHER_DEDUCTIONS, self::WH_MP_DEDUCTIONS],
        // Шаблон относит страхование к удержаниям; у компании своя статья — решение Владельца 29.10.
        '01a12899-5921-70b1-bfc4-781da8049688' => ['ozon', 'ozon_stock_insurance', self::MP_OTHER_DEDUCTIONS, self::WH_INSURANCE],
        // Шаблон — «Банковские комиссии» (OVERHEAD_ADMIN_BANK); у компании банковские комиссии ДДС ведут сюда.
        '615c7655-1ce6-4c90-b3b7-f6c4053d0f40' => ['ozon', 'ozon_early_payment', self::MP_OTHER_DEDUCTIONS, self::OPEX_GNA_OTHER],
        '42e4b346-7927-4786-a84c-bf00e4318700' => ['ozon', 'ozon_flexible_payment', self::MP_OTHER_DEDUCTIONS, self::OPEX_GNA_OTHER],
        'aaf9b152-eb98-4573-9e98-e3e182c8e569' => ['ozon', 'ozon_storage', self::MP_STORAGE, self::WH_STORAGE],
        '474b25e5-ccdd-4be0-9526-06ff72f71f10' => ['ozon', 'ozon_storage_partner', self::MP_STORAGE, self::WH_STORAGE],
        '6e14733c-0580-4861-b553-d1cd947f21ed' => ['ozon', 'ozon_crossdocking', self::MP_STORAGE, self::WH_RECEIVING],
        '73741b44-b785-4a57-8161-e2ebf738824e' => ['ozon', 'ozon_logistic_inbound', self::MP_STORAGE, self::WH_RECEIVING],
        '1a92252a-a8e1-4445-971b-e4f0ec48e319' => ['ozon', 'ozon_logistic_inbound_seller', self::MP_STORAGE, self::WH_RECEIVING],
        '042255d6-1875-4dbe-bca4-ef6125e6258f' => ['ozon', 'ozon_supply_additional', self::MP_STORAGE, self::WH_RECEIVING],
        '201e56c3-b5ca-4e27-8577-e9774b52a7a4' => ['ozon', 'ozon_supply_shortage', self::MP_STORAGE, self::WH_RECEIVING],
        'c95b1ee0-18d7-40f8-83aa-674c4f24ef71' => ['ozon', 'ozon_supply_surplus', self::MP_STORAGE, self::WH_RECEIVING],
        '219f975f-0979-4d04-975f-1f868a9e8d0f' => ['wildberries', 'product_processing', self::MP_STORAGE, self::WH_RECEIVING],
        'b78245c6-0357-4d30-a5da-f021ddc313b6' => ['ozon', 'ozon_brand_commission', self::MP_STORAGE, self::MP_COMMISSION],
        // OVERHEAD_PROD_CERT удаляется ниже как пустой дубль OPEX_PROD_COMPLIANCE.
        '3db93fdd-ac4f-4c4d-aedf-06c61fc30a15' => ['ozon', 'ozon_marking', self::OVERHEAD_PROD_CERT, self::OPEX_PROD_COMPLIANCE],
    ];

    /** Кредит → подпись; статья процентов INTEREST_EXPENSES → BELOW_EBITDA_INTEREST. */
    public const array LOANS = [
        '46a03905-30f7-408a-be51-f4415a0ba573' => 'Сбербанк',
        'bc3774b1-60d9-4aba-bde5-6b9c2aa7e1ad' => 'Альфа-Банк',
    ];

    public const string CASHFLOW_DIVIDENDS = 'd57b5155-4f65-46ae-b95b-d373c191d8e7';

    /** Старая статья → новая для операций любых документов. */
    private const array OP_MOVES = [
        self::OLD_REV_NOT_SPP => self::REV_NOT_SPP_SALES,
        self::INTEREST_EXPENSES => self::BELOW_EBITDA_INTEREST,
        self::DIVIDENDS_ROOT => self::PROFIT_DISTR_DIVIDENDS,
    ];

    /** Операции ручного документа WB 11.2025 в статьях затрат МП (не документ маркетплейса). */
    private const array MANUAL_COST_OPS = [
        'ce7a583a-1a23-4ffe-bf4a-c2b06a4dff32' => self::WH_MP_DEDUCTIONS,
        'ed8a6947-3c34-47cb-b484-a187faac3b30' => self::WH_STORAGE,
    ];

    private const array SOURCE_MARKETPLACE = ['marketplace_ozon' => 'ozon', 'marketplace_wb' => 'wildberries'];

    /** Группы OPEX: OPEX_TOTAL → OVERHEAD_TOTAL (уровень 2 не меняется). */
    private const array OPEX_GROUPS = [
        self::OPEX_PROD_FIXED => 'Производственные фиксированные',
        self::OPEX_GNA => 'Административные (G&A)',
        self::OPEX_MKT => 'Маркетинговые и коммерческие',
    ];

    /** Код листу без кода: [подпись, код]. */
    private const array CODES = [
        self::OPEX_PROD_OTHER => ['Прочие производственные', 'OPEX_PROD_OTHER'],
        self::SALES_BONUS => ['ЗП коммерческого персонала бонусная-часть', 'OPEX_MKT_WAGES_BONUS'],
    ];

    /** Направление листа: [подпись, было, стало]. */
    private const array FLOWS = [
        self::OPEX_MKT_WAGES => ['Зарплата маркетологов / SMM', 'NONE', 'EXPENSE'],
        self::OTHER_INCOME => ['Прочие доходы', 'NONE', 'INCOME'],
    ];

    /**
     * Удаляемые статьи — листья раньше групп. true — статья может быть занята документами
     * текущего месяца до ночной пересборки: тогда откладывается, а не отменяет запуск.
     *
     * @var array<string, array{0: string, 1: bool}>
     */
    private const array DELETES = [
        '01a188d0-7c6e-71ed-a0cc-3e7e089f2988' => ['OVERHEAD_PROD_RENT', false],
        '01a188d0-7c6f-7337-96f2-ca86321f90b8' => ['OVERHEAD_PROD_MAINT', false],
        '01a188d0-7c70-7018-a085-5bb5e835613c' => ['OVERHEAD_PROD_PAYROLL', false],
        self::OVERHEAD_PROD_CERT => ['OVERHEAD_PROD_CERT', false],
        '01a188d0-7c6d-723f-926c-aae5fa3ddbcc' => ['OVERHEAD_PROD_TOTAL', false],
        '01a188d0-7c71-7373-a790-ed7f546a10a4' => ['OVERHEAD_ADMIN_PAYROLL', false],
        '01a188d0-7c72-7332-94aa-fe394044df9e' => ['OVERHEAD_ADMIN_PAYROLL_TAX', false],
        '01a188d0-7c72-7332-94aa-fe3940b762ae' => ['OVERHEAD_ADMIN_COMMUNICATIONS', false],
        '01a188d0-7c72-7332-94aa-fe3941b41630' => ['OVERHEAD_ADMIN_CONTRACTORS', false],
        '01a188d0-7c73-73ef-8e2e-92bc52308fbc' => ['OVERHEAD_ADMIN_SUBS', false],
        '01a188d0-7c77-707b-8aad-2469c42b5039' => ['OVERHEAD_ADMIN_BANK', false],
        '01a188d0-7c78-706e-8a2a-7a06ecef4e16' => ['OVERHEAD_ADMIN_OTHER', false],
        '01a188d0-7c71-7373-a790-ed7f54277918' => ['OVERHEAD_ADMIN_TOTAL', false],
        '01a188d0-7c79-718a-b282-e0251c608c57' => ['OVERHEAD_SALES_PAYROLL', false],
        '01a188d0-7c7a-72b8-9bb8-409a92a082f8' => ['OVERHEAD_SALES_CONTENT', false],
        '01a188d0-7c7a-72b8-9bb8-409a931fe785' => ['OVERHEAD_SALES_RND', false],
        '01a188d0-7c7b-721b-b576-1c5850040a67' => ['OVERHEAD_SALES_SUBS', false],
        self::OVERHEAD_SALES_TOTAL => ['OVERHEAD_SALES_TOTAL', false],
        self::OPEX_TOTAL => ['OPEX_TOTAL', false],
        'bd946fef-0b58-4ecd-bad3-ccf0a4be81ab' => ['GROSS_MARGI_CM1', false],
        'd28c5b7a-b14e-4bc4-ba21-00311c15fe0d' => ['EBITDA_MARGIN', false],
        self::INTEREST_EXPENSES => ['INTEREST_EXPENSES', false],
        'c3d686bf-6da4-4fd9-9ed1-eaecff44c769' => ['INCOME_TAX', false],
        '94c0e0b0-1d67-491f-be08-4af9c417ab70' => ['BELOW_EBITDA_EXPENSES', false],
        '4f6d60e1-0352-47e1-8da5-f329fc82f4e0' => ['INTEREST_INCOME', false],
        'e043c8e8-fc48-4bde-b6b1-5db482b32b32' => ['BELOW_EBITDA_INCOME', false],
        self::DIVIDENDS_ROOT => ['«Дивиденды» без кода', false],
        self::OLD_REV_NOT_SPP => ['REV_NOT_SPP (старый лист)', false],
        self::MP_STORAGE => ['MP_STORAGE', true],
        self::MP_OTHER_DEDUCTIONS => ['MP_OTHER_DEDUCTIONS', true],
    ];

    /** @var list<array{0: string, 1: string, 2: string}> */
    private array $rows = [];
    private int $changed = 0;
    private int $failed = 0;
    private int $deferred = 0;

    public function __construct(
        private readonly CompanyFacade $companyFacade,
        private readonly PLRegisterUpdater $registerUpdater,
        private readonly EntityManagerInterface $em,
        private readonly Connection $connection,
        private readonly LoggerInterface $logger,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addOption('execute', null, InputOption::VALUE_NONE, 'Записать; без флага — предпросмотр с откатом транзакции');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $execute = (bool) $input->getOption('execute');
        $this->rows = [];
        $this->changed = $this->failed = $this->deferred = 0;

        if (null === $this->companyFacade->findById(self::COMPANY_ID)) {
            $io->error(sprintf('Компания %s не найдена.', self::COMPANY_ID));

            return Command::FAILURE;
        }

        $context = ['company_id' => self::COMPANY_ID, 'execute' => $execute];
        $this->logger->info('[RepairPlTree19621cff] started', $context);

        $committed = false;
        $days = [];
        $this->connection->beginTransaction();
        try {
            $this->retargetRules();
            $days = $this->moveOperations();
            $this->restructure();
            $this->deleteCategories();
            $this->renameSalesCode();

            if (0 === $this->failed && $execute) {
                $this->connection->commit();
                $committed = true;
            }
        } finally {
            if (!$committed) {
                $this->connection->rollBack();
            }
            // Документы и статьи правились DBAL; ORM-копии в памяти устарели в обоих исходах.
            $this->em->clear();
        }

        if ($committed) {
            $this->registerUpdater->invalidateCacheForCompanyId(self::COMPANY_ID);
        }

        $io->table(['Шаг', 'Что', 'Результат'], $this->rows);
        $summary = ['changed' => $this->changed, 'failed' => $this->failed, 'deferred' => $this->deferred, 'register_days' => count($days)];
        $this->logger->info('[RepairPlTree19621cff] finished', $context + $summary + ['committed' => $committed]);

        if ($this->failed > 0) {
            $io->error(sprintf('Ошибок: %d. Транзакция откатана, ничего не записано.', $this->failed));

            return Command::FAILURE;
        }

        $tail = $this->deferred > 0
            ? sprintf(' Отложено удалений: %d — повторить после оперативного закрытия 04:45.', $this->deferred)
            : '';

        if (!$execute) {
            $io->note(sprintf('Предпросмотр: изменений %d, транзакция откатана. Для записи повторить с --execute.%s', $this->changed, $tail));

            return Command::SUCCESS;
        }

        $io->success(sprintf('Записано изменений: %d.%s', $this->changed, $tail));

        return Command::SUCCESS;
    }

    private function retargetRules(): void
    {
        foreach (self::SALE_RULES as $id => [$label, $to]) {
            $this->guarded(
                'правило продаж',
                $label.' → '.$this->name($to),
                'UPDATE marketplace_sale_mappings m SET pl_category_id = :to, updated_at = :now
                 WHERE m.id = :id AND m.company_id = :company AND m.pl_category_id = :from AND '.$this->leafSql(),
                ['id' => $id, 'from' => self::COGS_PRODUCT, 'to' => $to],
                'SELECT pl_category_id FROM marketplace_sale_mappings WHERE id = :id AND company_id = :company',
                $to,
            );
        }

        foreach (self::COST_RULES as $id => [$marketplace, $code, $from, $to]) {
            $this->guarded(
                'правило затрат',
                sprintf('%s %s → %s', $marketplace, $code, $this->name($to)),
                'UPDATE marketplace_cost_pl_mappings m SET pl_category_id = :to, updated_at = :now
                 WHERE m.id = :id AND m.company_id = :company AND m.pl_category_id = :from AND '.$this->leafSql(),
                ['id' => $id, 'from' => $from, 'to' => $to],
                'SELECT pl_category_id FROM marketplace_cost_pl_mappings WHERE id = :id AND company_id = :company',
                $to,
            );
        }

        foreach (self::LOANS as $id => $label) {
            $this->guarded(
                'кредит',
                $label.': проценты → «Проценты по кредитам» (ниже EBITDA)',
                'UPDATE finance_loan m SET pl_category_interest_id = :to, updated_at = :now
                 WHERE m.id = :id AND m.company_id = :company AND m.pl_category_interest_id = :from AND '.$this->leafSql(),
                ['id' => $id, 'from' => self::INTEREST_EXPENSES, 'to' => self::BELOW_EBITDA_INTEREST],
                'SELECT pl_category_interest_id FROM finance_loan WHERE id = :id AND company_id = :company',
                self::BELOW_EBITDA_INTEREST,
            );
        }

        $this->guarded(
            'статья ДДС',
            '«Дивиденды» → «Дивиденды» в распределении прибыли',
            'UPDATE cashflow_categories m SET pl_category_id = :to
             WHERE m.id = :id AND m.company_id = :company AND m.pl_category_id = :from AND '.$this->leafSql(),
            ['id' => self::CASHFLOW_DIVIDENDS, 'from' => self::DIVIDENDS_ROOT, 'to' => self::PROFIT_DISTR_DIVIDENDS],
            'SELECT pl_category_id FROM cashflow_categories WHERE id = :id AND company_id = :company',
            self::PROFIT_DISTR_DIVIDENDS,
        );
    }

    /**
     * Переносит операции документов из старых статей и пересчитывает регистр за их дни.
     *
     * @return list<string> затронутые дни, Y-m-d
     */
    private function moveOperations(): array
    {
        $sources = [self::COGS_PRODUCT, self::MP_STORAGE, self::MP_OTHER_DEDUCTIONS, ...array_keys(self::OP_MOVES)];
        $operations = $this->connection->fetchAllAssociative(
            "SELECT o.id, o.category_id, o.amount, o.comment, d.type, d.source, to_char(d.date, 'YYYY-MM-DD') AS day
             FROM document_operations o
             JOIN documents d ON d.id = o.document_id
             WHERE d.company_id = :company
               AND o.category_id IN (:sources)
               AND NOT (d.type = 'marketplace_pl' AND d.date >= :month_start)
             ORDER BY d.date, o.id",
            ['company' => self::COMPANY_ID, 'sources' => $sources, 'month_start' => $this->currentMonthStart()],
            ['sources' => ArrayParameterType::STRING],
        );

        $costRules = $this->costRulesByName();
        $moves = [];
        $unrouted = 0;
        foreach ($operations as $op) {
            $target = $this->targetFor($op, $costRules);
            if (null === $target) {
                ++$unrouted;
                $this->rows[] = ['операция', sprintf('%s %s %s «%s»', $op['day'], $this->name((string) $op['category_id']), $op['amount'], $op['comment']), 'ОШИБКА: не знаю, куда перенести'];

                continue;
            }
            $moves[] = [(string) $op['id'], (string) $op['category_id'], $target, (string) $op['day'], (string) $op['amount']];
        }

        $days = array_values(array_unique(array_column($moves, 3)));
        sort($days);
        if ($unrouted > 0) {
            $this->failed += $unrouted;

            return $days;
        }
        if ([] === $moves) {
            $this->rows[] = ['операции', 'перенос из старых статей', 'нечего переносить'];

            return $days;
        }

        $before = $this->netByDay($days);

        $byPair = [];
        foreach ($moves as [$id, $from, $to, $day, $amount]) {
            $moved = $this->connection->executeStatement(
                'UPDATE document_operations SET category_id = :to WHERE id = :id AND category_id = :from',
                ['id' => $id, 'from' => $from, 'to' => $to],
            );
            if (1 !== $moved) {
                ++$this->failed;
                $this->rows[] = ['операция', $id, 'ОШИБКА: изменилась во время запуска'];

                continue;
            }
            $key = $this->name($from).' → '.$this->name($to);
            $byPair[$key] ??= [0, '0.00'];
            ++$byPair[$key][0];
            $byPair[$key][1] = bcadd($byPair[$key][1], $amount, 2);
            ++$this->changed;
        }
        foreach ($byPair as $pair => [$count, $sum]) {
            $this->rows[] = ['операции', $pair, sprintf('перенесено %d, сумма %s', $count, $sum)];
        }

        // Документы перечитываются из БД: в памяти ORM — старые статьи операций.
        $this->em->clear();
        $after = $this->netByDay($days);
        $drift = 0;
        foreach ($days as $day) {
            if (0 !== bccomp($before[$day], $after[$day], 2)) {
                ++$drift;
                $this->rows[] = ['регистр', $day, sprintf('ОШИБКА: итог дня %s → %s', $before[$day], $after[$day])];
            }
        }
        if ($drift > 0) {
            $this->failed += $drift;

            return $days;
        }

        $company = $this->company();
        foreach ($days as $day) {
            $date = new \DateTimeImmutable($day);
            $this->registerUpdater->recalcRange($company, $date, $date);
        }
        $this->rows[] = ['регистр', sprintf('пересчёт %d дн. (%s … %s)', count($days), $days[0], $days[count($days) - 1]), 'итог каждого дня не изменился'];

        return $days;
    }

    /**
     * @param array<string, mixed> $op
     * @param array<string, array<string, array{0: string, 1: string}>> $costRules маркетплейс → название категории затрат → [статья сейчас, статья шаблона]
     */
    private function targetFor(array $op, array $costRules): ?string
    {
        $from = (string) $op['category_id'];
        if (isset(self::OP_MOVES[$from])) {
            return self::OP_MOVES[$from];
        }

        if (self::COGS_PRODUCT === $from) {
            // Правило sale_cost_price пишет +X, return_cost_price — −X (сторно себестоимости).
            return bccomp((string) $op['amount'], '0', 2) < 0 ? self::COGS_PRODUCT_RET : self::COGS_PRODUCT_REV;
        }

        if ('marketplace_pl' !== $op['type']) {
            return self::MANUAL_COST_OPS[(string) $op['id']] ?? null;
        }

        $marketplace = self::SOURCE_MARKETPLACE[(string) $op['source']] ?? '';
        // Комментарий операции закрытия: «[Оперативное закрытие …] <категория затрат>[ [сторно]]».
        $name = (string) preg_replace(['/^\[[^]]*\] /u', '/ \[сторно\]$/u'], '', (string) $op['comment']);
        $rule = $costRules[$marketplace][$name] ?? null;

        // Категория затрат должна вести из той же старой статьи, что и операция.
        return null !== $rule && $rule[0] === $from ? $rule[1] : null;
    }

    /**
     * Категории затрат компании из COST_RULES по названию; неоднозначное название не попадает.
     *
     * @return array<string, array<string, array{0: string, 1: string}>>
     */
    private function costRulesByName(): array
    {
        $byCode = [];
        foreach (self::COST_RULES as [$marketplace, $code, $from, $to]) {
            $byCode[$marketplace][$code] = [$from, $to];
        }

        $rows = $this->connection->fetchAllAssociative(
            'SELECT marketplace, code, name FROM marketplace_cost_categories WHERE company_id = :company AND deleted_at IS NULL',
            ['company' => self::COMPANY_ID],
        );
        $count = [];
        foreach ($rows as $row) {
            $count[$row['marketplace']][$row['name']] = ($count[$row['marketplace']][$row['name']] ?? 0) + 1;
        }

        $rules = [];
        foreach ($rows as $row) {
            $rule = $byCode[$row['marketplace']][$row['code']] ?? null;
            if (null !== $rule && 1 === $count[$row['marketplace']][$row['name']]) {
                $rules[(string) $row['marketplace']][(string) $row['name']] = $rule;
            }
        }

        return $rules;
    }

    /**
     * Чистый итог регистра за день (доходы − расходы) — так, как его записал бы пересчёт.
     *
     * @param list<string> $days
     *
     * @return array<string, string>
     */
    private function netByDay(array $days): array
    {
        $company = $this->company();
        $net = [];
        foreach ($days as $day) {
            $date = new \DateTimeImmutable($day);
            $sum = '0.00';
            foreach ($this->registerUpdater->calculateRows($company, $date, $date) as $row) {
                $sum = bcadd($sum, bcsub($row->income, $row->expense, 2), 2);
            }
            $net[$day] = $sum;
        }

        return $net;
    }

    private function restructure(): void
    {
        $this->guarded(
            'формула',
            '«Чистая прибыль» = '.self::NET_PROFIT_FORMULA,
            "UPDATE pl_categories SET formula = :to WHERE id = :id AND company_id = :company AND replace(formula, ' ', '') = :from",
            ['id' => self::NET_PROFIT, 'from' => self::NET_PROFIT_FORMULA_OLD, 'to' => self::NET_PROFIT_FORMULA],
            'SELECT formula FROM pl_categories WHERE id = :id AND company_id = :company',
            self::NET_PROFIT_FORMULA,
        );

        foreach (self::OPEX_GROUPS as $id => $label) {
            $this->guarded(
                'структура',
                sprintf('«%s» → под «Косвенные расходы»', $label),
                'UPDATE pl_categories SET parent_id = :to WHERE id = :id AND company_id = :company AND parent_id = :from',
                ['id' => $id, 'from' => self::OPEX_TOTAL, 'to' => self::OVERHEAD_TOTAL],
                'SELECT parent_id FROM pl_categories WHERE id = :id AND company_id = :company',
                self::OVERHEAD_TOTAL,
            );
        }

        // Бонусная часть ЗП — к «Маркетинговым и коммерческим»: её прежняя группа удаляется.
        $this->guarded(
            'структура',
            '«ЗП коммерческого персонала бонусная-часть» → под «Маркетинговые и коммерческие»',
            'UPDATE pl_categories SET parent_id = :to, sort_order = 220 WHERE id = :id AND company_id = :company AND parent_id = :from',
            ['id' => self::SALES_BONUS, 'from' => self::OVERHEAD_SALES_TOTAL, 'to' => self::OPEX_MKT],
            'SELECT parent_id FROM pl_categories WHERE id = :id AND company_id = :company',
            self::OPEX_MKT,
        );

        foreach (self::CODES as $id => [$label, $code]) {
            $this->guarded(
                'код',
                sprintf('«%s» → %s', $label, $code),
                "UPDATE pl_categories c SET code = :to WHERE c.id = :id AND c.company_id = :company AND c.code IS NULL AND c.type = 'LEAF_INPUT'
                 AND NOT EXISTS (SELECT 1 FROM pl_categories o WHERE o.company_id = :company AND o.code = :to)",
                ['id' => $id, 'to' => $code],
                'SELECT code FROM pl_categories WHERE id = :id AND company_id = :company',
                $code,
            );
        }

        foreach (self::FLOWS as $id => [$label, $from, $to]) {
            $this->guarded(
                'направление',
                sprintf('«%s»: %s → %s', $label, $from, $to),
                'UPDATE pl_categories SET flow = :to WHERE id = :id AND company_id = :company AND flow = :from',
                ['id' => $id, 'from' => $from, 'to' => $to],
                'SELECT flow FROM pl_categories WHERE id = :id AND company_id = :company',
                $to,
            );
        }
    }

    private function deleteCategories(): void
    {
        foreach (self::DELETES as $id => [$label, $deferrable]) {
            $category = $this->connection->fetchAssociative(
                'SELECT code FROM pl_categories WHERE id = :id AND company_id = :company',
                ['id' => $id, 'company' => self::COMPANY_ID],
            );
            if (false === $category) {
                $this->rows[] = ['удаление', $label, 'уже удалена'];

                continue;
            }

            $refs = $this->references($id, null === $category['code'] ? null : (string) $category['code']);
            if ([] !== $refs) {
                $what = implode(', ', array_map(static fn (string $k, int $n): string => $k.' '.$n, array_keys($refs), $refs));
                if ($deferrable) {
                    ++$this->deferred;
                    $this->rows[] = ['удаление', $label, 'отложено: '.$what];
                } else {
                    ++$this->failed;
                    $this->rows[] = ['удаление', $label, 'ОШИБКА: на статью ссылаются — '.$what];
                }

                continue;
            }

            $this->connection->executeStatement('DELETE FROM pl_categories WHERE id = :id AND company_id = :company', ['id' => $id, 'company' => self::COMPANY_ID]);
            ++$this->changed;
            $this->rows[] = ['удаление', $label, 'удалена'];
        }
    }

    /**
     * Всё, что держит статью: дети, документы, регистр, правила, кредиты, ДДС, формулы по коду.
     *
     * @return array<string, int>
     */
    private function references(string $id, ?string $code): array
    {
        $checks = [
            'дочерних' => 'SELECT count(*) FROM pl_categories WHERE parent_id = :id',
            'операций' => 'SELECT count(*) FROM document_operations WHERE category_id = :id',
            'строк регистра' => 'SELECT count(*) FROM pl_daily_totals WHERE pl_category_id = :id',
            'снимков' => 'SELECT count(*) FROM pl_monthly_snapshots WHERE pl_category_id = :id',
            'правил продаж' => 'SELECT count(*) FROM marketplace_sale_mappings WHERE pl_category_id = :id',
            'правил затрат' => 'SELECT count(*) FROM marketplace_cost_pl_mappings WHERE pl_category_id = :id',
            'категорий затрат' => 'SELECT count(*) FROM marketplace_cost_categories WHERE pl_category_id = :id',
            'статей ДДС' => 'SELECT count(*) FROM cashflow_categories WHERE pl_category_id = :id',
            'кредитов' => 'SELECT count(*) FROM finance_loan WHERE pl_category_interest_id = :id OR pl_category_fee_id = :id',
        ];
        $refs = [];
        foreach ($checks as $what => $sql) {
            $n = (int) $this->connection->fetchOne($sql, ['id' => $id]);
            if ($n > 0) {
                $refs[$what] = $n;
            }
        }

        // Код старого листа продаж переходит рабочему (renameSalesCode): формула «Выручки» его не теряет.
        if (null !== $code && '' !== $code && self::OLD_REV_NOT_SPP !== $id) {
            $n = (int) $this->connection->fetchOne(
                "SELECT count(*) FROM pl_categories WHERE company_id = :company AND id <> :id AND formula ~* ('\\m' || :code || '\\M')",
                ['company' => self::COMPANY_ID, 'id' => $id, 'code' => $code],
            );
            if ($n > 0) {
                $refs['формул'] = $n;
            }
        }

        return $refs;
    }

    /**
     * Код шаблона REV_NOT_SPP — рабочему листу продаж, когда старый лист с этим кодом удалён:
     * формула «Выручки» (REV_NOT_SPP + REV_RETURNS) снова видит продажи.
     */
    private function renameSalesCode(): void
    {
        $this->guarded(
            'код',
            '«Продажи без СПП»: REV_NOT_SPP_SALES → REV_NOT_SPP',
            "UPDATE pl_categories c SET code = 'REV_NOT_SPP' WHERE c.id = :id AND c.company_id = :company AND c.code = 'REV_NOT_SPP_SALES'
             AND NOT EXISTS (SELECT 1 FROM pl_categories o WHERE o.company_id = :company AND o.code = 'REV_NOT_SPP')",
            ['id' => self::REV_NOT_SPP_SALES],
            'SELECT code FROM pl_categories WHERE id = :id AND company_id = :company',
            'REV_NOT_SPP',
        );
    }

    /**
     * UPDATE с охраной ожидаемого состояния: 1 строка — изменено; 0 и текущее значение уже
     * целевое — пропуск; иначе строка не в ожидаемом состоянии — ошибка, откат запуска.
     *
     * @param array<string, string> $params
     */
    private function guarded(string $step, string $what, string $update, array $params, string $current, string $target): void
    {
        $params += ['company' => self::COMPANY_ID];
        $updateParams = $params + ['now' => (new \DateTimeImmutable())->format('Y-m-d H:i:s')];
        if (!str_contains($update, ':now')) {
            unset($updateParams['now']);
        }

        if (1 === $this->connection->executeStatement($update, $updateParams)) {
            ++$this->changed;
            $this->rows[] = [$step, $what, 'изменено'];

            return;
        }

        $value = $this->connection->fetchOne($current, ['id' => $params['id'], 'company' => self::COMPANY_ID]);
        if (false !== $value && $target === (string) $value) {
            $this->rows[] = [$step, $what, 'уже'];

            return;
        }

        ++$this->failed;
        $this->rows[] = [$step, $what, sprintf('ОШИБКА: не в ожидаемом состоянии (сейчас %s)', false === $value ? 'нет строки' : var_export($value, true))];
    }

    /** Целевая статья — лист ввода той же компании. */
    private function leafSql(): string
    {
        return "EXISTS (SELECT 1 FROM pl_categories t WHERE t.id = :to AND t.company_id = :company AND t.type = 'LEAF_INPUT')";
    }

    private function name(string $categoryId): string
    {
        $row = $this->connection->fetchAssociative('SELECT code, name FROM pl_categories WHERE id = :id', ['id' => $categoryId]);

        return false === $row ? $categoryId : (string) ($row['code'] ?? $row['name']);
    }

    private function company(): Company
    {
        return $this->companyFacade->findById(self::COMPANY_ID)
            ?? throw new \LogicException('Компания исчезла во время запуска.');
    }

    /** Начало текущего месяца по Москве: даты документов в БД — московские без зоны. */
    private function currentMonthStart(): string
    {
        return (new \DateTimeImmutable('first day of this month midnight', new \DateTimeZone('Europe/Moscow')))->format('Y-m-d H:i:s');
    }
}
