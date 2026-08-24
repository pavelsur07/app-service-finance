<?php

declare(strict_types=1);

namespace App\Finance\Application\Service;

use App\Finance\Application\DTO\PLCategoryTreeNode;
use App\Finance\Enum\PLCategoryType;
use App\Finance\Enum\PLExpenseType;
use App\Finance\Enum\PLFlow;
use App\Finance\Enum\PLValueFormat;

/**
 * Чтение файла выгрузки категорий ОПиУ в дерево-источник импорта.
 *
 * Единственная граница доверия в переносе: файл приходит из другого аккаунта,
 * его содержимое не контролируется целевой компанией и может быть отредактировано
 * руками. Поэтому здесь всё, что попадёт в PLCategory, проверяется до того, как
 * импорт вообще начнётся: любая проблема — DomainException с путём узла, а не
 * исключение Doctrine на середине транзакции.
 *
 * Идентификаторы компании и категорий из файла не читаются вообще: импорт всегда
 * идёт в активную компанию, новым категориям id выдаётся при создании.
 */
final class PLCategoryTreeFileReader
{
    public const MAX_BYTES = 1_048_576;
    private const MAX_NODES = 1000;
    private const MAX_LEVEL = 5;
    private const MAX_NAME_LENGTH = 255;
    private const MAX_CODE_LENGTH = 64;
    private const JSON_MAX_DEPTH = 64;

    /**
     * Категория обязана нести весь набор полей формата v1. Терпимость к
     * неполным файлам здесь означала бы тихую потерю настроек: узел файла
     * матчится с существующей категорией по code, а импорт перезаписывает
     * поля целиком — отсутствующий `flow` сбросил бы INCOME в NONE, а
     * отсутствующий `weightInParent` превратил бы вес -0.5000 в 1.0000. В
     * предпросмотре это выглядит как обычное «обновить». Экспорт всегда пишет
     * все поля, поэтому строгость ничего не ломает, а недостающее поле
     * называется в тексте ошибки.
     */
    private const REQUIRED_FIELDS = [
        'code',
        'type',
        'format',
        'flow',
        'expenseType',
        'weightInParent',
        'isVisible',
        'formula',
        'calcOrder',
        'sortOrder',
    ];

    /**
     * calcOrder и sortOrder ложатся в PostgreSQL integer. На 64-битном PHP
     * 2147483648 — валидный int, но в колонку не влезет: без этой проверки
     * граница доверия пропустила бы значение до Doctrine и вместо понятной
     * ошибки пользователь получил бы 500. Границы несимметричны, поэтому
     * сравнение идёт по каждой отдельно: abs() отверг бы допустимый минимум
     * и сделал бы невозможным импорт собственной же выгрузки.
     */
    private const MIN_INT32 = -2147483648;
    private const MAX_INT32 = 2147483647;

