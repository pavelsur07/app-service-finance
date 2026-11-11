<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');
$kernel = new App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$em = $container->get(Doctrine\ORM\EntityManagerInterface::class);
$em->getConnection()->fetchOne("SELECT set_config('application_name', ?, false)", [(string) getenv('CASH_RACE_NAME')]);
$tx = $container->get(App\Cash\Repository\Transaction\CashTransactionRepository::class)->findOneByIdAndCompanyId((string) getenv('CASH_RACE_TX'), (string) getenv('CASH_RACE_COMPANY'));
assert($tx instanceof App\Cash\Entity\Transaction\CashTransaction);
// Initialize the collection before waiting, as an HTML form does.
$tx->getSplits()->count();
echo "ready\n";
flush();
try {
    switch (getenv('CASH_RACE_OPERATION')) {
        case 'update':
            $dto = new App\Cash\Application\DTO\CashTransactionDTO();
            $dto->moneyAccountId = (string) $tx->getMoneyAccount()->getId();
            $dto->currency = $tx->getCurrency();
            $dto->direction = $tx->getDirection();
            $dto->occurredAt = $tx->getOccurredAt();
            $dto->cashflowCategoryId = $tx->getCashflowCategory()?->getId();
            $dto->amount = '900';
            $container->get(App\Cash\Application\UpdateCashTransactionAction::class)($tx, $dto);
            break;
        case 'document':
            $container->get(App\Cash\Application\CreateDocumentFromTransactionAction::class)($tx, true);
            break;
        case 'splits':
            $input = new App\Cash\Application\DTO\CashTransactionSplitsInput();
            foreach (['CASH_RACE_A' => '600', 'CASH_RACE_B' => '400'] as $key => $amount) {
                $row = new App\Cash\Application\DTO\CashTransactionSplitInput();
                $row->cashflowCategoryId = (string) getenv($key);
                $row->amount = $amount;
                $input->rows[] = $row;
            }
            $container->get(App\Cash\Application\SaveCashTransactionSplitsAction::class)($tx, $input);
            break;
        default:
            throw new LogicException('Unknown test operation.');
    }
    echo "success\n";
} catch (App\Cash\Exception\CashTransactionMultiSplitException|DomainException) {
    echo "rejected\n";
}
$kernel->shutdown();
