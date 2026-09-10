<?php

declare(strict_types=1);

namespace App\Tests\Architecture;

use PHPat\Selector\Selector;
use PHPat\Test\Builder\Rule;
use PHPat\Test\PHPat;

/**
 * Границы модулей как машинная проверка вместо пункта ручного ревью.
 *
 * Правила регистрируются в phpstan.dist.neon с тегом `phpat.test` и исполняются
 * как обычные правила PHPStan, то есть попадают в тот же baseline и тот же гейт.
 */
final class ModuleBoundaryRules
{
    /**
     * Модули, объявившие публичный контракт классом Facade.
     *
     * Критерий — наличие класса, а не каталога: у MoySklad каталог Facade есть,
     * но в нём только .gitkeep, поэтому модуль сюда не входит. Модуль без Facade
     * добавлять нельзя: у нарушения не будет легального способа починки, а гейт,
     * помечающий недостижимое состояние, — дефект (CLAUDE.md, «Health-гейты»).
     */
    private const MODULES_WITH_FACADE = [
        'Balance',
        'Cash',
        'Catalog',
        'Company',
        'Finance',
        'Ingestion',
        'Inventory',
        'Marketplace',
        'MarketplaceAds',
        'MarketplaceAnalytics',
    ];

    /**
     * Внутренние слои модуля, закрытые для остальных модулей.
     *
     * Это дословный запрет из CLAUDE.md («Запрещено импортировать Service/,
     * Repository/, Application/, Infrastructure/ чужого модуля»), а не более
     * широкое «снаружи виден только Facade». Разница принципиальна: Facade в
     * этом проекте возвращают Entity и Enum, поэтому запрет на них сделал бы
     * легальное использование Facade невозможным. Ужесточение до
     * Facade+DTO+Enum+Entity — отдельная задача, оно стоит ещё 230 записей.
     */
    private const CLOSED_LAYERS = [
        'Service',
        'Repository',
        'Application',
        'Infrastructure',
    ];

    /**
     * Легаси-зона: CLAUDE.md запрещает создавать здесь новые файлы.
     * Сейчас каталоги пусты (placeholder-.gitignore на 0 байт, то есть сами по
     * себе они ничего не запрещают), а `src/Service` не существует вовсе.
     *
     * Проверяется именно существование класса (`shouldNot()->exist()`), а не
     * зависимость от него: иначе созданный, но пока никем не используемый
     * легаси-класс проходил бы гейт и всплывал позже.
     *
     * Остаточные пробелы, названные честно: PHPat сравнивает namespace класса,
     * а не путь файла, поэтому правило опирается на PSR-4 (`App\` -> `src/`).
     * Файл в `src/Controller/` с намеренно другим namespace, равно как и файл
     * вообще без class/interface/trait/enum, проверку пройдёт. Закрыть это
     * можно только файловым гейтом — отдельная проверка, не задача PHPat.
     */
    private const LEGACY_NAMESPACES = [
        'App\Entity',
        'App\Service',
        'App\Repository',
        'App\Controller',
    ];

    /**
     * Публичная часть слоя Application: типы, которыми Facade описывает свой
     * контракт. `CompanyFacade`-семейство возвращает
     * `App\Company\Application\DTO\FinancialResponsibilityCenterDTO`,
     * `CashFacade` принимает `App\Cash\Application\DTO\*Input`. Закрыть их
     * вместе с остальным Application значило бы запретить типизированное
     * использование самого Facade — то самое недостижимое состояние.
     */
    private const OPEN_APPLICATION_SUBLAYER = 'Application\DTO';

    /**
     * @return iterable<string, Rule>
     */
    public function test_module_internals_are_closed_to_other_modules(): iterable
    {
        foreach (self::MODULES_WITH_FACADE as $module) {
            $closed = [];
            foreach (self::CLOSED_LAYERS as $layer) {
                $closed[] = Selector::inNamespace(sprintf('App\%s\%s', $module, $layer));
            }

            yield $module => PHPat::rule()
                ->classes(Selector::AllOf(
                    Selector::inNamespace('App'),
                    // Тесты сознательно вне правила: интеграционному тесту
                    // положено дотягиваться до внутренностей проверяемого модуля.
                    Selector::Not(Selector::inNamespace('App\Tests')),
                    Selector::Not(Selector::inNamespace(sprintf('App\%s', $module))),
                ))
                ->shouldNot()
                ->dependOn()
                ->classes(Selector::AnyOf(...$closed))
                ->excluding(Selector::inNamespace(sprintf(
                    'App\%s\%s',
                    $module,
                    self::OPEN_APPLICATION_SUBLAYER,
                )))
                ->because(sprintf(
                    'внутренние слои модуля %s закрыты: снаружи доступны App\%s\Facade '
                    .'и типы его контракта App\%s\Application\DTO',
                    $module,
                    $module,
                    $module,
                ));
        }
    }

