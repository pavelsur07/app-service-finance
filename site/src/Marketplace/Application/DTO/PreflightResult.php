<?php

declare(strict_types=1);

namespace App\Marketplace\Application\DTO;

/**
 * Итоговый результат preflight-проверки перед закрытием этапа месяца.
 */
final class PreflightResult
{
    /** @param PreflightCheck[] $checks */
    public function __construct(
        public readonly array $checks,
    ) {
    }

    /**
     * Можно ли закрыть этап — нет блокирующих ошибок.
     */
    public function canClose(): bool
    {
        foreach ($this->checks as $check) {
            if (!$check->passed && $check->blocking) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return PreflightCheck[]
     */
    public function getErrors(): array
    {
        return array_values(array_filter(
            $this->checks,
            static fn(PreflightCheck $c) => !$c->passed && $c->blocking,
        ));
    }

    /**
     * @return PreflightCheck[]
     */
    public function getWarnings(): array
    {
        return array_values(array_filter(
            $this->checks,
            static fn(PreflightCheck $c) => !$c->passed && !$c->blocking,
        ));
    }

    /**
     * Сериализация для хранения в preflight_snapshot.
     */
    public function toArray(): array
    {
        return array_map(
            static fn(PreflightCheck $c) => [
                'key'      => $c->key,
                'label'    => $c->label,
                'passed'   => $c->passed,
                'blocking' => $c->blocking,
                'message'  => $c->message,
                'value'    => $c->value,
                'details'  => $c->details,
            ],
            $this->checks,
        );
    }
}