    /**
     * @return list<PLCategoryTreeNode> DFS pre-order: родитель раньше потомка
     */
    public function read(string $json): array
    {
        if (strlen($json) > self::MAX_BYTES) {
            throw new \DomainException(sprintf('Файл слишком большой (%d КБ), максимум — %d КБ.', (int) ceil(strlen($json) / 1024), self::MAX_BYTES / 1024));
        }

        if ('' === trim($json)) {
            throw new \DomainException('Файл пустой.');
        }

        try {
            $decoded = json_decode($json, true, self::JSON_MAX_DEPTH, \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new \DomainException(sprintf('Файл не является корректным JSON: %s', $e->getMessage()), 0, $e);
        }

        $categories = $this->extractCategories($decoded);

        $nodes = [];
        /** @var array<string, string> $seenCodes код → путь узла, который его занял */
        $seenCodes = [];
        $this->collect($categories, null, 'категории', $nodes, $seenCodes, 1);

        return $nodes;
    }

    /**
     * @return list<mixed>
     */
    private function extractCategories(mixed $decoded): array
    {
        if (!is_array($decoded)) {
            throw new \DomainException('Файл не содержит дерева категорий.');
        }

        // Голый массив верхнего уровня — формат прежнего эндпоинта выгрузки,
        // который отдавал JSON без конверта. Такие файлы могли быть сохранены
        // вручную, поэтому читаем их тоже.
        if (array_is_list($decoded)) {
            return $decoded;
        }

        if (!array_key_exists('categories', $decoded)) {
            throw new \DomainException('В файле нет раздела "categories" — похоже, это выгрузка не категорий ОПиУ.');
        }

        $version = $decoded['version'] ?? null;
        if (PLCategoryTreeExporter::FORMAT_VERSION !== $version) {
            throw new \DomainException(sprintf('Файл выгружен в формате версии %s, поддерживается версия %d.', is_scalar($version) ? (string) $version : 'неизвестной', PLCategoryTreeExporter::FORMAT_VERSION));
        }

        if (!is_array($decoded['categories']) || !array_is_list($decoded['categories'])) {
            throw new \DomainException('Раздел "categories" должен быть списком категорий.');
        }

        return $decoded['categories'];
    }

    /**
     * @param list<mixed> $rows
     * @param list<PLCategoryTreeNode> &$nodes
     * @param array<string, string> &$seenCodes
     */
    private function collect(array $rows, ?PLCategoryTreeNode $parent, string $parentPath, array &$nodes, array &$seenCodes, int $level): void
    {
        if ($level > self::MAX_LEVEL) {
            throw new \DomainException(sprintf('Превышена максимальная вложенность (%d уровней) в "%s".', self::MAX_LEVEL, $parentPath));
        }

        /** @var array<string, bool> $siblingNames имя → есть ли у узла code */
        $siblingNames = [];

        foreach ($rows as $index => $row) {
            if (!is_array($row)) {
                throw new \DomainException(sprintf('Категория №%d в "%s" — не объект.', (int) $index + 1, $parentPath));
            }

            if (count($nodes) >= self::MAX_NODES) {
                throw new \DomainException(sprintf('В файле больше %d категорий — это не похоже на дерево ОПиУ.', self::MAX_NODES));
            }

            $name = $this->readName($row, $parentPath, (int) $index + 1);
            $path = null !== $parent ? $parentPath.' / '.$name : $name;

            foreach (self::REQUIRED_FIELDS as $field) {
                if (!array_key_exists($field, $row)) {
                    throw new \DomainException(sprintf('В категории "%s" нет поля "%s". Файл должен содержать полный набор полей выгрузки — иначе импорт молча заменит недостающие настройки значениями по умолчанию.', $path, $field));
                }
            }

            $code = $this->readCode($row, $path);

            // Узел без code опознаётся в целевой компании парой (родитель, имя).
            // Два одноимённых соседа, из которых хотя бы один без кода, имеют
            // одну и ту же импортную идентичность: повторная загрузка того же
            // файла начала бы плодить дубли вместо обновления.
            if (isset($siblingNames[$name]) && (null === $code || !$siblingNames[$name])) {
                throw new \DomainException(sprintf('Категория "%s" встречается дважды среди соседних. Одноимённые категории у одного родителя должны иметь разные коды — иначе повторный импорт создаст дубли.', $path));
            }

            $siblingNames[$name] = null !== $code;

            if (null !== $code) {
                if (isset($seenCodes[$code])) {
                    throw new \DomainException(sprintf('Код "%s" встречается в файле дважды: "%s" и "%s". Код должен быть уникален — иначе обе категории претендуют на одну строку в целевой компании.', $code, $seenCodes[$code], $path));
                }

                $seenCodes[$code] = $path;
            }

            $node = new PLCategoryTreeNode(
                key: 'n'.count($nodes),
                parent: $parent,
                name: $name,
                code: $code,
                type: $this->readEnum($row, 'type', PLCategoryType::class, $path),
                format: $this->readEnum($row, 'format', PLValueFormat::class, $path),
                flow: $this->readEnum($row, 'flow', PLFlow::class, $path),
                expenseType: $this->readEnum($row, 'expenseType', PLExpenseType::class, $path),
                weightInParent: $this->readWeight($row, $path),
                isVisible: $this->readBool($row, 'isVisible', $path),
                formula: $this->readNullableString($row, 'formula', $path),
                calcOrder: $this->readNullableInt($row, 'calcOrder', $path),
                sortOrder: $this->readRequiredInt($row, 'sortOrder', $path),
            );

            $nodes[] = $node;

            $children = $row['children'] ?? [];
            if (!is_array($children) || ([] !== $children && !array_is_list($children))) {
                throw new \DomainException(sprintf('Поле "children" категории "%s" должно быть списком.', $path));
            }

            if ([] !== $children) {
                $this->collect($children, $node, $path, $nodes, $seenCodes, $level + 1);
            }
        }
    }

    /**
     * PostgreSQL не хранит нулевой байт в varchar/text, а JSON его спокойно
     * несёт (\u0000). Без этой проверки заявленная гарантия «всё проверено до
     * начала импорта» ломается ошибкой БД на середине транзакции.
     */
    private function assertNoNullByte(string $value, string $what, string $path): void
    {
        if (str_contains($value, "\0")) {
            throw new \DomainException(sprintf('Недопустимый символ в поле "%s" категории "%s".', $what, $path));
        }
    }

    /**
     * @param array<mixed> $row
     */
    private function readName(array $row, string $parentPath, int $position): string
    {
        $name = $row['name'] ?? null;
        if (!is_string($name) || '' === trim($name)) {
            throw new \DomainException(sprintf('У категории №%d в "%s" не заполнено название.', $position, $parentPath));
        }

        // Имя сохраняется ровно как в файле: PLCategory::setName() его не
        // нормализует, а экспорт пишет как есть. Обрезка пробелов здесь
        // означала бы, что источник " Расходы" совпадёт с целевой категорией
        // "Расходы" и молча перезапишет её настройки вместо создания
        // отдельной строки. trim() — только для проверки на пустоту.
        $this->assertNoNullByte($name, 'название', $parentPath);

        if (mb_strlen($name) > self::MAX_NAME_LENGTH) {
            throw new \DomainException(sprintf('Название категории "%s..." длиннее %d символов.', mb_substr($name, 0, 30), self::MAX_NAME_LENGTH));
        }

        return $name;
    }

    /**
     * @param array<mixed> $row
     */
    private function readCode(array $row, string $path): ?string
    {
        $code = $row['code'] ?? null;
        if (null === $code) {
            return null;
        }

        if (!is_string($code)) {
            throw new \DomainException(sprintf('Код категории "%s" должен быть строкой.', $path));
        }

        $this->assertNoNullByte($code, 'код', $path);

        // Нормализация обязана совпадать с PLCategory::setCode(): иначе
        // сохранённое значение разойдётся с тем, по которому шёл матчинг, и
        // повторный импорт того же файла перестанет быть идемпотентным.
        $code = '' !== trim($code) ? mb_strtoupper(trim($code)) : null;

        if (null !== $code && mb_strlen($code) > self::MAX_CODE_LENGTH) {
            throw new \DomainException(sprintf('Код категории "%s" длиннее %d символов.', $path, self::MAX_CODE_LENGTH));
        }

        return $code;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param array<mixed> $row
     * @param class-string<T> $enumClass
     *
     * @return T
     */
    private function readEnum(array $row, string $field, string $enumClass, string $path): \BackedEnum
    {
        $value = $row[$field];

        if (is_string($value)) {
            $parsed = $enumClass::tryFrom($value);
            if (null !== $parsed) {
                return $parsed;
            }
        }

        throw new \DomainException(sprintf('Недопустимое значение "%s" в поле "%s" категории "%s". Допустимые значения: %s.', is_scalar($value) ? (string) $value : gettype($value), $field, $path, implode(', ', array_column($enumClass::cases(), 'value'))));
    }

    /**
     * @param array<mixed> $row
     */
    private function readWeight(array $row, string $path): string
    {
        $value = $row['weightInParent'];

        if (!is_numeric($value)) {
            throw new \DomainException(sprintf('Вес категории "%s" должен быть числом.', $path));
        }

        // is_numeric() пропускает «1e9999»: во float это INF, number_format()
        // отдаёт строку "inf", а обратное приведение (float) "inf" даёт 0.0 —
        // то есть бесконечность проскочила бы проверку диапазона и ушла в
        // decimal(10,4).
        $number = (float) $value;
        if (!is_finite($number)) {
            throw new \DomainException(sprintf('Вес категории "%s" вне допустимого диапазона.', $path));
        }

        // Колонка — decimal(10,4). Проверять надо уже округлённое значение:
        // 999999.99999 проходит проверку «меньше миллиона», но округляется в
        // 1000000.0000, которое в колонку уже не влезает.
        $normalized = number_format($number, 4, '.', '');

        if (abs((float) $normalized) >= 1_000_000) {
            throw new \DomainException(sprintf('Вес категории "%s" вне допустимого диапазона.', $path));
        }

        return $normalized;
    }

    /**
     * @param array<mixed> $row
     */
    private function readBool(array $row, string $field, string $path): bool
    {
        $value = $row[$field];

        if (!is_bool($value)) {
            throw new \DomainException(sprintf('Поле "%s" категории "%s" должно быть true или false.', $field, $path));
        }

        return $value;
    }

    /**
     * @param array<mixed> $row
     */
    private function readNullableString(array $row, string $field, string $path): ?string
    {
        $value = $row[$field];
        if (null === $value) {
            return null;
        }

        if (!is_string($value)) {
            throw new \DomainException(sprintf('Поле "%s" категории "%s" должно быть строкой.', $field, $path));
        }

        $this->assertNoNullByte($value, sprintf('поле "%s"', $field), $path);

        // Пустая строка сохраняется как есть: PLCategory::setFormula() её не
        // превращает в null, и превращение здесь давало бы вечное «обновить»
        // на первом импорте собственной же выгрузки.
        return $value;
    }

    /**
     * @param array<mixed> $row
     */
    private function readNullableInt(array $row, string $field, string $path): ?int
    {
        $value = $row[$field];
        if (null === $value) {
            return null;
        }

        return $this->assertInt32($value, $field, $path);
    }

    /**
     * @param array<mixed> $row
     */
    private function readRequiredInt(array $row, string $field, string $path): int
    {
        return $this->assertInt32($row[$field], $field, $path);
    }

    private function assertInt32(mixed $value, string $field, string $path): int
    {
        if (!is_int($value)) {
            throw new \DomainException(sprintf('Поле "%s" категории "%s" должно быть целым числом.', $field, $path));
        }

        if ($value < self::MIN_INT32 || $value > self::MAX_INT32) {
            throw new \DomainException(sprintf('Поле "%s" категории "%s" вне допустимого диапазона.', $field, $path));
        }

        return $value;
    }
}
