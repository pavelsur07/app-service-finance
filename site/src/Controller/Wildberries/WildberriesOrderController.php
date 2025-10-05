<?php

namespace App\Controller\Wildberries;

use App\Entity\Company;
use App\Entity\User;
use App\Repository\Wildberries\WildberriesSaleRepository;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\KernelInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfToken;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class WildberriesOrderController extends AbstractController
{
    public function __construct(
        private LoggerInterface $logger,
        private KernelInterface $kernel,
        private CsrfTokenManagerInterface $csrfTokenManager,
    ) {
    }

    #[Route('/wildberries/orders', name: 'wildberries_orders')]
    public function index(Request $request, WildberriesSaleRepository $repository): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User is not authenticated');
        }

        $company = $user->getCompanies()[0] ?? null;
        if (!$company instanceof Company) {
            throw $this->createNotFoundException('Компания не найдена для пользователя');
        }

        $page = max(1, (int) $request->query->get('page', 1));
        $status = $request->query->get('status');
        $orderType = $request->query->get('orderType');
        $fromRaw = $request->query->get('from');
        $toRaw = $request->query->get('to');

        $filters = [];
        if (is_string($status) && '' !== $status) {
            $filters['status'] = $status;
        }
        if (is_string($orderType) && '' !== $orderType) {
            $filters['orderType'] = $orderType;
        }

        $filtersForTemplate = [
            'status' => $status,
            'orderType' => $orderType,
            'from' => $fromRaw,
            'to' => $toRaw,
        ];

        if (is_string($fromRaw) && '' !== $fromRaw) {
            try {
                $filters['from'] = new DateTimeImmutable($fromRaw);
            } catch (\Exception $exception) {
                $this->addFlash('error', sprintf('Некорректная дата начала "%s"', $fromRaw));
                $this->logger->warning('Invalid Wildberries from date', [
                    'value' => $fromRaw,
                    'exception' => $exception->getMessage(),
                    'companyId' => $company->getId(),
                ]);
            }
        }

        if (is_string($toRaw) && '' !== $toRaw) {
            try {
                $filters['to'] = new DateTimeImmutable($toRaw);
            } catch (\Exception $exception) {
                $this->addFlash('error', sprintf('Некорректная дата окончания "%s"', $toRaw));
                $this->logger->warning('Invalid Wildberries to date', [
                    'value' => $toRaw,
                    'exception' => $exception->getMessage(),
                    'companyId' => $company->getId(),
                ]);
            }
        }

        $pagination = $repository->paginateByCompany($company, $page, 50, $filters);

        return $this->render('wildberries/orders/index.html.twig', [
            'pagination' => $pagination,
            'filters' => $filtersForTemplate,
        ]);
    }

    #[Route('/wildberries/orders/update', name: 'wildberries_orders_update', methods: ['POST'])]
    public function update(Request $request): Response
    {
        $user = $this->getUser();
        if (!$user instanceof User) {
            throw $this->createAccessDeniedException('User is not authenticated');
        }

        $company = $user->getCompanies()[0] ?? null;
        if (!$company instanceof Company) {
            throw $this->createNotFoundException('Компания не найдена для пользователя');
        }

        $token = (string) $request->request->get('_token');
        if (!$this->csrfTokenManager->isTokenValid(new CsrfToken('wildberries_orders_update', $token))) {
            throw $this->createAccessDeniedException('Недопустимый CSRF токен');
        }

        $application = new Application($this->kernel);
        $application->setAutoExit(false);

        $input = new ArrayInput([
            'command' => 'app:wildberries:update-orders',
            '--company' => $company->getId(),
        ]);
        $output = new BufferedOutput();

        try {
            $exitCode = $application->run($input, $output);
        } catch (\Throwable $exception) {
            $this->logger->error('Wildberries orders update failed', [
                'companyId' => $company->getId(),
                'exception' => $exception->getMessage(),
            ]);
            $exitCode = Command::FAILURE;
            $output->writeln($exception->getMessage());
        }

        $message = trim($output->fetch());

        if (Command::SUCCESS === $exitCode) {
            $this->addFlash('success', '' !== $message ? $message : 'Заказы Wildberries обновлены');
        } else {
            $this->addFlash('error', '' !== $message ? $message : 'Не удалось обновить заказы Wildberries');
        }

        return $this->redirectToRoute('wildberries_orders');
    }
}
