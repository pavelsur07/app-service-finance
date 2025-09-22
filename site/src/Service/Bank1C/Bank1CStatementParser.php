<?php

namespace App\Service\Bank1C;

use App\Service\Bank1C\Dto\Bank1CDocument;
use App\Service\Bank1C\Dto\Bank1CStatement;

class Bank1CStatementParser
{
    public function parse(string $raw): Bank1CStatement
    {
        if (!mb_check_encoding($raw, 'UTF-8')) {
            $raw = iconv('Windows-1251', 'UTF-8', $raw);
        }
        $raw = str_replace("\r\n", "\n", $raw);
        $lines = preg_split('/\n/', trim($raw));
        $state = 'HEADER';
        $header = [];
        $account = [];
        $documents = [];
        $current = null;

        foreach ($lines as $line) {
            $line = trim($line);
            if ('' === $line) {
                continue;
            }
            if ('СекцияРасчСчет' === $line) {
                $state = 'ACCOUNT';
                continue;
            }
            if ('КонецРасчСчет' === $line) {
                $state = 'AFTER_ACCOUNT';
                continue;
            }
            if (str_starts_with($line, 'СекцияДокумент=')) {
                $type = substr($line, strlen('СекцияДокумент='));
                $current = new Bank1CDocument($type);
                $state = 'DOC';
                continue;
            }
            if ('КонецДокумента' === $line) {
                if ($current) {
                    $documents[] = $current;
                }
                $current = null;
                $state = 'AFTER_DOC';
                continue;
            }
            if ('КонецФайла' === $line) {
                break;
            }
            [$key, $value] = array_map('trim', explode('=', $line, 2) + [1 => '']);
            switch ($state) {
                case 'HEADER':
                    $header[$key] = $value;
                    break;
                case 'ACCOUNT':
                    $account[$key] = $value;
                    break;
                case 'DOC':
                    if ($current) {
                        $current->raw[$key] = $value;
                        switch ($key) {
                            case 'Номер':
                                $current->number = $value;
                                break;
                            case 'Дата':
                                $current->date = $value;
                                break;
                            case 'Сумма':
                                $current->amount = $this->normalizeAmount($value);
                                break;
                            case 'ПлательщикСчет':
                                $current->payerAccount = $value;
                                break;
                            case 'ПолучательСчет':
                                $current->payeeAccount = $value;
                                break;
                            case 'ДатаСписано':
                                $current->dateDebited = $value;
                                break;
                            case 'ДатаПоступило':
                                $current->dateCredited = $value;
                                break;
                            case 'Плательщик':
                                $current->payerName = $value;
                                break;
                            case 'ПлательщикИНН':
                                $current->payerInn = $value;
                                break;
                            case 'Получатель':
                                $current->payeeName = $value;
                                break;
                            case 'ПолучательИНН':
                                $current->payeeInn = $value;
                                break;
                            case 'НазначениеПлатежа':
                                $current->purpose = $value;
                                break;
                        }
                    }
                    break;
            }
        }

        return new Bank1CStatement($header, $account, $documents);
    }

    private function normalizeAmount(?string $value): ?string
    {
        if (null === $value) {
            return null;
        }

        $value = trim($value);
        if ('' === $value) {
            return null;
        }

        $sign = '';
        if (str_starts_with($value, '+') || str_starts_with($value, '-')) {
            $sign = $value[0];
            $value = substr($value, 1);
        }

        $value = str_replace(["\u{00A0}", ' '], '', $value);
        $filtered = preg_replace('/[^0-9,\.]/u', '', $value);
        if (null === $filtered || '' === $filtered) {
            return $sign.'0';
        }

        $value = $filtered;

        if (str_contains($value, ',')) {
            $value = str_replace('.', '', $value);
            $value = str_replace(',', '.', $value);
        } else {
            $value = str_replace(',', '', $value);
        }

        if (str_contains($value, '.')) {
            $parts = explode('.', $value);
            $decimal = array_pop($parts);
            $integer = implode('', $parts);
            $integer = ltrim($integer, '0');
            if ('' === $integer) {
                $integer = '0';
            }
            $value = $integer.'.'.$decimal;
        } else {
            $value = ltrim($value, '0');
            if ('' === $value) {
                $value = '0';
            }
        }

        return $sign.$value;
    }
}
