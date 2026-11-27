# Приёмка приёма 1С на проде (без 1С)

- Дата: 27.11.2026, 06:40 МСК.
- Master: `66a7a2a32` (после #3147 и #3145).
- URL: `https://app.vashfindir.ru/api/onec/v1/…`.
- Скрипт: `tools/onec-smoke/smoke.py --big`. Токен — только из переменной окружения.

## Данные прогона

| | |
|---|---|
| connection_id | `01a21913-3303-73ec-ab72-b3a54d8e781e` (компания `0bf4d699…`, «Тестовое подключение»; до прогона pending, без `base_id` и частей) |
| base_id | `2517322d-4a13-42fe-9876-d5992b0e2897` |
| package_id п. 6 | `2a9b7e3c-364e-4da1-ade1-7f93738e4c90`, часть 1 из 1, sha256 `9ed2c160…9ef69c` |
| package_id п. 13 | `96b5350c-662f-4db3-a1f3-56db14873a17`, части 1 и 2 из 2, sha256 `0f59787c…e5ccde` и `15c31660…ecf85c` |

Неуспешные запросы (п. 1, 8–12, 14, 15) использовали свои `package_id`; на сервере ничего не сохранили — в `onec_package` подключения ровно 3 строки.

## Результаты

| № | Ожидание | Факт | Итог |
|---|---|---|---|
| 1 | 409 base_conflict | 409 base_conflict «Сначала выполните рукопожатие.» | ✅ |
| 2 | 200 connected, supported, months 12, mapped=false | 200, всё совпало | ✅ |
| 3 | 200, то же подключение | 200, тот же connection_id | ✅ |
| 4 | 409 base_conflict | 409 base_conflict | ✅ |
| 5a | 422 invalid_handshake (невалидный JSON) | 422 invalid_handshake | ✅ |
| 5b | 422 invalid_handshake (без organizations) | 422 invalid_handshake | ✅ |
| 6 | 200 accepted, stored, duplicate=false | совпало | ✅ |
| 7 | 200 duplicate=true | совпало | ✅ |
| 8 | 409 package_conflict | 409 package_conflict | ✅ |
| 9 | 422 invalid_package (без snapshot_at) | 422 invalid_package | ✅ |
| 10 | 422 invalid_package (snapshot_at +2 дня) | 422 invalid_package | ✅ |
| 11 | 422 invalid_package (bank_statements) | 422 invalid_package | ✅ |
| 12a | 401 invalid_token (без заголовка) | 401 invalid_token | ✅ |
| 12b | 401 invalid_token (неверный токен) | 401 invalid_token | ✅ |
| 13 | обе части 200 stored | обе 200 stored, duplicate=false | ✅ |
| 14 | 409 (часть 2 с другим period_to) | 409 package_conflict | ✅ |
| 15 | 413 payload_too_large (≈10 МиБ + 1 КиБ) | 413 payload_too_large, JSON от приложения | ✅ |

18 из 18. Непрошедших сценариев нет, полные тела не приводятся.

## Проверено в БД прода (read-only)

- Подключение `connected`, `base_id` закреплён.
- Организация из примера сохранена, `enabled = false`.
- SHA-256 трёх частей в `onec_package` совпадают с отправленными файлами.
- Путь в хранилище: `onec/{connection_id}/{package_id}/{part}-{sha256}.json.gz`.

## Расхождения с контрактом

Нет. Замечания:

1. Путь объекта в бакете — `onec/{connection_id}/{package_id}/…`, без сегмента `invoices/`. Контракт путь не описывает, так что это не расхождение; если вид выгрузки нужен в пути — отдельная задача на сервер.
2. Для проверки взято существующее подключение без рукопожатий (создано 26.11), а не созданное заново. Для теста закрепления `base_id` это равнозначно.
3. `export_allowed` и `organization_guids` отсутствуют, как и ожидалось до следующего PR.

## Ручная проверка Владельцем

Заполняется после шагов 1–5 из отчёта агента: карточка, сравнение SHA-256 скачанной части, бакет и регион, другая компания, перевыпуск токена.
