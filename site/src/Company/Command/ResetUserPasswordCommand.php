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
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

#[AsCommand(
    name: 'app:user:reset-password',
    description: 'Resets a user password by email and displays a new temporary password.',
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
        $this->addArgument('email', InputArgument::REQUIRED, 'User email');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $email = User::normalizeEmail((string) $input->getArgument('email'));
        $escapedEmail = OutputFormatter::escape($email);

        if (false === filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $output->writeln(sprintf('<error>Invalid email format: "%s".</error>', $escapedEmail));

            return Command::FAILURE;
        }

        $user = $this->userRepository->findOneBy(['email' => $email]);
        if (null === $user) {
            $output->writeln(sprintf('<error>User with email "%s" was not found.</error>', $escapedEmail));

            return Command::FAILURE;
        }

        $plainPassword = $this->generatePassword();
        $hashedPassword = $this->passwordHasher->hashPassword($user, $plainPassword);

        $user->setPassword($hashedPassword);
        $this->entityManager->flush();

        $output->writeln(sprintf('<info>Password for user %s has been updated.</info>', $escapedEmail));
        $output->writeln(sprintf('<comment>New temporary password: %s</comment>', OutputFormatter::escape($plainPassword)));

        return Command::SUCCESS;
    }

    private function generatePassword(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(12)), '+/', '-_'), '=');
    }
}
