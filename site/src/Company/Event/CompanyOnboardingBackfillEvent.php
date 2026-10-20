<?php

declare(strict_types=1);

namespace App\Company\Event;

use App\Company\Enum\BusinessProfile;

/**
 * Дозаполнение стартовых данных существующей компании (`app:company:onboarding-backfill`).
 * Модули, которые заводят данные по `CompanyCreatedEvent`, подписываются и сюда: в dry-run
 * (`execute = false`) только считают, что создали бы, иначе создают. Каждый сообщает итог
 * через `report()`, команда печатает сводку — Company при этом о модулях не знает.
 */
final class CompanyOnboardingBackfillEvent
{
    /** @var array<string, int> пункт → сколько записей создано (или было бы создано) */
    private array $gaps = [];

    public function __construct(
        public readonly string $companyId,
        public readonly BusinessProfile $businessProfile,
        public readonly bool $execute,
    ) {
    }

    public function report(string $item, int $count): void
    {
        if ($count > 0) {
            $this->gaps[$item] = ($this->gaps[$item] ?? 0) + $count;
        }
    }

    /**
     * @return array<string, int> по алфавиту пунктов: порядок подписчиков не гарантирован
     */
    public function gaps(): array
    {
        $gaps = $this->gaps;
        ksort($gaps);

        return $gaps;
    }
}
