## Stage 1: Виджет и фасад — работающая замена одного `<select>` — DONE

**Риск:** 🟡 MEDIUM
**Owner gate:** no
**Release candidate:** no
**Independently deployable:** no
**Следующее действие:** continue autonomously (Stage 2)

### Scope Stage
- Stage base commit: `3cec3648d6ac480b86c88c7993eb54bc889b96d6`
- Work items completed: `1.1`, `1.2`, `1.3`, `1.4`, `1.5`

### Что сделано
- `CounterpartyFacade` + `CounterpartyChoiceDTO` — варианты выбора отдаются скалярами,
  Entity модуля `Company` в формы соседних модулей больше не попадает. Подпись варианта
  включает ИНН: одноимённые ООО иначе неразличимы.
- `CounterpartyPickerType` (parent `ChoiceType`): `company_id` объявлен через
  `setRequired()`, поэтому фильтр по компании невозможно забыть — форма без опции не
  собирается. `choices` — полный company-scoped список из фасада: он же no-JS fallback,
  он же граница допустимых значений при submit, поэтому подстановку чужого id отклоняет
  сам Symfony.
- `CounterpartyEntityTransformer` (id ↔ Entity) для форм, привязанных к сущности —
  вместо ручного перекладывания значения в слушателях. Разрешение id идёт через фасад
  с companyId, то есть чужая запись не найдётся даже теоретически.
- Twig-тема `templates/form/counterparty_picker_theme.html.twig` + регистрация в
  `twig.yaml → form_themes`, по образцу существующей темы пикера проектов.
- Форма операции ДДС переведена на виджет; из слушателя `FormEvents::SUBMIT` удалено
  ручное присвоение `counterpartyId`, значение маппится штатно.

### Затронутые файлы
- `src/Company/Facade/CounterpartyFacade.php` — new
- `src/Company/Facade/DTO/CounterpartyChoiceDTO.php` — new
- `src/Company/Form/Type/CounterpartyPickerType.php` — new
- `src/Company/Form/DataTransformer/CounterpartyEntityTransformer.php` — new
- `templates/form/counterparty_picker_theme.html.twig` — new
- `config/packages/twig.yaml` — modified
- `src/Cash/Form/Transaction/CashTransactionType.php` — modified
- `src/Cash/Controller/Transaction/CashTransactionController.php` — modified
- `templates/transaction/_form.html.twig` — modified
- `tests/Integration/Company/CounterpartyPickerTypeTest.php` — new (10 тестов)

### Self-review
- [x] Scope compliance
- [x] Security — `company_id` обязателен; тест на подстановку чужого UUID в обоих
      режимах (`id` и `entity`); фасад принимает companyId в каждом методе
- [x] Архивные не предлагаются, выбранный архивный остаётся — тест
- [x] Entity чужого модуля в choices нет — DTO
- [x] CS-Fixer — чисто
- [x] Tests — `tests/Integration/Company`: OK (66 tests); полный прогон — см. stage-3
- [x] `lint:container`, `lint:twig` — OK

### Ошибка в процессе, стоит фиксации
`use`-строка виджета не вставилась в две формы `Finance`: скрипт правки решил, что
импорт уже есть, потому что имя класса встречалось в теле файла. Поймано тестами
(«Could not load type App\Finance\Form\CounterpartyPickerType»), исправлено, добавлен
`php -l` по всем семи формам.

### Открытые вопросы
- W-1 (порядок вариантов: по названию или «Недавние») и W-2 (создание контрагента
  ссылкой или inline) — дефолты из плана применены: сортировка по названию,
  `allow_create` со ссылкой на форму создания.
