<?php

declare(strict_types=1);

namespace App\MarketplaceAds\Infrastructure\Api\Ozon;

/**
 * Permanent-ошибка Ozon Performance API: 403 (нет скоупа «Продвижение» /
 * заблокирован client_id) либо отсутствующие / некорректные credentials.
 *
 * Ретраиться по таким ошибкам бессмысленно — caller должен падать через
 * {@see \Symfony\Component\Messenger\Exception\UnrecoverableMessageHandlingException},
 * а до этого — пометить связанный AdLoadJob как FAILED.
 *
 * Extends \RuntimeException, чтобы старые `catch (\RuntimeException)` в
 * вызывающем коде продолжали ловить эту ошибку без изменений.
 */
final class OzonPermanentApiException extends \RuntimeException
{
}
