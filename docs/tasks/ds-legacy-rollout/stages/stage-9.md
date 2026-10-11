### Stage 9: Marketplace — аналитика, реклама, интеграции — DONE

**Risk:** LOW–MEDIUM · **База:** `feat/ds-rollout-base`

#### Страницы
`marketplace_analytics/snapshots/index`, `marketplace_ads/admin_debug`, `integrations/marketplace`.

#### Что сделано
- Снапшоты: «₽» из ячеек — в заголовки 7 денежных колонок; суммы — `vf_amount` с
  двумя знаками (DS: в таблицах копейки всегда; было — целые рубли); возвраты больше
  не красные; количество продаж — `vf_number`.
- Интеграции: фон, граница, тень, бейдж «бесплатно» — токены.
- Отладка рекламы: тёмный блок вывода — Navy 900 / Navy 100; маркер ok/fail — токены
  OK / danger-текст (это сигнал успеха/ошибки).

#### Проверки
- `lint:twig` ✅; `phpunit tests/Functional/MarketplaceAnalytics tests/Functional/MarketplaceAds` ✅ 80.
- smoke: интеграции, снапшоты — 200; отладка рекламы — 403 под ролью smoke (нужен
  `ROLE_ADMIN`), покрыта lint и тестами модуля.
