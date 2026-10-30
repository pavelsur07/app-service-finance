### Stage 2 — DONE
Base a2ff06e8c; MEDIUM; только Ozon mapping page и его default modal.
Понятные 4 вида продаж/возвратов + стоимость, descriptions до/после скидки;
технические коды скрыты в Ozon, другие marketplaces сохраняют прежние подписи.
Единый warning + кнопка + перечень отключаемых правил в подтверждении, форма с
company-scoped CSRF; смена компании после открытия подтверждения отклоняется.
Статусы обработанных месячных реализаций; GET read-only, другой marketplace без Ozon UI.
Checks: UI title regression red before implementation; module 62 tests/264 assertions;
Twig 4 files green; focused PHPStan green; JS smoke actual inline-script conflict/normal/error
(no technical codes in generated Ozon table), frontend lint/build/API types green.
Последний UI-only прогон записан в handoff; исходные ошибки тестовых импортов исправлены.
BrowserKit DOM + inline-script smoke выполнены; реальный browser visual smoke не выполнялся.
Internal 0 BLOCKER/IMPORTANT; no CSS/new entry/dependencies; existing Tabler/DS feedback patterns.
Next: full gates and fresh/internal + external review, no production changes.

Handoff verification complete: full7603/43343, unit4248/24515, full PHPStan/CS/strict,
frontend lint/build/API types green; post-review module64/273 + focused checks green.
Internal REVIEW_GREEN 0 B/I; external round1 REVIEW_GREEN 0 B/I,4 MINOR fixed/verified.
