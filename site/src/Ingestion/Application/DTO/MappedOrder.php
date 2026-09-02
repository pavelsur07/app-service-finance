<?php

declare(strict_types=1);

namespace App\Ingestion\Application\DTO;

use App\Ingestion\Enum\IngestOrderScheme;
use Webmozart\Assert\Assert;

/**
 * Заказ в нормализованном виде — то, что маппер отдаёт апсерту.
 *
 * Отдельный тип от {@see MappedTransaction}: тот требует type, direction, money
 * и operationGroupId, потому что описывает денежную проводку. Заказ — не
 * проводка: у него позиции, количества, статус и своя жизнь во времени.
 *
 * `statusObserved` отвечает на другой вопрос: сообщил ли источник статус
 * ВООБЩЕ. Отсутствие статуса — не статус: поток изменений WB при
 * `isCancel = false` говорит лишь «отмены не было», а `/api/v3/orders` без
 * ответа `/orders/status` не говорит ничего. Принять это за наблюдение
 * значило бы затирать реальный этап жизни заказа тем, что выведено из
 * отсутствия отмены, — а крон ставит statistics ПОСЛЕ marketplace, так что
 * затирало бы почти всегда.
 *
 * Атрибуты разделены по владельцу оси. Снимочные описывают заказ как таковой и
 * применяются вместе со снимком; статусные (`supplier_status`, `wb_status`,
 * `is_cancellable`, `is_cancel`) меняются во времени и применяются только
 * вместе с принятым статусом — иначе устаревшее сырьё показало бы актуальный
 * CANCELLED рядом с устаревшими осями статуса.
 *
 * `itemsAuthoritative` разделяет два вида наблюдений. Полный снимок (Ozon,
 * marketplace-api WB) заменяет состав заказа целиком, включая удаление
 * исчезнувших позиций. Частичное наблюдение (statistics-api WB) знает о
 * заказе не всё: оно вправе ДОБАВИТЬ недостающую позицию, но не переписывать
 * и не удалять чужие. Без этого различия поток, пришедший последним, стирал
 * бы цену и состав, которых он попросту не видит.
 */
final readonly class MappedOrder
{
    /**
     * @param list<MappedOrderItem> $items
     * @param array<string, mixed> $attributes атрибуты снимка: описывают заказ как таковой
     * @param array<string, mixed> $statusAttributes атрибуты статусной оси: меняются во времени
     * @param bool $itemsAuthoritative несёт ли наблюдение ПОЛНЫЙ состав заказа
     * @param bool $statusObserved сообщил ли источник статус вообще
     */
    public function __construct(
        public string $externalId,
        public IngestOrderScheme $scheme,
        public \DateTimeImmutable $orderedAt,
        public string $rawStatus,
        public array $items,
        public ?string $externalOrderId = null,
        public ?string $rawSubstatus = null,
        public array $attributes = [],
        public array $statusAttributes = [],
        public bool $itemsAuthoritative = true,
        public bool $statusObserved = true,
    ) {
        Assert::notEmpty($externalId);
        Assert::notEmpty($rawStatus);
    }
}
