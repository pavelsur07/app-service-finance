<?php

namespace App\Marketplace\Ozon\Service;

use App\Marketplace\Ozon\Adapter\OzonApiClient;
use App\Entity\Company;
use App\Marketplace\Ozon\Entity\OzonOrder;
use App\Marketplace\Ozon\Entity\OzonOrderItem;
use App\Marketplace\Ozon\Entity\OzonOrderStatusHistory;
use App\Marketplace\Ozon\Entity\OzonSyncCursor;
use App\Marketplace\Ozon\Repository\OzonOrderRepository;
use App\Marketplace\Ozon\Repository\OzonProductRepository;
use App\Marketplace\Ozon\Repository\OzonSyncCursorRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Ramsey\Uuid\Uuid;

readonly class OzonOrderSyncService
{
    public function __construct(
        private OzonApiClient $client,
        private EntityManagerInterface $em,
        private OzonOrderRepository $orderRepo,
        private OzonProductRepository $productRepo,
        private OzonSyncCursorRepository $cursorRepo,
        private ?LoggerInterface $logger = null,
    ) {
        $this->logger ??= new NullLogger();
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
                $itemsRepo = $this->em->getRepository(OzonOrderItem::class);
                $existingItems = $itemsRepo->findBy(['order' => $order]);
                $existingByKey = [];
                foreach ($existingItems as $existingItem) {
                    $existingByKey[self::buildItemKey($existingItem->getSku(), $existingItem->getOfferId())] = $existingItem;
                }

                $processedItems = [];
                foreach ($items as $item) {
                    $sku = isset($item['sku']) ? (string) $item['sku'] : null;
                    $offerId = $item['offer_id'] ?? null;
                    $key = self::buildItemKey($sku, $offerId);

                    if (isset($processedItems[$key])) {
                        $orderItem = $processedItems[$key];
                    } else {
                        $orderItem = $existingByKey[$key] ?? new OzonOrderItem(Uuid::uuid4()->toString(), $order);
                        $processedItems[$key] = $orderItem;
                    }

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
     * @return array{orders:int, created:int, updated:int, statusChanges:int}
     */
    public function syncFbo(Company $company, \DateTimeImmutable $since, \DateTimeImmutable $to, bool $forceDetails = false): array
    {
        $limit = 1000;
        $offset = 0;

        $created = 0;
        $updated = 0;
        $statusChanges = 0;

        $itemRepo = $this->em->getRepository(OzonOrderItem::class);

        do {
            $data = $this->client->getFboPostingsList($company, $since, $to, $limit, $offset);

            $rows = $data['result'] ?? [];
            if (!\is_array($rows)) {
                $rows = [];
            }
            if (!$rows) {
                break;
            }

            $debugPostings = [];

            foreach ($rows as $row) {
                if (!\is_array($row)) {
                    continue;
                }

                $postingNumber = (string) ($row['posting_number'] ?? '');
                if ('' === $postingNumber) {
                    continue;
                }

                $debugPostings[] = $postingNumber;

                $order = $this->orderRepo->findOneByCompanyAndPostingNumber($company, $postingNumber);
                $isNew = false;
                if (!$order) {
                    $order = new OzonOrder(Uuid::uuid4()->toString(), $company);
                    $isNew = true;
                }

                $ozonCreatedAt = self::parseTs($row['created_at'] ?? null);
                $ozonUpdatedAt = self::parseTs($row['in_process_at'] ?? null);
                $status = (string) ($row['status'] ?? '');
                $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
                $statusAt = $ozonUpdatedAt ?? $ozonCreatedAt ?? $now;

                $order->setPostingNumber($postingNumber);
                $order->setScheme('FBO');
                $order->setWarehouseId(isset($row['warehouse_id']) ? (string) $row['warehouse_id'] : null);
                $deliveryMethod = $row['delivery_method'] ?? null;
                $deliveryName = null;
                if (\is_array($deliveryMethod) && isset($deliveryMethod['name'])) {
                    $deliveryName = (string) $deliveryMethod['name'];
                }
                $order->setDeliveryMethodName($deliveryName);
                $order->setPaymentStatus(isset($row['payment_status']) ? (string) $row['payment_status'] : null);
                $order->setOzonCreatedAt($ozonCreatedAt);
                $order->setOzonUpdatedAt($ozonUpdatedAt);
                $order->setRaw($row);
                $order->setUpdatedAt($now);

                $previousStatus = $order->getStatus();
                $order->setStatus($status);
                $order->setStatusUpdatedAt($statusAt);

                if ($previousStatus !== $status) {
                    $history = new OzonOrderStatusHistory(Uuid::uuid4()->toString(), $order);
                    $history->setStatus($status);
                    $history->setChangedAt($statusAt);
                    $history->setRawEvent($row);
                    $this->em->persist($history);
                    ++$statusChanges;
                }

                $existingItems = $itemRepo->findBy(['order' => $order]);
                $existingByKey = [];
                foreach ($existingItems as $existingItem) {
                    $existingByKey[self::buildItemKey($existingItem->getSku(), $existingItem->getOfferId())] = $existingItem;
                }

                $items = $row['products'] ?? [];
                if (!\is_array($items)) {
                    $items = [];
                }

                if (!$items && $forceDetails) {
                    $details = $this->client->getFboPosting($company, $postingNumber);
                    $items = $details['result']['products'] ?? [];
                    if (!\is_array($items)) {
                        $items = [];
                    }
                }

                $processedItems = [];
                foreach ($items as $itemRow) {
                    if (!\is_array($itemRow)) {
                        continue;
                    }

                    $sku = isset($itemRow['sku']) ? (string) $itemRow['sku'] : null;
                    $offerId = isset($itemRow['offer_id']) ? (string) $itemRow['offer_id'] : null;

                    $key = self::buildItemKey($sku, $offerId);
                    if (isset($processedItems[$key])) {
                        $item = $processedItems[$key];
                    } else {
                        $item = $existingByKey[$key] ?? new OzonOrderItem(Uuid::uuid4()->toString(), $order);
                        $processedItems[$key] = $item;
                    }

                    $item->setSku($sku);
                    $item->setOfferId($offerId);
                    $item->setQuantity((int) ($itemRow['quantity'] ?? 0));
                    $item->setPrice((string) ($itemRow['price'] ?? '0'));

                    $product = null;
                    if ($sku) {
                        $product = $this->productRepo->findOneBy(['ozonSku' => $sku, 'company' => $company]);
                    }
                    if (!$product && $offerId) {
                        $product = $this->productRepo->findOneBy(['manufacturerSku' => $offerId, 'company' => $company]);
                    }
                    $item->setProduct($product);
                    $item->setRaw($itemRow);

                    $this->em->persist($item);
                }

                foreach ($existingItems as $existingItem) {
                    $key = self::buildItemKey($existingItem->getSku(), $existingItem->getOfferId());
                    if (!isset($processedItems[$key])) {
                        $this->em->remove($existingItem);
                    }
                }

                $this->em->persist($order);

                if ($isNew) {
                    ++$created;
                } else {
                    ++$updated;
                }
            }

            $this->em->flush();

            $this->logger->info('Ozon FBO page processed', [
                'company_id' => $company->getId(),
                'offset' => $offset,
                'limit' => $limit,
                'count' => \count($rows),
                'posting_numbers_sample' => \array_slice($debugPostings, 0, 5),
            ]);

            $offset += $limit;
        } while (\count($rows) === $limit);

        $cursor = $this->cursorRepo->findOneByCompanyAndScheme($company, 'FBO') ?? new OzonSyncCursor(Uuid::uuid4()->toString(), $company, 'FBO');
        $cursor->setLastSince($since);
        $cursor->setLastTo($to);
        $cursor->setLastRunAt(new \DateTimeImmutable('now', new \DateTimeZone('UTC')));
        $this->em->persist($cursor);

        $this->em->flush();

        return [
            'orders' => $created + $updated,
            'created' => $created,
            'updated' => $updated,
            'statusChanges' => $statusChanges,
        ];
    }

    private static function parseTs(?string $ts): ?\DateTimeImmutable
    {
        if (!$ts) {
            return null;
        }

        try {
            return new \DateTimeImmutable($ts);
        } catch (\Throwable) {
            return null;
        }
    }

    private static function buildItemKey(?string $sku, ?string $offerId): string
    {
        return serialize([$sku, $offerId]);
    }
}
