Анализ дизайн-системы прототипа
Цветовая палитра
Бренд / Primary

#1A56DB — primary
#1E40AF — primary hover / deep
#EEF3FE — primary tint (фон выбранного, hover)
#C7D7FB — dashed primary border
#DBE6FF — выделение текста ::selection
Нейтральные

#0B1220 — основной текст, тёмные карточки
#475569 — вторичный
#64748B — caption
#8B95A7 — muted (плейсхолдеры)
#94A3B8 — disabled / mini-meta
#A4ADBF — sidebar заголовки секций
#CBD5E1 — точки-разделители, пустые состояния
#D8DEE8 — scrollbar, dashed border
Границы

#E2E8F0 — border у inputs
#E8ECF3 — border у карточек, дивайдеры
#F1F4F9 — мягкие row-дивайдеры
#EDEFF3 — alt subtle border (только в AutoRules — для paused-карточек)
Фоны

#FFFFFF — карточки
#F4F6FA — фон страницы / hover
#F7F9FC — workspace switcher, condition-rows
#FAFBFD — thead, row hover, footer-полосы
#F4F8FF — выбранная строка в picker'ах
Семантика

Роль	Текст	Фон	Border	Точка
Success (income)	#047857, #065F46, #10B981	#ECFDF5	#D1FAE5	#10B981, #A7F3D0
Danger (expense)	#B91C1C, #DC2626, #EF4444	#FEF2F2	#FECACA, #FEE2E2	#EF4444
Warning	#92400E, #B45309	#FFFBEB, #FFF7ED	#FDE68A, #FFE4CC	#F59E0B, #FBBF24
Категорийные #10B981, #F59E0B, #06B6D4, #8B5CF6, #EF4444, #0EA5E9, #94A3B8, #1A56DB

Бренды банков Тинькофф #FFDD2D (текст #0B1220) · Сбер #1A9F29 · Альфа #EF3124 · Точка #6132D6 · ЮKassa #7B61FF · МТС #EF4444

Source badge'ы (5 шт) — каждый со своим bg/color/border tint.

Типографическая шкала
Шрифт: Manrope, system-ui, -apple-system, 'Segoe UI', sans-serif

Размер	Использование
9.5px	мини-капс (CUSTOM, ОСНОВНОЙ, РАЗДЕЛ)
10–10.5px	thead, sidebar-заголовки секций, footer-meta
11px	KPI-подзаголовки, бейджи статусов, footer-хинты
11.5px	вторичный текст, KPI-meta
12px	filter chips, бейджи фильтров
12.5px	sidebar items, tabs, chip-buttons
13px	основной body, ячейки таблиц, инпуты
13.5px	sidebar основной
14px	секционные H, суммы в таблице
15px	подзаголовки модалок
17px	заголовки drawer'ов
22px	KPI-значения (стандарт)
24px	page H1
26px	KPI-значения dashboard
28px	большой баланс (account-card)
Веса: 400, 500, 600, 700, 800 Letter-spacing: −0.025em (H1) → −0.005em (body) → +0.04–0.06em (uppercase labels)

Отступы (gap / padding)
Базовая шкала: 1, 2, 3, 4, 5, 6, 7, 8, 10, 12, 14, 16, 18, 20, 24, 28

