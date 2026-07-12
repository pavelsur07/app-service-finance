<?php

declare(strict_types=1);

namespace App\Cash\Form\Transaction;

use App\Cash\Entity\Transaction\CashflowCategory;
use Symfony\Component\Form\DataTransformerInterface;
use Symfony\Component\Form\Exception\TransformationFailedException;

/** @implements DataTransformerInterface<?string, ?string> */
final class CashflowCategoryCodeTransformer implements DataTransformerInterface
{
    public function transform(mixed $value): ?string
    {
        return is_string($value) ? $value : null;
    }

    public function reverseTransform(mixed $value): ?string
    {
        try {
            $code = CashflowCategory::normalizeCode(is_string($value) ? $value : null);
            if (CashflowCategory::isSystemCode($code)) {
                throw new \DomainException('Этот код зарезервирован для системной категории.');
            }

            return $code;
        } catch (\DomainException $exception) {
            $failure = new TransformationFailedException($exception->getMessage(), 0, $exception);
            $failure->setInvalidMessage($exception->getMessage());

            throw $failure;
        }
    }
}
