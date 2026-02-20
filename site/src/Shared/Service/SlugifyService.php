<?php

namespace App\Shared\Service;

class SlugifyService
{
    /**
     * Генерирует slug из строки
     *
     * @param string $text Исходный текст
     * @param string $prefix Префикс для slug (например, 'wb_')
     * @return string
     */
    public function slugify(string $text, string $prefix = ''): string
    {
        // Транслитерация
        $text = $this->transliterate($text);

        // Очистка и форматирование
        $text = $this->sanitize($text);

        // Добавляем префикс если нужно
        if ($prefix !== '') {
            $text = $prefix . $text;
        }

        return $text;
    }

    /**
     * Транслитерация кириллицы в латиницу
     */
    private function transliterate(string $text): string
    {
        $translitMap = [
            'А' => 'A', 'Б' => 'B', 'В' => 'V', 'Г' => 'G', 'Д' => 'D',
            'Е' => 'E', 'Ё' => 'E', 'Ж' => 'Zh', 'З' => 'Z', 'И' => 'I',
            'Й' => 'Y', 'К' => 'K', 'Л' => 'L', 'М' => 'M', 'Н' => 'N',
            'О' => 'O', 'П' => 'P', 'Р' => 'R', 'С' => 'S', 'Т' => 'T',
            'У' => 'U', 'Ф' => 'F', 'Х' => 'H', 'Ц' => 'Ts', 'Ч' => 'Ch',
            'Ш' => 'Sh', 'Щ' => 'Sch', 'Ъ' => '', 'Ы' => 'Y', 'Ь' => '',
            'Э' => 'E', 'Ю' => 'Yu', 'Я' => 'Ya',
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
            'е' => 'e', 'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
            'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
            'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
            'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts', 'ч' => 'ch',
            'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
            'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        ];

        return strtr($text, $translitMap);
    }

    /**
     * Очистка и форматирование slug
     */
    private function sanitize(string $text): string
    {
        // Оставляем только латиницу, цифры, пробелы, дефисы
        $text = preg_replace('/[^a-zA-Z0-9\s\-]/', '', $text);

        // Заменяем пробелы и дефисы на подчёркивания
        $text = preg_replace('/[\s\-]+/', '_', $text);

        // Lowercase
        $text = strtolower($text);

        // Убираем множественные подчёркивания
        $text = preg_replace('/_+/', '_', $text);

        // Убираем подчёркивания в начале/конце
        $text = trim($text, '_');

        return $text;
    }
}
