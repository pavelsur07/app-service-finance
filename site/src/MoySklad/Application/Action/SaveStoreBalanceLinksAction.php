<?php

declare(strict_types=1);

namespace App\MoySklad\Application\Action;

use App\MoySklad\Exception\StoreBalanceException;
use Webmozart\Assert\Assert;

/**
 * Форма вкладки «Склады» целиком: для каждого склада выбор (`target`: '' — снять, 'excluded', id счёта) и состояние на
 * момент показа (`was`). Пишутся только изменённые строки, каждая своей транзакцией SaveStoreBalanceLinkAction (склады
 * независимы): склад, изменённый после показа, пропускается (skipped), отказ по складу попадает в failed и не отменяет
 * остальные. Нет права проводить в Балансе — исключение на первой же строке, ничего не записано. Повтор — 0 изменений.
 */
final readonly class SaveStoreBalanceLinksAction
{
    public function __construct(private SaveStoreBalanceLinkAction $link)
    {
    }

    /**
     * @param array<string, array{target: string, was: string}> $links
     *
     * @return array{saved: int, skipped: int, failed: array<string, string>}
     */
    public function __invoke(string $companyId, string $connectionId, array $links, string $actorUserId): array
    {
        Assert::uuid($companyId);
        Assert::uuid($connectionId);
        $saved = $skipped = 0;
        $failed = [];
        foreach ($links as $storeExternalId => ['target' => $target, 'was' => $was]) {
            if ($target === $was) {
                continue;
            }
            $excluded = 'excluded' === $target;
            try {
                ($this->link)($companyId, $connectionId, (string) $storeExternalId, $excluded || '' === $target ? null : $target, $excluded, $actorUserId, $was) ? ++$saved : ++$skipped;
            } catch (StoreBalanceException $error) {
                if ('actor_forbidden' === $error->reason) {
                    throw $error;
                }
                $failed[(string) $storeExternalId] = $error->getMessage();
            }
        }

        return ['saved' => $saved, 'skipped' => $skipped, 'failed' => $failed];
    }
}
