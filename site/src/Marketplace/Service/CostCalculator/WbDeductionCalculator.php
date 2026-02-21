<?php

namespace App\Marketplace\Service\CostCalculator;

use App\Marketplace\Entity\MarketplaceListing;
use App\Shared\Service\SlugifyService;

class WbDeductionCalculator implements CostCalculatorInterface
{
    public function __construct(
        private readonly SlugifyService $slugify
    ) {}

    public function supports(array $item): bool
    {
        return ($item['supplier_oper_name'] ?? '') === 'Удержание';
    }

    public function requiresListing(): bool
    {
        return false; // Не блокируем — listing опционален
    }

    public function calculate(array $item, ?MarketplaceListing $listing): array
    {
        $deduction = (float)($item['deduction'] ?? 0);
        $srid = (string)$item['srid'];
        $saleDate = new \DateTimeImmutable($item['sale_dt'] ?? $item['rr_dt']);

        // Обработка bonus_type_name
        $bonusTypeName = (string)($item['bonus_type_name'] ?? '');

        // Шаг 1: Удаляем ID-паттерн
        // Паттерн: "Списание за отзыв 1xTgKBfVf6AAWKuVZ1ql: акция №1392833"
        // Нужно получить: "Списание за отзыв"

        // Находим последнее двоеточие
        $colonPos = strrpos($bonusTypeName, ':');

        if ($colonPos !== false) {
            // Есть двоеточие - проверяем что перед ним ID (примерно 20 символов латиницы/цифр)
            $beforeColon = substr($bonusTypeName, 0, $colonPos);

            // Находим последний пробел перед двоеточием
            $lastSpacePos = strrpos($beforeColon, ' ');

            if ($lastSpacePos !== false) {
                // Проверяем что между последним пробелом и двоеточием примерно 15-25 символов
                $potentialId = substr($beforeColon, $lastSpacePos + 1);
                $idLength = strlen($potentialId);

                // Если это похоже на ID (15-25 латиницы/цифр) - удаляем его вместе с тем что после двоеточия
                if ($idLength >= 15 && $idLength <= 25 && ctype_alnum($potentialId)) {
                    $bonusTypeName = substr($bonusTypeName, 0, $lastSpacePos);
                }
            }
        }

        // Шаг 2: Удаляем всё после запятой (например: ", документ №123")
        $commaPos = strpos($bonusTypeName, ',');
        if ($commaPos !== false) {
            $categoryName = trim(substr($bonusTypeName, 0, $commaPos));
        } else {
            $categoryName = trim($bonusTypeName);
        }

        // Если пусто - используем дефолт
        if ($categoryName === '') {
            $categoryName = 'Удержание';
        }

        // Генерируем code через SlugifyService: wb_ + slug
        $categoryCode = $this->slugify->slugify($categoryName, 'wb_');

        // Обрезаем до 50 символов (лимит поля code в БД)
        if (strlen($categoryCode) > 50) {
            $categoryCode = substr($categoryCode, 0, 50);
            $categoryCode = rtrim($categoryCode, '_'); // Убираем завершающее подчеркивание
        }

        // Привязываем к товару только если listing найден
        $product = $listing?->getProduct();

        return [
            [
                'category_code' => $categoryCode,
                'category_name' => $categoryName, // Передаём name для автосоздания
                'amount' => (string)abs($deduction),
                'external_id' => $srid . '_deduction',
                'cost_date' => $saleDate,
                'description' => $categoryName,
                'product' => $product,
            ],
        ];
    }
}
