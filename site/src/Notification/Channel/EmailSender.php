<?php

namespace App\Notification\Channel;

use App\Notification\Contract\NotificationSenderInterface;
use App\Notification\Contract\TemplateRendererInterface;
use App\Notification\DTO\EmailMessage;
use App\Notification\DTO\NotificationContext;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Email;

final class EmailSender implements NotificationSenderInterface
{
    public function __construct(
        private TransportInterface $transport,
        private TemplateRendererInterface $renderer,
        private string $defaultFrom,     // из параметров/ENV
        private ?string $defaultReplyTo = null,
    ) {
    }

    public function supports(): string
    {
        return 'email';
    }

    public function send(object $message, NotificationContext $ctx): bool
    {
        if (!$message instanceof EmailMessage) {
            // мягко проигнорируем — не наш тип сообщения
            return false;
        }

        $html = $this->renderer->render($message->htmlTemplate, $message->vars);
        $text = null;

        if ($message->textTemplate) {
            $text = $this->renderer->render($message->textTemplate, $message->vars);
        }

        $email = (new Email())
            ->from($this->defaultFrom)
            ->to($message->to)
            ->subject($message->subject ?? ($message->vars['subject'] ?? ''))
            ->html($html);

        if ($text) {
            $email->text($text);
        }

        $email->replyTo($message->replyTo ?? $this->defaultReplyTo ?? $this->defaultFrom);

        foreach ($message->attachments as $a) {
            $email->attachFromPath($a['path'], $a['name'] ?? null, $a['mime'] ?? null);
        }

        // Простейший антидубль (по желанию): если пришёл idempotencyKey — добавить заголовок
        if ($ctx->idempotencyKey) {
            $email->getHeaders()->addTextHeader('X-Idempotency-Key', $ctx->idempotencyKey);
        }

        // Отправляем через транспорт напрямую, чтобы обойти Messenger и доставить письмо синхронно.
        $this->transport->send($email);

        return true;
    }
}
