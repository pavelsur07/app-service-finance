<?php

declare(strict_types=1);

namespace App\Telegram\Application;

use App\Cash\Application\DTO\PaymentIntake\PaymentIntakeDraftView;
use Ramsey\Uuid\Uuid;

final readonly class PaymentIntakePresenter
{
    /** @return list<array<string, mixed>> */
    public function summary(PaymentIntakeDraftView $draft, string $sessionId, string $accountName): array
    {
        if (in_array($draft->status, ['pending', 'queued', 'failed', 'confirmed', 'cancelled'], true)) {
            return $this->present($draft, $sessionId, [], $accountName);
        }
        $messages = [];
        if ([] !== $draft->questions) {
            $header = 'Черновик · версия '.$draft->revision."\n";
            $text = "Общие вопросы — ответьте на это сообщение:\n".implode("\n", $draft->questions)."\nПример: дата 14.10.2026; проект Название; отдельные платежи";
            $limit = 3500 - mb_strlen($header);
            while ('' !== $text) {
                $messages[] = ['text' => $header.mb_substr($text, 0, $limit)];
                $text = mb_substr($text, $limit);
            }
        }
        $messages[] = $this->listPage($draft, $sessionId, $accountName);

        return $messages;
    }

    /** @return array<string, mixed> */
    public function listPage(PaymentIntakeDraftView $draft, string $sessionId, string $accountName, int $offset = 0): array
    {
        if ($offset < 0 || ($offset >= count($draft->items) && 0 !== $offset)) {
            throw new \DomainException('Страница черновика недоступна.');
        }
        $prefix = 'pi:'.self::encodeId($sessionId).':'.$draft->revision.':';
        $totals = [];
        $active = 0;
        foreach ($draft->items as $item) {
            if ($item['excluded'] ?? false) {
                continue;
            }
            ++$active;
            if (is_string($item['amount'] ?? null) && preg_match('/^[0-9]{1,16}(?:\.\d{1,2})?$/D', $item['amount']) && in_array($item['direction'] ?? null, ['inflow', 'outflow'], true) && is_string($item['currency'] ?? null)) {
                $key = ('inflow' === $item['direction'] ? 'Приход' : 'Расход').' '.$item['currency'];
                $totals[$key] = bcadd($totals[$key] ?? '0.00', $item['amount'], 2);
            }
        }
        $lines = ['Черновик · версия '.$draft->revision, 'Касса: '.$accountName, 'Выбрано строк: '.$active.' / '.count($draft->items)];
        $pageCurrencies = array_column(array_slice($draft->items, $offset, 5), 'currency');
        foreach ($totals as $key => $amount) {
            [$direction, $currency] = explode(' ', $key, 2);
            if (!in_array($currency, $pageCurrencies, true)) {
                continue;
            }
            $lines[] = $direction.': '.$amount.' '.$currency.' (только строки с известной суммой и направлением)';
        }
        $buttons = [];
        foreach (array_slice($draft->items, $offset, 5) as $index => $item) {
            $number = $offset + $index + 1;
            $label = 'Строка '.$number.': '.($item['amount'] ?? '?').' '.($item['currency'] ?? '?');
            $lines[] = $label.($item['excluded'] ?? false ? ' · исключена' : '').' · '.($item['date'] ?? 'дата ?')."\n".mb_substr((string) ($item['description'] ?? ''), 0, 80)."\n".'Вопросов: '.count($item['questions'] ?? []).'; оплата '.(($item['paid_confirmed'] ?? false) ? 'подтверждена' : 'не подтверждена');
            $buttons[] = [['text' => $label, 'callback_data' => $prefix.'r'.self::encodeId($item['id']).'.0']];
        }
        $navigation = [];
        if ($offset > 0) {
            $navigation[] = ['text' => '← Назад', 'callback_data' => $prefix.'list'.max(0, $offset - 5)];
        }
        if ($offset + 5 < count($draft->items)) {
            $navigation[] = ['text' => 'Далее →', 'callback_data' => $prefix.'list'.($offset + 5)];
        }
        if ([] !== $navigation) {
            $buttons[] = $navigation;
        }
        $lines[] = 'Откройте строку для проверки статьи, проекта и ЦФО. Общую дату или проект можно изменить ответом на сводку. «Все оплачены» подтверждает факт, источник средств проверяется отдельно.';
        $lines[] = '«Подтвердить оплату» подтверждает оплату со средств компании для строк без нерешённых вопросов. Неизвестные финансовые вопросы сохраняются: откройте строку и разрешите их отдельно.';
        if ([] !== $draft->questions) {
            $lines[] = '«Общие данные верны» подтверждает ответы на общие вопросы и что выбранные строки — отдельные платежи. Ошибочные реквизиты сначала исправьте ответом на сводку.';
            $buttons[] = [['text' => 'Общие данные верны', 'callback_data' => $prefix.'resolve']];
        }
        $buttons[] = [['text' => 'Подтвердить оплату', 'callback_data' => $prefix.'approve']];
        $buttons[] = [['text' => 'Сохранить выбранные', 'callback_data' => $prefix.'confirm']];
        $buttons[] = [['text' => 'Обновить', 'callback_data' => $prefix.'refresh'], ['text' => 'Отменить разбор', 'callback_data' => $prefix.'cancel']];

        return ['text' => implode("\n\n", $lines), 'reply_markup' => ['inline_keyboard' => $buttons]];
    }

    /**
     * @param array<string, array<string, string>> $names
     *
     * @return array<string, mixed>
     */
    public function details(PaymentIntakeDraftView $draft, string $sessionId, string $itemId, string $accountName, array $names, int $page = 0): array
    {
        $items = array_values(array_filter($draft->items, static fn (array $item): bool => $item['id'] === $itemId));
        if (1 !== count($items)) {
            throw new \DomainException('Строка недоступна.');
        }
        $view = new PaymentIntakeDraftView($draft->id, $draft->companyId, $draft->actorUserId, $draft->moneyAccountId, $draft->revision, $draft->status, $draft->taskId, $items, [], $draft->errorCode);
        $messages = array_slice($this->present($view, $sessionId, [], $accountName, $names), 1, -1);
        if (!isset($messages[$page])) {
            throw new \DomainException('Страница строки недоступна.');
        }
        $payload = $messages[$page];
        if (0 === $page) {
            $number = array_search($itemId, array_column($draft->items, 'id'), true) + 1;
            $payload['text'] = preg_replace('/^Строка 1/u', 'Строка '.$number, $payload['text']) ?? $payload['text'];
        }
        $prefix = 'pi:'.self::encodeId($sessionId).':'.$draft->revision.':';
        $navigation = [];
        foreach ([$page - 1 => '← Назад', $page + 1 => 'Далее →'] as $next => $label) {
            if (isset($messages[$next])) {
                $navigation[] = ['text' => $label, 'callback_data' => $prefix.'r'.self::encodeId($itemId).'.'.$next];
            }
        }
        if ([] !== $navigation) {
            $payload['reply_markup']['inline_keyboard'][] = $navigation;
        }
        $payload['reply_markup']['inline_keyboard'][] = [['text' => 'К сводке', 'callback_data' => $prefix.'list0']];

        return $payload;
    }

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
            $messages[] = ['text' => $text, 'reply_markup' => ['inline_keyboard' => [
                [['text' => 'Проверено и оплачено', 'callback_data' => $prefix.'q'.$rowKey]],
                [['text' => 'Исправить', 'callback_data' => $prefix.'e'.$rowKey], ['text' => in_array($id, $excluded, true) ? 'Включить' : 'Исключить', 'callback_data' => $prefix.(in_array($id, $excluded, true) ? 'i' : 'x').$rowKey]],
            ]]];
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
