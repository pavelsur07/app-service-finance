# Конвенции Twig

> Когда читать: задача трогает `site/templates/`. Визуал и React —
> `CLAUDE.frontend.md`; гейт — `make site-cs-twig` (twigcs + `lint:twig`),
> в CI — `.github/workflows/twig-lint.yml`. Задача, из которой выросли правила, —
> `docs/tasks/twig-ui-systematization/plan.md`.

## Три shell'а

| Shell | Кто наследует | Стили |
|---|---|---|
| `_layout/legacy.html.twig` (через `base.html.twig`) | легаси-страницы на Tabler, 153 файла | Tabler CDN + `design-tokens.css` + `vf-custom-classes.css` + `legacy-app.css` |
| `_layout/app.html.twig` | новый слой: Баланс, Взаиморасчёты, API, Бюджет | кит `assets/styles/kit/` через `app.css` |
| `admin/base.html.twig` | админка | кит + `admin_shell` |

Новая страница в существующем модуле наследует layout модуля
(`marketplace/layout`, `inventory/layout`, `balance/layout`, `settlement/layout`,
`api/settings/layout`), а не `base.html.twig` напрямую: layout даёт вкладки,
flash и шапку раздела. Новый shell не создаётся.

## Файлы и имена

- Страница — `<module>/<page>.html.twig`; партиал — `<module>/_<name>.html.twig`;
  макросы — `<module>/_macros.html.twig`, импорт `{% import ... as ui %}`.
- Общие партиалы: легаси — `partials/_*.html.twig` (`@Partials/…`), новый слой —
  `app/_ui/_*.html.twig`. Прежде чем писать свой — поискать там: пагинация,
  вкладки, диалог подтверждения, выбор из справочника, пресеты периода уже есть.
- Партиал с параметрами подключается с `only`, параметры перечислены в
  комментарии в начале партиала (образец — `partials/_pagination.html.twig`).
- Блоки страницы: `title`, `body` (легаси) или `content` (новый слой),
  `stylesheets`, `javascripts`. Собственные блоки layout'а модуля именуются
  `<module>_content`.
- Переменные — camelCase, как свойства DTO из PHP (`currentPage`, `totalItems`);
  правило snake_case в twigcs отключено осознанно (`tests/Twigcs/Ruleset.php`).

## Что в шаблоне не живёт

| Запрещено | Куда | Почему |
|---|---|---|
| `<script>` с логикой страницы | `assets/pages/<page>.js` с `init*()` из `legacy-app.js` или Stimulus-контроллер `assets/controllers/` | Twig внутри JS — XSS; код без eslint и сборки |
| Twig-выражение внутри JS | `data-*`-атрибут: `data-props="{{ value\|json_encode\|e('html_attr') }}"` | единственное безопасное экранирование |
| `confirm()`, `onclick=`, `onsubmit=` | `partials/_confirm_modal.html.twig` + `data-confirm-*`, Stimulus `data-action` | DS-диалог, один на страницу |
| `<style>` и `style="…"` со значениями | `assets/styles/pages/<page>.css`, токены `--vf-*` / semantic | DS: цвета и размеры только из токенов |
| `{% set statusLabels = {…} %}` | `label()` Enum в PHP или поле DTO | один источник подписи, тесты в PHP |
| `number_format`, `₽` литералом | `vf_money` / `vf_amount` / `vf_number` | лист DS-Money |
| свой рендер `app.flashes` | `partials/_flash` (легаси), партиал shell'а нового слоя | один маппинг `label → variant` |

Исключения: `_layout/*`, `admin/base` (подключение бандлов), `notifications/email`
(инлайн-стили обязательны для почты), Chart-острова до их переноса в `assets/pages`.

## Стиль кода (twigcs, ruleset проекта)

Один пробел внутри `{{ }}` и `{% %}`, вокруг бинарных операторов и `?:`,
после `,` и `:` в хэшах; без пробелов внутри `{}` и `[]`, у именованных
аргументов `with_context=false`. twigcs требует это **на одной строке**: перенос
допустим внутри хэша и списка аргументов функции, но не вокруг операторов и
`? :` — длинное условие или тернарник пишется одной строкой либо выносится в
промежуточный `{% set %}`. Предупреждения «unused variable/macro» гейт не
считает: без резолвера include/extends они ложные (`active_tab`, `app_nav_active`
читает layout).

## Проверки перед коммитом

```bash
make site-cs-twig                      # twigcs + lint:twig
make site-test                          # шаблоны проверяют функциональные тесты (strict_variables только в test)
```

В prod Twig не строгий: пропавшая переменная отрендерится пустотой молча.
Поэтому у страницы, которую трогает задача, должен быть функциональный тест
на 200 и корневой селектор; нет теста — написать до правки.
