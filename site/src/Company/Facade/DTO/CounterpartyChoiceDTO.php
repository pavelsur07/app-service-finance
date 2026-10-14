<?php

declare(strict_types=1);

namespace App\Company\Facade\DTO;

/**
 * Контрагент как вариант выбора в форме. Скаляры: формы соседних модулей не должны
 * получать Entity модуля Company.
 */
final readonly class CounterpartyChoiceDTO
{
    public function __construct(
        public string $id,
        public string $name,
        public ?string $inn,
        public ?string $kpp,
        public bool $isArchived,
        public ?string $displayName = null,
    ) {
    }

    /** Название для показа: отображаемое, если задано (docs/plan/counterparty-display-name.md). */
    public function shownName(): string
    {
        return $this->displayName ?? $this->name;
    }

    /**
     * Подпись варианта: одноимённые ООО различимы только по ИНН.
     */
    public function label(): string
    {
        $label = $this->shownName();

        if (null !== $this->inn) {
            $label .= ' · '.$this->inn;
        }

        if ($this->isArchived) {
            $label .= ' (в архиве)';
        }

        return $label;
    }
}
