<?php

namespace App\Controller\Ozon;

use App\Marketplace\Ozon\Adapter\OzonApiClient;
use App\Marketplace\Ozon\Entity\OzonOrder;
use App\Marketplace\Ozon\Entity\OzonOrderItem;
use App\Marketplace\Ozon\Repository\OzonOrderRepository;
use App\Marketplace\Ozon\Repository\OzonOrderStatusHistoryRepository;
use App\Marketplace\Ozon\Service\OzonOrderSyncService;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class OzonOrderController extends AbstractController
{
    public function __construct(private LoggerInterface $logger)
    {
    }

    #[Route('/ozon/orders', name: 'ozon_orders')]
    public function index(Request $request, OzonOrderRepository $repo): Response
    {
        $company = $this->getUser()->getCompanies()[0];
        $scheme = $request->query->get('scheme');
        $status = $request->query->get('status');
        $from = $request->query->get('from');
        $to = $request->query->get('to');

        $qb = $repo->createQueryBuilder('o')
            ->andWhere('o.company = :company')
            ->setParameter('company', $company)
            ->orderBy('o.statusUpdatedAt', 'DESC')
            ->setMaxResults(100);
        if ($scheme) {
            $qb->andWhere('o.scheme = :scheme')->setParameter('scheme', $scheme);
        }
        if ($status) {
            $qb->andWhere('o.status = :status')->setParameter('status', $status);
        }
        if ($from) {
            $qb->andWhere('o.statusUpdatedAt >= :from')->setParameter('from', new \DateTimeImmutable($from));
        }
        if ($to) {
            $qb->andWhere('o.statusUpdatedAt <= :to')->setParameter('to', new \DateTimeImmutable($to));
        }
        $orders = $qb->getQuery()->getResult();

        return $this->render('ozon/orders/index.html.twig', [
            'orders' => $orders,
        ]);
    }

    #[Route('/ozon/orders/sync', name: 'ozon_orders_sync')]
    public function sync(Request $request, OzonOrderSyncService $syncService): Response
    {
        $company = $this->getUser()->getCompanies()[0];
        $to = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $since = $to->sub(new \DateInterval('P3D'));
        $statusParam = (string) $request->query->get('status');
        $status = $statusParam
            ? (\str_contains($statusParam, ',') ? array_map('trim', explode(',', $statusParam)) : $statusParam)
            : null;

        $rFbs = $syncService->syncFbs($company, $since, $to, $status);
        $rFbo = $syncService->syncFbo($company, $since, $to);

        $totalOrders = (int) ($rFbs['orders'] ?? 0) + (int) ($rFbo['orders'] ?? 0);
        $totalStatus = (int) ($rFbs['statusChanges'] ?? 0) + (int) ($rFbo['statusChanges'] ?? 0);

        $this->addFlash('success', sprintf(
            'Ozon: обработано заказов %d (FBS+FBO), новых изменений статуса %d.',
            $totalOrders,
            $totalStatus
        ));

        $this->logger->info('Ozon sync done', [
            'company_id' => $company->getId(),
            'since' => $since?->format('c'),
            'to' => $to?->format('c'),
            'fbs' => $rFbs,
            'fbo' => $rFbo,
            'total_orders' => $totalOrders,
            'total_status_changes' => $totalStatus,
        ]);

        return $this->redirectToRoute('ozon_orders');
    }

    #[Route('/ozon/orders/api', name: 'ozon_orders_api')]
    public function api(OzonApiClient $client): JsonResponse
    {
        $company = $this->getUser()->getCompanies()[0];
        $to = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $since = $to->sub(new \DateInterval('P3D'));

        $fbs = $client->getFbsPostingsList($company, $since, $to);
        $fbo = $client->getFboPostingsList($company, $since, $to);

        return $this->json([
            'fbs' => $fbs,
            'fbo' => $fbo,
        ]);
    }

    #[Route('/ozon/orders/{id}', name: 'ozon_order_show')]
    public function show(OzonOrder $order, EntityManagerInterface $em, OzonOrderStatusHistoryRepository $historyRepo): Response
    {
        $items = $em->getRepository(OzonOrderItem::class)->findBy(['order' => $order]);
        $history = $historyRepo->findBy(['order' => $order], ['changedAt' => 'ASC']);

        return $this->render('ozon/orders/show.html.twig', [
            'order' => $order,
            'items' => $items,
            'history' => $history,
        ]);
    }
}
