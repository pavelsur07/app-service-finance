1️⃣ ЦЕЛЕВАЯ МОДЕЛЬ МОДУЛЯ CASH (ФИКСАЦИЯ)

Это важно зафиксировать один раз, дальше всё под это правим.

1.1. Границы модуля Cash
✅ Разрешено

Доступ к Cash только через:

App\Cash\Service\* (Application Services)

App\Cash\Repository\* (read/write, но только внутри Cash)

Контроллеры Cash:

НЕ используют EntityManager напрямую

НЕ строят QueryBuilder

Делают: DTO → Service → Response

❌ Запрещено

EntityManager / QueryBuilder / DBAL вне Cash

Прямые Cash* Entity в:

DTO

Controllers других модулей

MessageHandler

Telegram / Admin / AI

Прямые ManyToOne на Cash Entity вне Cash (кроме legacy → выносим в адаптер)

⚠️ Отчёты / аналитика (D)

Cash — источник данных, но:

Только через QueryService / ReadModel

Никаких createQueryBuilder() снаружи
