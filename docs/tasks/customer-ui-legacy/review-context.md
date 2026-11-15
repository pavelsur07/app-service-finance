# Контекст внешнего review

Scope: только пользовательский UI site/. Admin и apps исключены.
Дашборд использует имеющийся legacy template; API markup переведён на Tabler;
старые UI-cookie и POST app приводятся к legacy, URL/CSRF/redirect guards сохранены.
Auth уже отдельный legacy auth-слой, бюджет и Unit уже legacy-shell.
Общие app/_ui pagination/tabs и kit assets сохранены, поскольку ими пользуется admin.

Внутренне найдено и исправлено:
- Bootstrap checkbox theme выводил technical scope labels: label:false,
  доступное имя остаётся через aria-label; browser проверяет пустой текст ячеек.
- FlashShellsTest ссылался на удалённый customer flash template: ссылка удалена,
  legacy/admin tests сохранены. Red: 2 LoaderError; green: 2 tests / 20 assertions.
  Исправление проверено независимым внутренним reviewer.

Независимое review полного diff: BLOCKER 0, IMPORTANT 1 fixed; открытых нет.
Модульные проверки: 241 tests / 3763 assertions; permissions после label fix:
7 tests / 69 assertions. Frontend lint/build/unit 152 tests — OK. Chromium:
9 отрендеренных Symfony test HTML fixtures × desktop/mobile; copy/presets/Tab/
secret clearing — OK. Это UI smoke; реальные HTTP-сценарии покрыты functional tests.
CI дополнительно выявил устаревшее ожидание app-workzone для billing banner и
ожидание Vite script tags в functional job, где frontend build не выполняется.
Тесты актуализированы, built scripts доказаны browser smoke. Delta проверен
независимым reviewer, открытых B/I нет. Эти два случая — validation fixes,
не дополнительные находки внешнего review.

Полные PHPStan, fixer, strict-types, unit и Twig gates прошли. Backend CI
на source commit `6f380a39e`: unit, три functional shards и integration — pass.
Локальный make site-test сначала остановился на правах записи Composer в
vendor; прогон с preinstalled deps показал отсутствие seed tariffs в старой
test DB и отсутствие write-доступа UID 1000 к var/storage (owner root).
Диагностика на отдельной fresh test DB доказала storage permission failure
в первом CashFileImportHandlerFlowTest. Контрольный полный прогон выполняется
в отдельной fresh DB под user 0 внутри локального test runner:
OK, 9271 tests / 52215 assertions, 07:45.022, exit 0. chmod/chown,
production/config/dependencies не менялись. Результаты — в handoff.