    /**
     * Ingestion не создаёт операций ОПиУ.
     *
     * Запрет на весь namespace `App\Finance`, а не точечно по классам ОПиУ:
     * вызов фасада — это тоже импорт `App\Finance\Facade\*`, поэтому одно
     * правило закрывает оба пути, прямой и через посредника. Слой `Facade`
     * правилом выше не закрыт, и без этого `FinanceFacade::createPLDocument()`
     * из Ingestion прошёл бы гейт.
     *
     * Ingestion — загрузка и нормализация сырья; наружу он отдаёт данные, а не
     * операции ОПиУ. Их создаёт Marketplace при закрытии месяца по маппингу
     * «категория затрат → категория ОПиУ», привязанному к компании. Второй
     * источник тех же операций дал бы двойной учёт или расхождение, и молча.
     *
     * Проекция ОПиУ из Ingestion была отключена PR #2302 (06.08.2026); правило
     * превращает то решение из договорённости в проверяемый гейт. На момент
     * добавления в Ingestion ноль импортов `App\Finance`, то есть гейт зелёный
     * с первого дня (`docs/workflow/health-gates.md`).
     *
     * Граница правила, названная честно: PHPat видит настоящую связь классов —
     * тип свойства или параметра, создание, статический вызов. Один лишь
     * `use App\Finance\...;` без использования гейт не роняет (проверено), как
     * и обращение через строку с полным именем класса, контейнер или рефлексию.
     * Правило закрывает обычный путь, а не злонамеренный обход.
     */
    public function test_ingestion_does_not_create_pnl_operations(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Ingestion'))
            ->shouldNot()
            ->dependOn()
            ->classes(Selector::inNamespace('App\Finance'))
            ->because(
                'Ingestion не создаёт операций ОПиУ — ни напрямую, ни через фасады. '
                .'Их создаёт Marketplace при закрытии месяца.',
            );
    }

    /**
     * Обратное направление: Finance не потребляет сообщения и события Ingestion.
     *
     * Правило выше закрывает путь «Ingestion зовёт Finance», но исторически
     * связь была обратной: `Finance\EventSubscriber\NormalizationCompletedSubscriber`
     * слушал событие Ingestion и создавал по нему операции ОПиУ, а надгробия
     * `Mark/RebuildPnlPeriodMessage` реализовывали `App\Ingestion\Message\CompanyAwareMessage`.
     * Всё это удалено, но без запрета подписчик можно завести заново, и первое
     * правило осталось бы зелёным — двойной учёт вернулся бы молча.
     *
     * Запрет намеренно узкий — только `Message` и `Domain\Event`, то есть
     * механизм «Ingestion дёргает пересчёт ОПиУ». Чтение данных Ingestion из
     * Finance иным способом правилом не закрывается: такой задачи не ставилось.
     *
     * На момент добавления в `src/Finance/` ноль ссылок на `App\Ingestion`,
     * то есть гейт зелёный с первого дня.
     */
    public function test_finance_does_not_consume_ingestion_messages(): Rule
    {
        return PHPat::rule()
            ->classes(Selector::inNamespace('App\Finance'))
            ->shouldNot()
            ->dependOn()
            ->classes(
                Selector::inNamespace('App\Ingestion\Message'),
                Selector::inNamespace('App\Ingestion\Domain\Event'),
            )
            ->because(
                'Пересчёт ОПиУ не запускается из Ingestion: подписка Finance на его '
                .'сообщения и события возвращала бы удалённую проекцию.',
            );
    }

    public function test_legacy_zone_stays_empty(): Rule
    {
        $legacy = [];
        foreach (self::LEGACY_NAMESPACES as $namespace) {
            $legacy[] = Selector::inNamespace($namespace);
        }

        return PHPat::rule()
            ->classes(Selector::AnyOf(...$legacy))
            ->shouldNot()
            ->exist()
            ->because('легаси-зона заморожена: новый код живёт в src/{Module}');
    }
}
