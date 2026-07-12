<?php

declare(strict_types=1);

namespace App\Tests\Unit\Cash\Form\Transaction;

use App\Cash\Entity\Transaction\CashflowCategory;
use App\Cash\Form\Transaction\CashflowCategoryCodeTransformer;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Form\Exception\TransformationFailedException;

final class CashflowCategoryCodeTransformerTest extends TestCase
{
    public function testNormalizesValidCode(): void
    {
        self::assertSame('CUSTOM_CODE', (new CashflowCategoryCodeTransformer())->reverseTransform(' custom_code '));
    }

    public function testInvalidCodeBecomesFormError(): void
    {
        try {
            (new CashflowCategoryCodeTransformer())->reverseTransform('неверный код');
            self::fail('Некорректный код должен стать ошибкой формы.');
        } catch (TransformationFailedException $exception) {
            self::assertStringContainsString('латинские буквы', $exception->getInvalidMessage());
        }
    }

    public function testReservedSystemCodeBecomesFormError(): void
    {
        try {
            (new CashflowCategoryCodeTransformer())->reverseTransform(CashflowCategory::CODE_OPERATING);
            self::fail('Системный код должен быть зарезервирован.');
        } catch (TransformationFailedException $exception) {
            self::assertStringContainsString('зарезервирован', $exception->getInvalidMessage());
        }
    }
}
