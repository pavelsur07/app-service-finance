<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Application;

use App\Ingestion\Facade\IngestionFacade;
use App\Marketplace\Enum\MarketplaceType;
use App\MarketplaceAds\Application\DTO\AdRawEntry;
use App\MarketplaceAds\Application\DTO\OzonSearchPromoSyncResult;
use App\MarketplaceAds\Domain\ValueObject\OzonSearchPromoCampaign;
use App\MarketplaceAds\Entity\AdRawDocument;
use App\MarketplaceAds\Enum\AdRawDocumentStatus;
use App\MarketplaceAds\Repository\AdRawDocumentRepository;
use Doctrine\ORM\EntityManagerInterface;
use Webmozart\Assert\Assert;

/**
 * Оплата за заказ Ozon из отчёта Performance по заказам (Ingestion) — в рекламную статистику.
 *
 * На (кабинет, день) — AdRawDocument с source_key `ozon-search-promo:<день>` и payload в nested-форме
 * OzonAdRawDataParser: кампания OzonSearchPromoCampaign, строки — заказы (sku заказанного товара,
 * promoted_sku, spend). Проекция — ProcessAdRawDocumentAction: SKU → листинг, повтор заменяет
 * документы дня. День без строк, у которого документ уже есть, перезаписывается пустым — прежние
 * документы дня удаляются. Неизменённый обработанный день не трогается.
 */
final readonly class SyncOzonSearchPromoSpendAction
{
    public function __construct(
        private IngestionFacade $ingestionFacade,
        private AdRawDocumentRepository $rawDocumentRepository,
        private ProcessAdRawDocumentAction $processAction,
        private EntityManagerInterface $entityManager,
    ) {
    }

    public function __invoke(string $companyId, \DateTimeImmutable $from, \DateTimeImmutable $to): OzonSearchPromoSyncResult
    {
        Assert::uuid($companyId);
        $from = $from->setTime(0, 0);
        $to = $to->setTime(0, 0);
        Assert::true($from <= $to, 'Пустой период.');

        $orders = $this->ingestionFacade->getOzonSearchPromoOrders($companyId, $from, $to);

        $byDay = [];
        $total = '0';
        $mismatched = 0;
        foreach ($orders->rows as $order) {
            $byDay[$order->day][] = [
                // Строка без SKU сохраняется суммой без листинга, а не теряется парсером.
                'sku' => '' === $order->sku ? AdRawEntry::UNALLOCATED_PARENT_SKU : $order->sku,
                'promoted_sku' => $order->promotedSku,
                'spend' => $order->expense,
                'views' => 0,
                'clicks' => 0,
            ];
            $total = bcadd($total, $order->expense, 2);
            if ('' !== $order->promotedSku && $order->promotedSku !== $order->sku) {
                ++$mismatched;
            }
        }

        $days = 0;
        $daysWithUnmapped = 0;
        for ($date = $from; $date <= $to; $date = $date->modify('+1 day')) {
            $day = $date->format('Y-m-d');
            $sourceKey = OzonSearchPromoCampaign::SOURCE_KEY_PREFIX.$day;
            $document = $this->rawDocumentRepository->findBySourceKey($companyId, MarketplaceType::OZON->value, $sourceKey);
            $rows = $byDay[$day] ?? [];
            if ([] === $rows && null === $document) {
                continue;
            }

            $payload = json_encode([
                'campaigns' => [[
                    'campaign_id' => OzonSearchPromoCampaign::CAMPAIGN_ID,
                    'campaign_name' => OzonSearchPromoCampaign::CAMPAIGN_NAME,
                    'rows' => $rows,
                ]],
            ], \JSON_THROW_ON_ERROR | \JSON_UNESCAPED_UNICODE);

            if (null === $document) {
                $document = new AdRawDocument($companyId, MarketplaceType::OZON, $date, $payload, $sourceKey);
                $this->rawDocumentRepository->save($document);
            } elseif ($payload !== $document->getRawPayload() || AdRawDocumentStatus::PROCESSED !== $document->getStatus()) {
                $document->updatePayload($payload);
            } else {
                continue;
            }

            // Сырьё дня сохраняется до проекции: при её сбое день остаётся в DRAFT для повтора.
            $this->entityManager->flush();
            ($this->processAction)($companyId, $document->getId());
            ++$days;
            if (AdRawDocumentStatus::DRAFT === $document->getStatus()) {
                ++$daysWithUnmapped;
            }
        }

        return new OzonSearchPromoSyncResult($days, count($orders->rows), $total, $mismatched, $orders->unreadable, $daysWithUnmapped);
    }
}
