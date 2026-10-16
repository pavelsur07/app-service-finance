<?php

declare(strict_types=1);

namespace App\Finance\DTO;

use App\Company\Entity\Company;
use App\Finance\Enum\DocumentStatus;
use App\Finance\Enum\DocumentType;
use App\Finance\Enum\PLFlow;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\InputBag;

/**
 * Параметры списка документов ОПиУ.
 *
 * Фильтры приходят из строки запроса, поэтому конструктор принимает сырые строки
 * и сам приводит их к типам: контроллер остаётся HTTP in/out, а репозиторий
 * получает уже нормализованные значения. Нераспознанное значение (мусор в дате,
 * несуществующий enum, пустая строка) означает «фильтр не задан», а не «не найдено
 * ничего»: иначе опечатка в URL молча прячет весь список.
 */
class DocumentListDTO
{
    public readonly ?\DateTimeImmutable $dateFrom;
    public readonly ?\DateTimeImmutable $dateTo;
    public readonly ?DocumentType $type;
    public readonly ?DocumentStatus $status;
    public readonly ?string $number;
    public readonly ?string $counterparty;
    public readonly ?string $q;
    public readonly ?string $projectDirectionId;
    public readonly ?string $responsibilityCenterId;
    /** Документы, где есть хотя бы одна операция со статьёй этого направления. */
    public readonly ?PLFlow $direction;
    public readonly ?string $categoryId;
    /** Границы модуля суммы операции, десятичная строка с точкой: «1 000,50» → `1000.50`. */
    public readonly ?string $amountFrom;
    public readonly ?string $amountTo;

    public function __construct(
        public readonly Company $company,
        public readonly int $page = 1,
        public readonly int $limit = 20,
        ?string $dateFrom = null,
        ?string $dateTo = null,
        ?string $type = null,
        ?string $status = null,
        ?string $number = null,
        ?string $counterparty = null,
        ?string $q = null,
        ?string $projectDirectionId = null,
        ?string $responsibilityCenterId = null,
        ?string $direction = null,
        ?string $categoryId = null,
        ?string $amountFrom = null,
        ?string $amountTo = null,
    ) {
        $this->dateFrom = self::parseDate($dateFrom)?->setTime(0, 0, 0);
        $this->dateTo = self::parseDate($dateTo)?->setTime(23, 59, 59);
        $this->type = DocumentType::tryFrom(self::normalizeText($type) ?? '');
        $this->status = DocumentStatus::tryFrom(self::normalizeText($status) ?? '');
        $this->number = self::normalizeText($number);
        $this->counterparty = self::normalizeText($counterparty);
        $this->q = self::normalizeText($q);
        $this->projectDirectionId = self::parseUuid($projectDirectionId);
        $this->responsibilityCenterId = self::parseUuid($responsibilityCenterId);
        $this->direction = match (self::normalizeText($direction)) {
            'income' => PLFlow::INCOME,
            'expense' => PLFlow::EXPENSE,
            default => null,
        };
        $this->categoryId = self::parseUuid($categoryId);
        $this->amountFrom = self::parseAmount($amountFrom);
        $this->amountTo = self::parseAmount($amountTo);
    }

    /**
     * Фильтры вкладок раздела из строки запроса — одни имена параметров у «Документов» и
     * «Операций», чтобы ссылка вкладки переносила фильтры как есть.
     *
     * @param InputBag<string> $query
     */
    public static function fromQuery(Company $company, InputBag $query): self
    {
        return new self(
            $company,
            max(1, (int) $query->get('page', 1)),
            (int) $query->get('limit', 20),
            $query->getString('dateFrom'),
            $query->getString('dateTo'),
            $query->getString('type'),
            $query->getString('status'),
            $query->getString('number'),
            $query->getString('counterparty'),
            $query->getString('q'),
            $query->getString('projectDirection'),
            $query->getString('responsibilityCenter'),
            $query->getString('direction'),
            $query->getString('category'),
            $query->getString('amountFrom'),
            $query->getString('amountTo'),
        );
    }

    /**
     * Задан ли хоть один распознанный фильтр: пустой список без фильтров — «документов ещё нет»,
     * с фильтрами — «ничего не найдено».
     */
    public function hasFilters(): bool
    {
        return null !== $this->dateFrom || null !== $this->dateTo || null !== $this->type
            || null !== $this->status || null !== $this->number || null !== $this->counterparty
            || null !== $this->q || null !== $this->projectDirectionId
            || null !== $this->responsibilityCenterId || null !== $this->direction
            || null !== $this->categoryId || null !== $this->amountFrom || null !== $this->amountTo;
    }

    /**
     * Граница суммы: неотрицательное число с не более чем двумя знаками после запятой
     * (точность колонки). Пробелы-разделители разрядов и запятая допускаются — так сумму
     * пишут руками. Знак минус — мусор: фильтр по модулю, «−5» означал бы непонятно что.
     */
    private static function parseAmount(?string $value): ?string
    {
        $value = self::normalizeText($value);
        if (null === $value) {
            return null;
        }

        $value = str_replace(',', '.', (string) preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $value));
        if (1 !== preg_match('/^\d{1,13}(\.\d{1,2})?$/', $value)) {
            return null;
        }

        return bcadd($value, '0', 2);
    }

    /**
     * Идентификатор в нижнем регистре: в БД guid хранится так, а сравнение строк регистрозависимо.
     */
    private static function parseUuid(?string $value): ?string
    {
        $value = self::normalizeText($value);

        return null !== $value && Uuid::isValid($value) ? strtolower($value) : null;
    }

    /**
     * Принимается только календарная дата в формате поля `<input type="date">`.
     *
     * Разбор строгий намеренно: `new \DateTimeImmutable()` принял бы `tomorrow`
     * (граница поехала бы за текущим днём) и молча превратил бы `2024-02-30`
     * в 1 марта — то есть применил бы границу, которую пользователь не задавал.
     */
    private static function parseDate(?string $value): ?\DateTimeImmutable
    {
        $value = self::normalizeText($value);

        if (null === $value) {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);

        if (false === $date || $date->format('Y-m-d') !== $value) {
            return null;
        }

        // PostgreSQL TIMESTAMP не знает нулевого года: такой параметр не отфильтровал
        // бы ничего, а уронил бы запрос ошибкой драйвера уже после валидации.
        return '0000' === $date->format('Y') ? null : $date;
    }

    private static function normalizeText(?string $value): ?string
    {
        $value = trim((string) $value);

        return '' === $value ? null : $value;
    }
}
