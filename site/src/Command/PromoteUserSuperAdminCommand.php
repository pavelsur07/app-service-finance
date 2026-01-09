<?php

namespace App\Command;

use App\Repository\UserRepository;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'app:user:promote-super-admin',
    description: 'Повышает роль пользователя до ROLE_SUPER_ADMIN по указанному email.',
)]
final class PromoteUserSuperAdminCommand extends Command
{
    public function __construct(
        private readonly UserRepository $userRepository,
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
        $email = (string) $input->getArgument('email');

        $user = $this->userRepository->findOneBy(['email' => $email]);
        if (null === $user) {
            $output->writeln(sprintf('<error>Пользователь с email "%s" не найден.</error>', $email));

            return Command::FAILURE;
        }

        $roles = $user->getRoles();
        if (\in_array('ROLE_SUPER_ADMIN', $roles, true)) {
            $output->writeln(sprintf('<info>Пользователь %s уже обладает ролью ROLE_SUPER_ADMIN.</info>', $email));

            return Command::SUCCESS;
        }

        $roles[] = 'ROLE_SUPER_ADMIN';
        $user->setRoles(array_values(array_unique($roles)));

        $this->entityManager->flush();

        $output->writeln(sprintf('<info>Роль ROLE_SUPER_ADMIN успешно назначена пользователю %s.</info>', $email));

        return Command::SUCCESS;
    }
}
