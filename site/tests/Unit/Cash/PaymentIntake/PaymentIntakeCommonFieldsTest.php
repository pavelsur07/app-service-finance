<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\PaymentIntake;

use App\Cash\Application\Service\PaymentIntake\PaymentIntakeCommonFields;
use PHPUnit\Framework\TestCase;

final class PaymentIntakeCommonFieldsTest extends TestCase
{
    public function testTonyCommonReplyParsesDateAndPaidTogether(): void
    {
        $result = PaymentIntakeCommonFields::parse('все оплачены и у всех дата оплаты 14.10.26');
        self::assertSame('2026-10-14', $result['fields']['date']);
        self::assertSame('paid', $result['fields']['execution_status']);
        self::assertArrayNotHasKey('paid_confirmed', $result['fields']);
    }

    public function testUnknownClausesCannotPartiallyApply(): void
    {
        $this->expectException(\DomainException::class);
        PaymentIntakeCommonFields::parse('дата 2026-10-14; удали ещё что-нибудь');
    }

    public function testHeaderDateAndExpenseApplyOnlyAsDefaults(): void
    {
        $output = ['items' => [['date' => null, 'direction' => null, 'questions' => ['Укажите дату.']], ['date' => '2026-10-15', 'direction' => 'inflow', 'questions' => []]], 'questions' => []];
        $result = PaymentIntakeCommonFields::applyHeaders($output, "направление расход\nдата всех платежей ниже 14.10.26\n1. Аренда 100 руб.", []);
        self::assertSame('2026-10-14', $result['items'][0]['date']);
        self::assertSame('outflow', $result['items'][0]['direction']);
        self::assertSame([], $result['items'][0]['questions']);
        self::assertSame('2026-10-15', $result['items'][1]['date']);
        self::assertSame('inflow', $result['items'][1]['direction']);
    }

    public function testImpossibleDateIsRejected(): void
    {
        $this->expectException(\DomainException::class);
        PaymentIntakeCommonFields::parse('дата 31.02.2026');
    }

    public function testResolvingDateKeepsPaidAndPersonalFundingQuestions(): void
    {
        self::assertFalse(PaymentIntakeCommonFields::resolved('Подтвердите факт оплаты и укажите дату.', ['date' => '2026-10-14']));
        self::assertTrue(PaymentIntakeCommonFields::resolved('Подтвердите факт оплаты и укажите дату.', ['date' => '2026-10-14', 'execution_status' => 'paid']));
        self::assertFalse(PaymentIntakeCommonFields::resolved('Оплатила сотрудница: уточните источник денег и компенсацию.', ['execution_status' => 'paid']));
        self::assertFalse(PaymentIntakeCommonFields::resolved('Это отдельные платежи или части одного?', ['date' => '2026-10-14']));
        self::assertFalse(PaymentIntakeCommonFields::resolved('Это расход или перевод между счетами?', ['direction' => 'outflow']));
        self::assertFalse(PaymentIntakeCommonFields::resolved('Это расход, который ещё надо проверить?', ['direction' => 'outflow']));
        self::assertFalse(PaymentIntakeCommonFields::resolved('Уточните: этот расход является авансом?', ['direction' => 'outflow']));
    }

    public function testUnknownHeaderProjectRequiresClarification(): void
    {
        $output = ['items' => [['date' => null, 'direction' => null, 'project_id' => null, 'cfo_id' => null, 'questions' => []], ['project_id' => 'local-project', 'cfo_id' => 'local-center', 'questions' => []]], 'questions' => []];
        $result = PaymentIntakeCommonFields::applyHeaders($output, "проект Неизвестный\n1. Аренда 100 руб.", []);
        self::assertNotEmpty($result['questions']);
        self::assertNull($result['items'][0]['project_id']);
        self::assertSame('local-project', $result['items'][1]['project_id']);
        self::assertSame('local-center', $result['items'][1]['cfo_id']);
        self::assertTrue(PaymentIntakeCommonFields::resolved($result['questions'][0], ['project_id' => 'chosen-project']));
    }
}
