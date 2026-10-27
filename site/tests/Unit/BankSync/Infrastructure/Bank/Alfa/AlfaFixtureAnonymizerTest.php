<?php

declare(strict_types=1);

namespace App\Tests\Unit\BankSync\Infrastructure\Bank\Alfa;

use App\BankSync\Infrastructure\Bank\Alfa\AlfaFixtureAnonymizer;
use PHPUnit\Framework\TestCase;

final class AlfaFixtureAnonymizerTest extends TestCase
{
    public function testPersonalDataIsReplacedAndStructureKept(): void
    {
        $response = [
            'transactions' => [[
                'uuid' => '1f0b6b3c-0000-4000-8000-000000000001',
                'transactionId' => '1234567890',
                'direction' => 'CREDIT',
                'operationDate' => '2026-10-01T00:00:00Z',
                'amount' => ['amount' => 1500.25, 'currencyName' => 'RUR'],
                'paymentPurpose' => 'Оплата по счёту 15 от ООО «Ромашка», ИНН 7701234567',
                'rurTransfer' => [
                    'payerAccount' => '40702810900000000001',
                    'payerName' => 'Иванов Иван Иванович',
                    'payerInn' => '770123456789',
                ],
            ]],
            'accounts' => [['identification' => '40702810900000000001']],
        ];

        $result = new AlfaFixtureAnonymizer()->anonymize($response, 'salt');
        $line = $result['transactions'][0];
        $raw = json_encode($result, \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

        foreach (['40702810900000000001', 'Иванов', 'Ромашка', '7701234567', '770123456789'] as $secret) {
            self::assertStringNotContainsString($secret, $raw);
        }
        self::assertSame(['1f0b6b3c-0000-4000-8000-000000000001', '1234567890', 'CREDIT', '2026-10-01T00:00:00Z'], [$line['uuid'], $line['transactionId'], $line['direction'], $line['operationDate']]);
        self::assertSame(['amount' => 1500.25, 'currencyName' => 'RUR'], $line['amount']);
        self::assertMatchesRegularExpression('/^\d{20}$/', $line['rurTransfer']['payerAccount'], 'номер остаётся номером той же длины');
        self::assertSame($line['rurTransfer']['payerAccount'], $result['accounts'][0]['identification'], 'одно значение — одна замена во всех ответах');
        self::assertStringStartsWith('anon-', $line['paymentPurpose']);
    }

    public function testAmountsAndDatesAsStringsAreKept(): void
    {
        $response = ['amount' => '100.25', 'amountRub' => '7000.00', 'instructedAmount' => 'USD70,00', 'receiptDate' => '2018-12-31', 'payerName' => 'Иванов'];

        $result = new AlfaFixtureAnonymizer()->anonymize($response, 'salt');

        self::assertSame(['100.25', '7000.00', 'USD70,00', '2018-12-31'], [$result['amount'], $result['amountRub'], $result['instructedAmount'], $result['receiptDate']]);
        self::assertStringStartsWith('anon-', $result['payerName']);
    }

    public function testDifferentSaltGivesDifferentReplacement(): void
    {
        $response = ['payerName' => 'Иванов'];

        self::assertNotSame(new AlfaFixtureAnonymizer()->anonymize($response, 'a'), new AlfaFixtureAnonymizer()->anonymize($response, 'b'));
    }
}
