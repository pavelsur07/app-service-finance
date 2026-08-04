<?php

declare(strict_types=1);

namespace App\Company\Service;

use App\Company\Entity\User;
use App\Company\Exception\InvalidCurrentPasswordException;
use App\Company\Exception\SamePasswordException;
use App\Company\Message\SendPasswordChangedEmailMessage;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

final readonly class PasswordChanger
{
    public function __construct(
        private UserPasswordHasherInterface $userPasswordHasher,
        private EntityManagerInterface $entityManager,
        private MessageBusInterface $bus,
        private LoggerInterface $logger,
    ) {
    }

    public function change(User $user, string $currentPlainPassword, string $newPlainPassword): void
    {
        if (!$this->userPasswordHasher->isPasswordValid($user, $currentPlainPassword)) {
            throw new InvalidCurrentPasswordException();
        }

        if ($currentPlainPassword === $newPlainPassword) {
            throw new SamePasswordException();
        }

        $user->setPassword($this->userPasswordHasher->hashPassword($user, $newPlainPassword));
        $this->entityManager->flush();

        $this->logger->info('User password changed', [
            'userId' => $user->getId(),
        ]);

        try {
            $this->bus->dispatch(new SendPasswordChangedEmailMessage(
                userId: (string) $user->getId(),
                changedAt: new \DateTimeImmutable(),
            ));
        } catch (\Throwable $e) {
            // Уведомление не должно ломать уже завершённую смену пароля
            $this->logger->error('Password changed email dispatch failed', [
                'userId' => $user->getId(),
                'exception' => $e,
            ]);
        }
    }
}
