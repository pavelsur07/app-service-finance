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
Полные гейты запускаются отдельно; результаты будут в handoff.
