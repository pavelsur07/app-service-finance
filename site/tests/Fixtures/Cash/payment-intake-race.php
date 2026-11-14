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
    if ('amend' === getenv('INTAKE_RACE_OPERATION')) {
        $draft = $action->amend(new App\Cash\Application\DTO\PaymentIntake\AmendPaymentIntakeDraftCommand((string) getenv('INTAKE_COMPANY'), (string) getenv('INTAKE_ACTOR'), (string) getenv('INTAKE_DRAFT'), (int) getenv('INTAKE_REVISION'), [(string) getenv('INTAKE_ITEM') => ['amount' => '200.00']]));
        echo json_encode(['status' => 'amended', 'revision' => $draft->revision], \JSON_THROW_ON_ERROR)."\n";
    } else {
        $result = $action->confirm(new App\Cash\Application\DTO\PaymentIntake\ConfirmPaymentIntakeDraftCommand((string) getenv('INTAKE_COMPANY'), (string) getenv('INTAKE_ACTOR'), (string) getenv('INTAKE_DRAFT'), (int) getenv('INTAKE_REVISION'), [(string) getenv('INTAKE_ITEM')]));
        echo json_encode(['status' => 'confirmed', 'ids' => $result->transactionIds], \JSON_THROW_ON_ERROR)."\n";
    }
} catch (DomainException) {
    echo json_encode(['status' => 'rejected'], \JSON_THROW_ON_ERROR)."\n";
}
$kernel->shutdown();
