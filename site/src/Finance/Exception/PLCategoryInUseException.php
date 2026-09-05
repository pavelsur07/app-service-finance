<?php

declare(strict_types=1);

namespace App\Finance\Exception;

/**
 * Статью ОПиУ нельзя удалить: к ней привязаны операции документов.
 *
 * Проверку делает вызывающее действие заранее, чтобы дать понятное сообщение, но между
 * проверкой и удалением возможна конкурентная запись операции. Тогда отказ приходит от
 * FK `fk_doc_oper_category` (без ON DELETE — RESTRICT по умолчанию) и транслируется в
 * это исключение вместо 500.
 */
final class PLCategoryInUseException extends \RuntimeException
{
    public function __construct(private readonly string $categoryId, ?\Throwable $previous = null)
    {
        parent::__construct(sprintf('PL category "%s" is still referenced by document operations.', $categoryId), 0, $previous);
    }

    public function getCategoryId(): string
    {
        return $this->categoryId;
    }
}
