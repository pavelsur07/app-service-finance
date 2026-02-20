<?php

namespace App\Admin\Controller;

use App\Shared\Service\AppLogger;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;


#[Route('/admin', name: 'admin_')]
final class DashboardController extends AbstractController
{
    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly AppLogger $appLogger,
    ) {
    }

    #[Route('/_debug/log-test', name: 'debug_log_test', methods: ['GET'])]
    public function logTest(): Response
    {
        // Информационный лог
        $this->logger->info('Test INFO log for GlitchTip');

        // Ошибка
        $this->logger->error('Test ERROR ERROR log for GlitchTip My');

        $startTime = microtime(true);

        // ИМИТИРУЕМ долгую работу кода (останавливаем скрипт на 4 секунды)
        sleep(4);

        $duration = (int) ((microtime(true) - $startTime) * 1000);

        // Теперь duration будет ~4000 мс, и условие сработает!
        if ($duration > 3000) {
            $this->appLogger->logSlowExecution('CashflowReportBuilder::build', $duration, 3000);
        }

        return $this->redirect('dashboard');
    }

    #[Route('', name: 'dashboard', methods: ['GET'])]
    public function index(): Response
    {
        return $this->render('admin/dashboard/index.html.twig', [
            'title' => 'Admin · Dashboard (тестовая страница)',
        ]);
    }
}
