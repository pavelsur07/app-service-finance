<?php

namespace App\Controller\Company;

use App\Service\ActiveCompanyService;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_USER')]
#[Route('/company/wb/tools', name: 'company_wb_tools_')]
final class WildberriesToolsController extends AbstractController
{
    public function __construct(
        private readonly ActiveCompanyService $activeCompanyService,
        private readonly CsrfTokenManagerInterface $csrf,
        private readonly KernelInterface $kernel,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route('', name: 'index', methods: ['GET'])]
    public function index(): Response
    {
        $company = $this->activeCompanyService->getActiveCompany();

        return $this->render('company/wb/tools.html.twig', [
            'company' => $company,
            'csrf_finance' => $this->csrf->getToken('wb_finance_run')->getValue(),
            'csrf_sales' => $this->csrf->getToken('wb_sales_run')->getValue(),
        ]);
    }

    #[Route('/run/finance', name: 'run_finance', methods: ['POST'])]
    public function runFinance(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $token = new CsrfToken('wb_finance_run', (string) $request->request->get('_token'));
        if (!$this->csrf->isTokenValid($token)) {
            $this->addFlash('danger', 'Неверный CSRF токен для запуска финансовых отчётов WB.');

            return $this->redirectToRoute('company_wb_tools_index');
        }

        $company = $this->activeCompanyService->getActiveCompany();

        $dateTo = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))->setTime(0, 0);
        $dateFrom = $dateTo->sub(new \DateInterval('P59D'));

        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        $input = new ArrayInput([
            'command' => 'wb:finance:import',
            '--companyId' => (string) $company->getId(),
            '--dateFrom' => $dateFrom->format('Y-m-d'),
            '--dateTo' => $dateTo->format('Y-m-d'),
        ]);
        $output = new BufferedOutput();

        try {
            $exitCode = $application->run($input, $output);
        } catch (\Throwable $exception) {
            $this->logger->error('Wildberries finance sync failed', [
                'companyId' => $company->getId(),
                'exception' => $exception->getMessage(),
            ]);
            $exitCode = Command::FAILURE;
            $output->writeln($exception->getMessage());
        }

        $message = trim($output->fetch());

        if (Command::SUCCESS === $exitCode) {
            $this->addFlash('success', '' !== $message ? $message : sprintf(
                'Запущена задача: Финансовые отчёты WB для компании «%s».',
                (string) $company->getName()
            ));
        } else {
            $this->addFlash('danger', '' !== $message ? $message : sprintf(
                'Не удалось запустить загрузку фин. отчётов WB для компании «%s».',
                (string) $company->getName()
            ));
        }

        return $this->redirectToRoute('company_wb_tools_index');
    }

    #[Route('/run/sales', name: 'run_sales', methods: ['POST'])]
    public function runSales(Request $request): RedirectResponse
    {
        $this->denyAccessUnlessGranted('ROLE_USER');

        $token = new CsrfToken('wb_sales_run', (string) $request->request->get('_token'));
        if (!$this->csrf->isTokenValid($token)) {
            $this->addFlash('danger', 'Неверный CSRF токен для запуска продаж WB.');

            return $this->redirectToRoute('company_wb_tools_index');
        }

        $company = $this->activeCompanyService->getActiveCompany();

        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        $input = new ArrayInput([
            'command' => 'app:wildberries:sync-sales',
            '--company' => $company->getId(),
        ]);
        $output = new BufferedOutput();

        try {
            $exitCode = $application->run($input, $output);
        } catch (\Throwable $exception) {
            $this->logger->error('Wildberries sales sync failed', [
                'companyId' => $company->getId(),
                'exception' => $exception->getMessage(),
            ]);
            $exitCode = Command::FAILURE;
            $output->writeln($exception->getMessage());
        }

        $message = trim($output->fetch());

        if (Command::SUCCESS === $exitCode) {
            $this->addFlash('success', '' !== $message ? $message : sprintf(
                'Запущена задача: Продажи WB для компании «%s».',
                (string) $company->getName()
            ));
        } else {
            $this->addFlash('danger', '' !== $message ? $message : sprintf(
                'Не удалось запустить загрузку продаж WB для компании «%s».',
                (string) $company->getName()
            ));
        }

        return $this->redirectToRoute('company_wb_tools_index');
    }
}
