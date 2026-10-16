<?php

declare(strict_types=1);

namespace App\Finance\Exception;

/**
 * Документ ОПиУ или его операция получили справочник другой компании: контрагента, проект,
 * основание ДДС или статью. FK компанию не несут, поэтому инвариант держит сама сущность —
 * иначе чужое название показали бы страницы документа, а регистр ОПиУ разнёс бы сумму по
 * чужой статье.
 */
final class DocumentForeignReferenceException extends \RuntimeException
{
    public function __construct(
        private readonly string $reference,
        private readonly string $referenceId,
        string $documentCompanyId,
    ) {
        parent::__construct(sprintf(
            'P&L document of company "%s" cannot reference %s "%s" of another company.',
            $documentCompanyId,
            $reference,
            $referenceId,
        ));
    }

    public function getReference(): string
    {
        return $this->reference;
    }

    public function getReferenceId(): string
    {
        return $this->referenceId;
    }
}
