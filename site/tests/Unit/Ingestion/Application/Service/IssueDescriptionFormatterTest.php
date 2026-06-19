<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ingestion\Application\Service;

use App\Ingestion\Application\Service\IssueDescriptionFormatter;
use App\Ingestion\Enum\NormalizationIssueKind;
use PHPUnit\Framework\TestCase;

final class IssueDescriptionFormatterTest extends TestCase
{
    /**
     * @return iterable<string, array{0: NormalizationIssueKind, 1: string}>
     */
    public static function issueDescriptions(): iterable
    {
        yield 'sum mismatch' => [
            NormalizationIssueKind::SUM_MISMATCH,
            'Сумма операций не сходится с контрольной суммой источника',
        ];
        yield 'mapper failure' => [
            NormalizationIssueKind::MAPPER_FAILURE,
            'Не удалось распознать операцию',
        ];
        yield 'unknown field' => [
            NormalizationIssueKind::UNKNOWN_FIELD,
            'В отчёте появилось неизвестное поле',
        ];
        yield 'currency mismatch' => [
            NormalizationIssueKind::CURRENCY_MISMATCH,
            'В операции смешаны разные валюты',
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('issueDescriptions')]
    public function testHumanizesEveryIssueKind(NormalizationIssueKind $kind, string $expected): void
    {
        $formatter = new IssueDescriptionFormatter();

        self::assertSame($expected, $formatter->humanize($kind, ['raw' => 'hidden']));
    }
}
