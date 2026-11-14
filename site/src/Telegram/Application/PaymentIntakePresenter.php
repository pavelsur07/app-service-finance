<?php

declare(strict_types=1);

namespace App\Telegram\Application;

use App\Cash\Application\DTO\PaymentIntake\PaymentIntakeDraftView;
use Ramsey\Uuid\Uuid;

final readonly class PaymentIntakePresenter
{
    /**
     * @param list<string> $excluded
     * @param array<string, array<string, string>> $names
     *
     * @return list<array<string, mixed>>
     */
    public function present(PaymentIntakeDraftView $draft, string $sessionId, array $excluded, string $accountName, array $names = []): array
    {
        $prefix = 'pi:'.self::encodeId($sessionId).':'.$draft->revision.':';
        $messages = [];
        if ('confirmed' === $draft->status) {
            return [['text' => 'Платежи сохранены. Повторное нажатие не создаст новые операции.']];
        }
        if ('cancelled' === $draft->status) {
            return [['text' => 'Черновик отменён. Операции не созданы.']];
        }
        if (in_array($draft->status, ['pending', 'queued'], true)) {
            return [['text' => 'Сообщение принято. Разбираю платежи; операции появятся только после подтверждения.', 'reply_markup' => ['inline_keyboard' => [[['text' => 'Обновить', 'callback_data' => $prefix.'refresh'], ['text' => 'Отмена', 'callback_data' => $prefix.'cancel']]]]]];
        }
        if ('failed' === $draft->status) {
            $reason = match ($draft->errorCode) {
                'budget_exceeded', 'budget_limit', 'budget' => 'Исчерпан бюджет ИИ.',
                'timeout', 'task_expired' => 'Истекло время разбора.',
                'service_disabled', 'ai_disabled', 'unavailable' => 'Сервис разбора недоступен.',
                default => 'Не удалось разобрать сообщение.',
            };

            return [['text' => $reason.' Платежи не созданы.', 'reply_markup' => ['inline_keyboard' => [[['text' => 'Повторить разбор', 'callback_data' => $prefix.'retry'], ['text' => 'Отмена', 'callback_data' => $prefix.'cancel']]]]]];
        }
        $messages[] = ['text' => sprintf("Черновик\nКасса: %s\n%s", $accountName, implode("\n", $draft->questions))];
        foreach ($draft->items as $index => $item) {
            $id = (string) ($item['id'] ?? '');
            if (true === ($item['excluded'] ?? false) && !in_array($id, $excluded, true)) {
                $excluded[] = $id;
            }
            $lines = [sprintf('Строка %d%s', $index + 1, in_array($id, $excluded, true) ? ' (исключена)' : ''), 'Касса: '.$accountName];
            foreach (['amount' => 'Сумма', 'currency' => 'Валюта', 'direction' => 'Направление', 'date' => 'Дата', 'description' => 'Назначение', 'purpose' => 'Назначение', 'category_id' => 'Статья', 'project_id' => 'Проект', 'cfo_id' => 'ЦФО', 'execution_status' => 'Факт оплаты'] as $key => $label) {
                $value = $item[$key] ?? null;
                if (is_scalar($value)) {
                    $display = match ($key) {
                        'direction' => match ($value) {
                            'inflow' => 'Доход', 'outflow' => 'Расход', default => 'Нужно уточнить',
                        },
                        'execution_status' => match ($value) {
                            'paid' => 'Оплата выполнена', 'planned' => 'Планируется', default => 'Нужно подтвердить факт оплаты',
                        },
                        'category_id', 'project_id', 'cfo_id' => $names[$key][(string) $value] ?? 'Не выбрано или недоступно',
                        default => (string) $value,
                    };
                    $lines[] = $label.': '.$display;
                }
            }
            foreach (($item['warnings'] ?? []) as $warning) {
                if (is_string($warning)) {
                    $lines[] = 'Внимание: '.$warning;
                }
            }
            foreach (($item['questions'] ?? []) as $question) {
                if (is_string($question)) {
                    $lines[] = '? '.$question;
                }
            }
            if ('message_date_default' === ($item['provenance']['date'] ?? null)) {
                $lines[] = 'Дата взята из времени сообщения.';
            }
            if ('system_general_default' === ($item['provenance']['project_id'] ?? null) || 'system_general_default' === ($item['provenance']['cfo_id'] ?? null)) {
                $lines[] = 'Проект и ЦФО подставлены из общих настроек компании.';
            }
            if ('selected_account_default' === ($item['provenance']['currency'] ?? null)) {
                $lines[] = 'Валюта взята из выбранной кассы.';
            }
            $lines[] = 'Подтверждая строку, вы проверили сумму, дату, направление и кассу; платёж со средств компании выполнен.';
            $text = implode("\n", $lines);
            // Splitting is lossless; keyboard targets the same stable row on the last part.
            while (mb_strlen($text) > 3500) {
                $messages[] = ['text' => mb_substr($text, 0, 3500)];
                $text = mb_substr($text, 3500);
            }
            $rowKey = self::encodeId($id);
            $messages[] = ['text' => $text, 'reply_markup' => ['inline_keyboard' => [[
                ['text' => 'Исправить', 'callback_data' => $prefix.'e'.$rowKey],
                ['text' => 'Данные верны, платёж выполнен', 'callback_data' => $prefix.'q'.$rowKey],
                ['text' => in_array($id, $excluded, true) ? 'Включить' : 'Исключить', 'callback_data' => $prefix.(in_array($id, $excluded, true) ? 'i' : 'x').$rowKey],
            ]]]];
        }
        if ([] !== $draft->questions && [] !== $draft->items) {
            $messages[] = ['text' => 'Общие вопросы: '.implode("\n", $draft->questions)."\n".'Нажимая кнопку ниже, вы явно подтверждаете общие данные и что выбранные строки — отдельные платежи, а не итоги или варианты одного платежа. Сначала исправьте суммы и исключите лишние строки.', 'reply_markup' => ['inline_keyboard' => [[['text' => 'Подтверждаю отдельные платежи и общие данные', 'callback_data' => $prefix.'resolve']]]]];
        }
        $messages[] = ['text' => 'Кнопка «Данные верны, платёж выполнен» означает: вы проверили сумму, дату, направление и кассу; платёж действительно выполнен со средств компании. Вопросы по этой строке будут сняты. Проверьте все строки. Для текстовой правки ответьте на карточку; укажите строку и поле. Подтверждение сохраняет только выбранные строки.', 'reply_markup' => ['inline_keyboard' => [[['text' => 'Подтвердить выбранные', 'callback_data' => $prefix.'confirm']], [['text' => 'Обновить', 'callback_data' => $prefix.'refresh'], ['text' => 'Отмена', 'callback_data' => $prefix.'cancel']]]]];

        $chunks = [];
        foreach ($messages as $message) {
            $text = $message['text'];
            while (mb_strlen($text) > 3500) {
                $chunks[] = ['text' => mb_substr($text, 0, 3500)];
                $text = mb_substr($text, 3500);
            }
            $message['text'] = $text;
            $chunks[] = $message;
        }

        return $chunks;
    }

    public static function encodeId(string $uuid): string
    {
        return rtrim(strtr(base64_encode(Uuid::fromString($uuid)->getBytes()), '+/', '-_'), '=');
    }

    public static function decodeId(string $value): string
    {
        $bytes = base64_decode(strtr($value, '-_', '+/').'==', true);
        if (false === $bytes || 16 !== strlen($bytes)) {
            throw new \InvalidArgumentException('Invalid callback identifier.');
        }

        return Uuid::fromBytes($bytes)->toString();
    }
}
