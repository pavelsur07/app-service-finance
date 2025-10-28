<?php

namespace App\Notification\Service;

use App\Notification\Contract\NotificationSenderInterface;
use App\Notification\DTO\NotificationContext;
use Symfony\Component\DependencyInjection\Attribute\TaggedIterator;

final class NotificationRouter
{
    /** @var array<string, NotificationSenderInterface> */
    private array $sendersByChannel;

    /**
     * @param iterable<NotificationSenderInterface> $senders
     */
    public function __construct(
        #[TaggedIterator('app.notification.sender')]
        iterable $senders,
    )
    {
        $map = [];
        foreach ($senders as $sender) {
            $map[$sender->supports()] = $sender;
        }
        $this->sendersByChannel = $map;
    }

    /**
     * @param 'email'|'telegram' $channel
     */
    public function send(string $channel, object $message, ?NotificationContext $ctx = null): bool
    {
        $ctx ??= new NotificationContext();
        $sender = $this->sendersByChannel[$channel] ?? null;
        if (!$sender) {
            return false;
        }
        return $sender->send($message, $ctx);
    }
}
