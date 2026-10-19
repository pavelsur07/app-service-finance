<?php

declare(strict_types=1);

namespace App\Tests\Builders\Ingestion;

use App\Ingestion\Entity\CategoryGroup;
use App\Ingestion\Enum\IngestSource;

final class CategoryGroupBuilder
{
    private string $code = 'test_group';
    private string $label = 'Тестовая группа';
    private int $sortOrder = 500;
    private ?string $ozonLabel = null;

    public static function aGroup(): self
    {
        return new self();
    }

    public function withCode(string $code): self
    {
        $this->code = $code;

        return $this;
    }

    public function withLabel(string $label): self
    {
        $this->label = $label;

        return $this;
    }

    public function withOzonLabel(?string $ozonLabel): self
    {
        $this->ozonLabel = $ozonLabel;

        return $this;
    }

    public function withSortOrder(int $sortOrder): self
    {
        $this->sortOrder = $sortOrder;

        return $this;
    }

    public function build(): CategoryGroup
    {
        return new CategoryGroup(IngestSource::OZON, $this->code, $this->label, $this->sortOrder, $this->ozonLabel);
    }
}
