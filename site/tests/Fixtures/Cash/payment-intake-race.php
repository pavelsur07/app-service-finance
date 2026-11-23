<?php

declare(strict_types=1);

require dirname(__DIR__, 3).'/vendor/autoload.php';
(new Symfony\Component\Dotenv\Dotenv())->bootEnv(dirname(__DIR__, 3).'/.env');
$kernel = new App\Kernel('test', true);
$kernel->boot();
$container = $kernel->getContainer()->get('test.service_container');
$em = $container->get(Doctrine\ORM\EntityManagerInterface::class);
$em->getConnection()->fetchOne("SELECT set_config('application_name', ?, false)", [(string) getenv('INTAKE_RACE_NAME')]);
echo "ready\n";
flush();
$action = $container->get(App\Cash\Application\PaymentIntake\PaymentIntakeAction::class);
try {
    if ('mcp-create' === getenv('INTAKE_RACE_OPERATION')) {
        $draft = $action->createMcpDraft(new App\Cash\Application\DTO\PaymentIntake\CreateMcpCashDraft(
            (string) getenv('INTAKE_COMPANY'), (string) getenv('INTAKE_ACTOR'), (string) getenv('INTAKE_CONNECTION'), 'payment-race',
            (string) getenv('INTAKE_ACCOUNT'), new DateTimeImmutable('today'), App\Cash\Enum\Transaction\CashDirection::OUTFLOW, '100.00',
        ));
        echo json_encode(['status' => $draft->status, 'id' => $draft->draftId], \JSON_THROW_ON_ERROR)."\n";
    } else {
        $result = $action->confirmMcp((string) getenv('INTAKE_COMPANY'), (string) getenv('INTAKE_ACTOR'), (string) getenv('INTAKE_DRAFT'), (int) getenv('INTAKE_REVISION'), true);
        echo json_encode(['status' => 'confirmed', 'ids' => $result->transactionIds], \JSON_THROW_ON_ERROR)."\n";
    }
} catch (DomainException) {
    echo json_encode(['status' => 'rejected'], \JSON_THROW_ON_ERROR)."\n";
}
$kernel->shutdown();
