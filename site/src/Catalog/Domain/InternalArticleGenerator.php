<?php

declare(strict_types=1);

namespace App\Catalog\Domain;

interface InternalArticleGenerator
{
    /**
     * Генерирует уникальный внутренний артикул для компании.
     * Формат: PRD-{YYYY}-{NNNNNN}
     * Уникальность гарантируется в рамках companyId.
     */
    public function generate(string $companyId): string;
}
