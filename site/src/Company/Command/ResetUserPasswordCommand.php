<?php

declare(strict_types=1);

namespace App\Company\Command;

use App\Company\Entity\User;
use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:reset-password',
    description: 'Сбрасывает пароль пользователя по email и выводит новый временный пароль.',
)]
final class ResetUserPasswordCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
        private readonly UserPasswordHasherInterface $passwordHasher,
        private readonly EntityManagerInterface $entityManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->addArgument('email', InputArgument::REQUIRED, 'Email пользователя');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = User::normalizeEmail((string) $input->getArgument('email'));

        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $output->writeln(sprintf('<error>Некорректный формат email: "%s".</error>', $email));

            return Command::FAILURE;
        }

        $user = $this->userRepository->findOneBy(['email' => $email]);
        if (null === $user) {
            $output->writeln(sprintf('<error>Пользователь с email "%s" не найден.</error>', $email));

            return Command::FAILURE;
        }

        $plainPassword = $this->generatePassword();
        $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);

        $user->setPassword($hashedPassword);
        $this->entityManager->flush();

        $output->writeln(sprintf('<info>Пароль пользователя %s успешно обновлён.</info>', $email));
        $output->writeln(sprintf('<comment>Новый временный пароль: %s</comment>', $plainPassword));

        return Command::SUCCESS;
    }

    private function generatePassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
    }
}
