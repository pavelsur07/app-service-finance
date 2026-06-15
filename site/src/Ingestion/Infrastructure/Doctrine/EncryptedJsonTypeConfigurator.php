<?php

declare(strict_types=1);

namespace App\Ingestion\Infrastructure\Doctrine;

use App\Ingestion\Domain\Contract\SecretCodec;
use Symfony\Component\Console\ConsoleEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;

final readonly class EncryptedJsonTypeConfigurator implements EventSubscriberInterface
{
    public function __construct(private SecretCodec $secretCodec)
    {
        EncryptedJsonType::setSecretCodec($this->secretCodec);
    }

    public static function getSubscribedEvents(): array
    {
        return [
            ConsoleEvents::COMMAND => 'configure',
            KernelEvents::REQUEST => 'configure',
        ];
    }

    public function configure(): void
    {
        EncryptedJsonType::setSecretCodec($this->secretCodec);
    }
}
