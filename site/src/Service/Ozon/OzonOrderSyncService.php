<?php

namespace App\Service\Ozon;

use App\Api\Ozon\OzonApiClient;
use App\Entity\Company;
use App\Entity\Ozon\OzonOrder;
use App\Entity\Ozon\OzonOrderItem;
use App\Entity\Ozon\OzonOrderStatusHistory;
use App\Entity\Ozon\OzonSyncCursor;
use App\Repository\Ozon\OzonOrderRepository;
use App\Repository\Ozon\OzonProductRepository;
use App\Repository\Ozon\OzonSyncCursorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;

readonly class OzonOrderSyncService
{
    public function __construct(
        private OzonApiClient $client,
        private EntityManagerInterface $em,
        private OzonOrderRepository $orderRepo,
        private OzonProductRepository $productRepo,
        private OzonSyncCursorRepository $cursorRepo,
    ) {
    }

    /**
     * @return array{orders:int, statusChanges:int}
     */
    public function syncFbs(Company $company, \DateTimeImmutable $since, \DateTimeImmutable $to, array|string|null $status = null, bool $forceDetails = false): array
    {
        $offset = 0;
        $limit = 1000;
        $processed = 0;
        $statusChanges = 0;
        do {
            $data = $this->client->getFbsPostingsList($company, $since, $to, $status, $limit, $offset);
            $postings = $data['result']['postings'] ?? [];
            foreach ($postings as $posting) {
                $order = $this->orderRepo->findOneByCompanyAndPostingNumber($company, $posting['posting_number']) ?? new OzonOrder(Uuid::uuid4()->toString(), $company);
                $order->setPostingNumber($posting['posting_number']);
                $order->setScheme('FBS');
                $order->setWarehouseId($posting['warehouse_id'] ?? null);
                $order->setDeliveryMethodName($posting['delivery_method']['name'] ?? null);
                $order->setPaymentStatus($posting['payment_status'] ?? null);
                $order->setOzonCreatedAt(isset($posting['created_at']) ? new \DateTimeImmutable($posting['created_at']) : null);
                $order->setOzonUpdatedAt(isset($posting['in_process_at']) ? new \DateTimeImmutable($posting['in_process_at']) : null);
                $order->setRaw($posting);
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $order->setUpdatedAt($now);
                $this->em->persist($order);
                $statusUpdatedAt = isset($posting['status_updated_at']) ? new \DateTimeImmutable($posting['status_updated_at']) : $now;
                if ($order->getStatus() !== ($posting['status'] ?? '')) {
                    $order->setStatus($posting['status'] ?? '');
                    $order->setStatusUpdatedAt($statusUpdatedAt);
                    $history = new OzonOrderStatusHistory(Uuid::uuid4()->toString(), $order);
                    $history->setStatus($order->getStatus());
                    $history->setChangedAt($statusUpdatedAt);
                    $history->setRawEvent($posting);
                    $this->em->persist($history);
                    ++$statusChanges;
                } else {
                    $order->setStatusUpdatedAt($statusUpdatedAt);
                }

                $items = $posting['products'] ?? $posting['items'] ?? [];
                if (!$items || $forceDetails) {
                    $details = $this->client->getFbsPosting($company, $posting['posting_number']);
                    $items = $details['result']['products'] ?? $details['result']['items'] ?? [];
                }
                foreach ($items as $item) {
                    $sku = isset($item['sku']) ? (string) $item['sku'] : null;
                    $offerId = $item['offer_id'] ?? null;
                    $orderItem = $this->em->getRepository(OzonOrderItem::class)->findOneBy([
                        'order' => $order,
                        'sku' => $sku,
                        'offerId' => $offerId,
                    ]) ?? new OzonOrderItem(Uuid::uuid4()->toString(), $order);
                    $orderItem->setSku($sku);
                    $orderItem->setOfferId($offerId);
                    $orderItem->setQuantity((int) ($item['quantity'] ?? 0));
                    $orderItem->setPrice((string) ($item['price'] ?? '0'));
                    $product = null;
                    if ($sku) {
                        $product = $this->productRepo->findOneBy(['ozonSku' => $sku, 'company' => $company]);
                    }
                    if (!$product && $offerId) {
                        $product = $this->productRepo->findOneBy(['manufacturerSku' => $offerId, 'company' => $company]);
                    }
                    $orderItem->setProduct($product);
                    $orderItem->setRaw($item);
                    $this->em->persist($orderItem);
                }
                ++$processed;
            }
            $offset += $limit;
        } while (!empty($data['result']['has_next']));

        $cursor = $this->cursorRepo->findOneByCompanyAndScheme($company, 'FBS') ?? new OzonSyncCursor(Uuid::uuid4()->toString(), $company, 'FBS');
        $cursor->setLastSince($since);
        $cursor->setLastTo($to);
        $cursor->setLastRunAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->em->persist($cursor);

        $this->em->flush();

        return ['orders' => $processed, 'statusChanges' => $statusChanges];
    }

    /**
     * @return array{orders:int, statusChanges:int}
     */
    public function syncFbo(Company $company, \DateTimeImmutable $since, \DateTimeImmutable $to, bool $forceDetails = false): array
    {
        $offset = 0;
        $limit = 1000;
        $processed = 0;
        $statusChanges = 0;
        do {
            $data = $this->client->getFboPostingsList($company, $since, $to, $limit, $offset);
            $postings = $data['result']['postings'] ?? [];
            foreach ($postings as $posting) {
                $order = $this->orderRepo->findOneByCompanyAndPostingNumber($company, $posting['posting_number']) ?? new OzonOrder(Uuid::uuid4()->toString(), $company);
                $order->setPostingNumber($posting['posting_number']);
                $order->setScheme('FBO');
                $order->setWarehouseId($posting['warehouse_id'] ?? null);
                $order->setDeliveryMethodName($posting['delivery_method']['name'] ?? null);
                $order->setPaymentStatus($posting['payment_status'] ?? null);
                $order->setOzonCreatedAt(isset($posting['created_at']) ? new \DateTimeImmutable($posting['created_at']) : null);
                $order->setOzonUpdatedAt(isset($posting['in_process_at']) ? new \DateTimeImmutable($posting['in_process_at']) : null);
                $order->setRaw($posting);
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $order->setUpdatedAt($now);
                $this->em->persist($order);
                $statusUpdatedAt = isset($posting['status_updated_at']) ? new \DateTimeImmutable($posting['status_updated_at']) : $now;
                if ($order->getStatus() !== ($posting['status'] ?? '')) {
                    $order->setStatus($posting['status'] ?? '');
                    $order->setStatusUpdatedAt($statusUpdatedAt);
                    $history = new OzonOrderStatusHistory(Uuid::uuid4()->toString(), $order);
                    $history->setStatus($order->getStatus());
                    $history->setChangedAt($statusUpdatedAt);
                    $history->setRawEvent($posting);
                    $this->em->persist($history);
                    ++$statusChanges;
                } else {
                    $order->setStatusUpdatedAt($statusUpdatedAt);
                }

                $items = $posting['products'] ?? [];
                if (!$items || $forceDetails) {
                    $details = $this->client->getFboPosting($company, $posting['posting_number']);
                    $items = $details['result']['products'] ?? [];
                }
                foreach ($items as $item) {
                    $sku = isset($item['sku']) ? (string) $item['sku'] : null;
                    $offerId = $item['offer_id'] ?? null;
                    $orderItem = $this->em->getRepository(OzonOrderItem::class)->findOneBy([
                        'order' => $order,
                        'sku' => $sku,
                        'offerId' => $offerId,
                    ]) ?? new OzonOrderItem(Uuid::uuid4()->toString(), $order);
                    $orderItem->setSku($sku);
                    $orderItem->setOfferId($offerId);
                    $orderItem->setQuantity((int) ($item['quantity'] ?? 0));
                    $orderItem->setPrice((string) ($item['price'] ?? '0'));
                    $product = null;
                    if ($sku) {
                        $product = $this->productRepo->findOneBy(['ozonSku' => $sku, 'company' => $company]);
                    }
                    if (!$product && $offerId) {
                        $product = $this->productRepo->findOneBy(['manufacturerSku' => $offerId, 'company' => $company]);
                    }
                    $orderItem->setProduct($product);
                    $orderItem->setRaw($item);
                    $this->em->persist($orderItem);
                }
                ++$processed;
            }
            $offset += $limit;
        } while (!empty($data['result']['has_next']));

        $cursor = $this->cursorRepo->findOneByCompanyAndScheme($company, 'FBO') ?? new OzonSyncCursor(Uuid::uuid4()->toString(), $company, 'FBO');
        $cursor->setLastSince($since);
        $cursor->setLastTo($to);
        $cursor->setLastRunAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->em->persist($cursor);

        $this->em->flush();

        return ['orders' => $processed, 'statusChanges' => $statusChanges];
    }
}
