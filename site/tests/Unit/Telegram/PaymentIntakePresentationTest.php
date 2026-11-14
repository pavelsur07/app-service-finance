<?php

declare(strict_types=1);

namespace App\Tests\Unit\Telegram;

use App\Cash\Application\DTO\PaymentIntake\PaymentIntakeDraftView;
use App\Telegram\Application\PaymentIntakePresenter;
use PHPUnit\Framework\TestCase;

final class PaymentIntakePresentationTest extends TestCase
{
    public function testAllRowsAndQuestionsArePresentedAsPlainTextWithCompactCallbacks(): void
    {
        $rows = [];
        for ($i = 0; $i < 32; ++$i) {
            $rows[] = ['id' => sprintf('11111111-1111-1111-1111-%012d', $i), 'amount' => '100.00', 'direction' => 'outflow', 'date' => '2026-11-13', 'description' => '<b>row '.$i.'</b>', 'questions' => ['Какой проект?'], 'category_id' => 'aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', 'provenance' => ['date' => 'message_date_default'], 'warnings' => ['Возможен повтор платежа.']];
        }
        $view = new PaymentIntakeDraftView('11111111-1111-1111-1111-111111111111', '22222222-2222-2222-2222-222222222222', '33333333-3333-3333-3333-333333333333', '44444444-4444-4444-4444-444444444444', 3, 'needs_clarification', null, $rows, ['Подтвердите оплату'], null);
        $messages = (new PaymentIntakePresenter())->present($view, '11111111-1111-1111-1111-111111111111', [], 'Касса', ['category_id' => ['aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa' => 'Реклама']]);
        $text = implode("\n", array_column($messages, 'text'));
        self::assertStringContainsString('<b>row 31</b>', $text);
        self::assertStringContainsString('Какой проект?', $text);
        self::assertStringContainsString('Подтвердите оплату', $text);
        self::assertStringContainsString('Статья: Реклама', $text);
        self::assertStringContainsString('Направление: Расход', $text);
        self::assertStringContainsString('Внимание: Возможен повтор платежа.', $text);
        self::assertStringContainsString('Дата взята из времени сообщения.', $text);
        self::assertStringNotContainsString('aaaaaaaa-aaaa-aaaa-aaaa-aaaaaaaaaaaa', $text);
        self::assertStringNotContainsString('message_date_default', $text);
        foreach ($messages as $message) {
            self::assertArrayNotHasKey('parse_mode', $message);
            self::assertLessThanOrEqual(4096, mb_strlen($message['text']));
            foreach ($message['reply_markup']['inline_keyboard'] ?? [] as $buttons) {
                foreach ($buttons as $button) {
                    self::assertLessThanOrEqual(64, strlen($button['callback_data']));
                }
            }
        }
    }
}
