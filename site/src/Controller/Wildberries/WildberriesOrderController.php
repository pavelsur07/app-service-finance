<?php

namespace App\Controller\Wildberries;

use App\Entity\Company;
use App\Entity\User;
use App\Repository\Wildberries\WildberriesSaleRepository;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class WildberriesOrderController extends AbstractController
{
    public function __construct(private LoggerInterface $logger)
    {
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
}
