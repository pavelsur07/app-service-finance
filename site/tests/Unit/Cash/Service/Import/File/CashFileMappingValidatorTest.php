<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Service\Import\File;

use App\Cash\Exception\InvalidCashFileMappingException;
use App\Cash\Service\Import\File\CashFileMappingValidator;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CashFileMappingValidatorTest extends TestCase
{
    /** @param array<array-key, mixed> $mapping */
    #[DataProvider('mappings')]
    public function testStructureAllowsDraftsAndRejectsInvalidValues(array $mapping, bool $valid): void
    {
        if (!$valid) {
            $this->expectException(InvalidCashFileMappingException::class);
        }
        (new CashFileMappingValidator())->validate($mapping);
        self::assertTrue($valid);
    }

    /** @param array<array-key, mixed> $mapping */
    #[DataProvider('importMappings')]
    public function testImportRequiresCompleteMapping(array $mapping, bool $valid): void
    {
        if (!$valid) {
            $this->expectException(InvalidCashFileMappingException::class);
        }
        (new CashFileMappingValidator())->validateForImport($mapping);
        self::assertTrue($valid);
    }

    /** @return iterable<array{array<array-key, mixed>, bool}> */
    public static function importMappings(): iterable
    {
        yield 'amount' => [['date' => 'Date', 'amount' => 'Amount'], true];
        yield 'pair' => [['date' => 'Date', 'inflow' => 'In', 'outflow' => 'Out'], true];
        yield 'pair plus amount' => [['date' => 'Date', 'amount' => 'Amount', 'inflow' => 'In', 'outflow' => 'Out'], true];
        yield 'draft' => [[], false];
        yield 'no amount' => [['date' => 'Date'], false];
        yield 'inflow only' => [['date' => 'Date', 'inflow' => 'In'], true];
        yield 'outflow only' => [['date' => 'Date', 'outflow' => 'Out'], true];
        yield 'inflow plus amount' => [['date' => 'Date', 'inflow' => 'In', 'amount' => 'Amount'], true];
        yield 'outflow plus amount' => [['date' => 'Date', 'outflow' => 'Out', 'amount' => 'Amount'], true];
        yield 'missing date' => [['amount' => 'Amount'], false];
    }

    /** @return iterable<array{array<array-key, mixed>, bool}> */
    public static function mappings(): iterable
    {
        yield 'empty draft' => [[], true];
        yield 'partial draft' => [['description' => 'Purpose', 'date' => null], true];
        yield 'all fields' => [['date' => 'Date', 'amount' => 'Amount', 'inflow' => null, 'outflow' => null, 'counterparty' => 'Party', 'description' => 'Purpose', 'currency' => 'Currency', 'doc_number' => 'Number'], true];
        yield 'nested' => [['date' => []], false];
        yield 'object' => [['date' => new \stdClass()], false];
        yield 'bool' => [['date' => false], false];
        yield 'float' => [['amount' => 1.25], false];
        yield 'integer' => [['date' => 1], false];
        yield 'unknown' => [['other' => 'Date'], false];
        yield 'list' => [['Date'], false];
    }
}
