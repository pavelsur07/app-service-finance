<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');
$kernel = new App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$em = $container->get(Doctrine\ORM\EntityManagerInterface::class);
$em->getConnection()->fetchOne("SELECT set_config('application_name', ?, false)", [(string) getenv('CASH_RACE_NAME')]);
$account = $container->get(App\Cash\Repository\Accounts\MoneyAccountRepository::class)->findOneByIdAndCompanyId((string) getenv('CASH_RACE_ACCOUNT'), (string) getenv('CASH_RACE_COMPANY'));
assert($account instanceof App\Cash\Entity\Accounts\MoneyAccount);
echo "ready\n";
flush();
if ('save' === getenv('CASH_RACE_OPERATION')) {
    $account->setOpeningBalance('234.56');
    $container->get(App\Cash\Application\Accounts\SaveMoneyAccountAction::class)($account);
} else {
    $container->get(App\Cash\Service\Accounts\AccountBalanceService::class)->recalculateDailyRange($account->getCompany(), $account, new DateTimeImmutable('today'), new DateTimeImmutable('today'));
}
echo "done\n";
$kernel->shutdown();
