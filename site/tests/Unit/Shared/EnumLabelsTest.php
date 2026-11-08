<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared;

use App\Cash\Enum\Accounts\MoneyAccountType;
use App\Cash\Enum\Transaction\CashCategorySuggestionStatus;
use App\Finance\Enum\DocumentStatus;
use App\Finance\Enum\DocumentType;
use App\Settlement\Enum\SettlementBucket;
use App\Settlement\Enum\SettlementDocumentKind;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Stage 3 PR D (twig-ui-systematization): подписи, жившие словарями `{% set …Labels %}` в шаблонах, переехали в Enum.
 * Тексты зафиксированы как были в шаблонах; каждый case имеет подпись.
 */
final class EnumLabelsTest extends TestCase
{
    /** @return iterable<string, array{class-string<\BackedEnum>, string, array<string, string>}> */
    public static function labels(): iterable
    {
        yield 'document status' => [DocumentStatus::class, 'label', ['DRAFT' => 'Черновик', 'ACTIVE' => 'Активен', 'INACTIVE' => 'Неактивен', 'TO_DELETE' => 'На удаление']];
        yield 'document type short' => [DocumentType::class, 'shortLabel', ['DEAL_SALE' => 'Сделка', 'PAYROLL' => 'Зарплата', 'TAXES' => 'Налоги', 'LOANS' => 'Кредиты', 'OBLIGATIONS' => 'Обязательства', 'ASSETS' => 'Имущество', 'CASH' => 'Касса', 'CASHFLOW_EXPENSE' => 'Расход из ДДС', 'OTHER' => 'Прочие', 'marketplace_pl' => 'ОПиУ маркетплейса']];
        yield 'settlement bucket' => [SettlementBucket::class, 'label', ['receivable' => 'Нам должны', 'payable' => 'К оплате', 'advance_given' => 'Выданные авансы', 'advance_received' => 'Полученные авансы', 'unallocated_out' => 'Нераспределённые выплаты', 'unallocated_in' => 'Нераспределённые поступления']];
        yield 'settlement kind' => [SettlementDocumentKind::class, 'label', ['opening' => 'Начальный остаток', 'obligation' => 'Основание долга', 'payment' => 'Оплата', 'correction' => 'Корректировка', 'allocation' => 'Погашение', 'classification' => 'Классификация оплаты', 'allocation_reversal' => 'Отмена погашения', 'document_reversal' => 'Отмена документа']];
        yield 'money account type' => [MoneyAccountType::class, 'label', ['bank' => 'Банк', 'cash' => 'Касса', 'ewallet' => 'Кошелёк', 'crypto_wallet' => 'Крипто-кошелёк']];
        yield 'ai suggestion status' => [CashCategorySuggestionStatus::class, 'label', ['requested' => 'ждут ответа', 'suggested' => 'показаны, без решения', 'no_answer' => 'без ответа модели', 'failed' => 'ошибка', 'stale' => 'устарели', 'accepted' => 'приняты', 'dismissed' => 'отклонены', 'auto_applied' => 'применены автоматически']];
    }

    /**
     * @param class-string<\BackedEnum> $enum
     * @param array<string, string> $expected
     */
    #[DataProvider('labels')]
    public function testEveryCaseKeepsTheTemplateText(string $enum, string $method, array $expected): void
    {
        $actual = [];
        foreach ($enum::cases() as $case) {
            $actual[(string) $case->value] = $case->{$method}();
        }

        self::assertSame($expected, $actual);
    }
}