Контейнер	Padding
Page wrapper	24px 28px 40px
Карточка (компактная)	14px 16px
Карточка (стандарт)	18px 20px
Header страницы	0 28px (height 56)
Drawer header/footer	18px 28px / 14px 28px
Drawer body	20px 28px 24px
Sidebar	264px ширина
Drawer	640–680px ширина
Модалка	440px (компания), 520px (контрагент/категория)
Радиусы (после редукции)
px	Применение
3	kbd-хинты, бейджи самого верхнего уровня
4	мини-чипы, source badges, type badges, кружки с инициалом small
5	nav items, кнопки-чипы, dropdown-меню items, small inputs
6	инпуты, primary buttons, drawer-кнопка close, search
7	dropdown-menu контейнеры
8	карточки, секции drawer'а
50%	аватары, dots
99px / 999px	status pills, прогресс-бары, тогл-переключатели
Тени
0 1px 2px rgba(15,23,42,0.06)                                    — subtle button selected state
0 1px 3px rgba(15,23,42,0.06–0.08)                                — card lift
0 1px 0 rgba(255,255,255,0.15) inset                              — primary button highlight
0 4px 10px rgba(26,86,219,0.25)                                   — primary button glow
0 4px 12px rgba(15,23,42,0.04)                                    — card hover
0 12px 32px rgba(15,23,42,0.12)                                   — dropdown (early)
0 16px 36px rgba(15,23,42,0.14), 0 2px 8px rgba(15,23,42,0.06)    — row dropdown menu
0 24px 60px rgba(11,18,32,0.25–0.30), 0 4px 12px rgba(11,18,32,0.08–0.10)  — модалки
-20px 0 60px rgba(11,18,32,0.20–0.22)                             — drawer slide-in
⚠️ Расхождения
Backdrop модалок: opacity 0.45 (drawer, компания) vs 0.50 (категория); blur 4px (drawer) vs 6px (модалки).
Width drawer'ов: Cashflow edit 680px · Account form 640px · Rule form 680px — нет одного стандарта.
Padding карточек-секций: 14px 16px (KPI) vs 18px 20px (drawer-секции) — два разных «стандарта».
KPI-значения: Dashboard 26px, AccountsCash hero 28px, остальные 22px — три размера для одного паттерна.
Border-color у границ: #E2E8F0 (inputs) vs #E8ECF3 (карточки) — близкие, но не идентичные.
Toggle-переключатель: 42×24 везде ✓
Аватары/квадраты с инициалом: 22 / 24 / 28 / 30 / 32 / 34 — шесть вариантов размеров.
Цвет тинта чипа: иногда руками (#EEF3FE, #F5F3FF), иногда генерация color + '12' (AutoRules action chip) — два метода.
Direction-индикатор: круг ↑/↓ (DirIcon в Cashflow) vs +/− чип (Categories) vs цветной dot — три визуальных решения.
Status pill vs Sync dot: на таблице ДДС статус — пилюля с точкой; на AccountsCash sync-статус — голый dot + текст. Несогласовано.
Border у не-активных карточек: Дашборд KPI use #E8ECF3; paused-rule в AutoRules — #EDEFF3 (≈ то же визуально, но другое значение).
💰 Форматирование сумм — все варианты
#	Формат	Пример	Где
1	signed + ₽ суффикс + thin-space	+245 000 ₽ / −156 200 ₽	ДДС-таблица, edit-drawer, KPI
2	unsigned + ₽ суффикс	3 829 930 ₽	account-card balance, total
3	short metric + ₽	2,0 М ₽ / 500 К ₽	график Dashboard (Y-оси, buffer)
4	currency-prefix	$8 450 / €...	foreign currency в AccountsCash
5	без знака и без валюты	1 245 230	AccountsCash таблица (валюта в отдельной колонке)
6	inline-meta	в обработке: 18 200 ₽, лимит 200 000 ₽	account-card, accounts
7	raw input	95 000 (без знака, без ₽)	input в split-форме edit-drawer
8	zero	0 (плюс цвет #CBD5E1) / — ₽ / —	ноль в таблицах, пустые проекты
9	процент Russian-decimal	+12,4% / −4,1%	KPI динамики
10	day-sum/итог по дню	итого по дню: +206 500 ₽	day-разделители ДДС-таблицы
11	подытог категории	+5 670 000 ₽	агрегаты в дереве категорий
12	прайс-ставка	+12% год.	депозит в account-card
Несоответствия:

Знак минуса: везде em-dash − через formatter, но кое-где может проскочить hyphen-minus -. Проверить нужно.
Слово «млн»: изначально 2,0 млн, потом унифицировал в 2,0 М для графика — но в KPI Dashboard осталось 3,2 млн ₽ (прогноз). Расхождение: М vs млн.
Десятичный разделитель: русская запятая в процентах/графике, но в «2,0 М ₽» есть, а в «+245 000 ₽» — нет (integer). Логично, но требует осознания.
₽ как суффикс vs RUB как чип: в AccountsCash таблице — отдельная колонка RUB чипом, в остальных местах — ₽ сразу после числа.
Размер шрифта суммы: 13 / 14 / 18 / 22 / 26 / 28 — шесть градаций, не всегда обоснованы (см. KPI ≠ единый стандарт).
