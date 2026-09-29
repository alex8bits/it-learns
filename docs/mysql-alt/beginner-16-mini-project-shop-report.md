---
course: mysql
level: Начинающий
level_slug: beginner
lesson: 16
title: "Мини-проект «Отчёт интернет-магазина»: JOIN + GROUP BY + HAVING + CASE + даты"
practice: yes
---

# Мини-проект «Отчёт интернет-магазина»: JOIN + GROUP BY + HAVING + CASE + даты

## Материал

### Ранее в курсе

- Урок 1 уровня «Основы» «Что такое БД и СУБД» — таблицы, строки, столбцы, `SELECT`.
- Урок 2 уровня «Основы» «MySQL сервер и клиент» — клиент-серверная архитектура, базы данных, `USE`.
- Урок 3 уровня «Основы» «Синтаксис SQL и SELECT» — синтаксис запросов, `LIMIT`.
- Урок 4 уровня «Основы» «Добавление данных» — `INSERT INTO ... VALUES ...`.
- Урок 5 уровня «Основы» «Типы данных» — `INT`, `DECIMAL`, `VARCHAR`/`TEXT`, `DATE`/`DATETIME`, `BOOL`, `utf8mb4`.
- Урок 6 уровня «Основы» «Создание таблиц с ограничениями» — `CREATE TABLE`, `PRIMARY KEY`, `AUTO_INCREMENT`, `NOT NULL`, `UNIQUE`, `DEFAULT`, `CHECK`.
- Урок 7 уровня «Основы» «Изменение и удаление объектов» — `ALTER TABLE`, различия `DELETE`/`TRUNCATE`/`DROP`.
- Урок 8 уровня «Основы» «Фильтрация WHERE» — операторы сравнения, `<>`, отбор строк по условию.
- Урок 9 уровня «Основы» «Сортировка и страницы» — `ORDER BY`, `LIMIT`/`OFFSET`, постраничный вывод.
- Урок 10 уровня «Основы» «Изменение и удаление строк» — `UPDATE ... WHERE`, `DELETE ... WHERE`, опасность отсутствия `WHERE`.
- Урок 11 уровня «Основы» «NULL на практике» — отличие `NULL` от `0`/пустой строки, `IS NULL`/`IS NOT NULL`, `COALESCE`, `NULLIF`.
- Урок 12 уровня «Основы» «Мини-проект «Каталог товаров»» — сборка `CREATE TABLE` → `INSERT` → `SELECT`/`WHERE` → `ORDER BY` → `UPDATE`/`DELETE` в единый сценарий работы с данными.
- Урок 1 уровня «Начинающий» «Логические операторы» — `AND`/`OR`/`NOT`, приоритет операторов, обязательные скобки в составных условиях.
- Урок 2 уровня «Начинающий» «IN, BETWEEN, LIKE» — принадлежность списку, диапазоны, поиск по текстовому шаблону, `ESCAPE`.
- Урок 3 уровня «Начинающий» «Вычисления, псевдонимы, DISTINCT» — арифметика в `SELECT`, `AS`, `CONCAT`, уникальные строки.
- Урок 4 уровня «Начинающий» «Строковые функции» — `UPPER`/`LOWER`, `SUBSTRING`, `TRIM`, `REPLACE`, `LENGTH`/`CHAR_LENGTH`.
- Урок 5 уровня «Начинающий» «Числа и даты» — `ROUND`/`FLOOR`/`ABS`, `NOW`, `DATEDIFF`, `DATE_ADD`, `DATE_FORMAT`, `EXTRACT`, `TIMESTAMPDIFF`.
- Урок 6 уровня «Начинающий» «Логический порядок выполнения SELECT» — `FROM → WHERE → GROUP BY → HAVING → SELECT → ORDER BY`, почему псевдоним недоступен в `WHERE`.
- Урок 7 уровня «Начинающий» «CASE WHEN» — простой и поисковый `CASE`, категоризация значений, условная сортировка.
- Урок 8 уровня «Начинающий» «Агрегатные функции» — `COUNT`, `SUM`, `AVG`, `MIN`, `MAX`; поведение с `NULL`.
- Урок 9 уровня «Начинающий» «GROUP BY» — группировка по одному и нескольким столбцам, условные агрегаты `COUNT(CASE ...)`.
- Урок 10 уровня «Начинающий» «HAVING vs WHERE» — фильтрация строк до группировки против фильтрации готовых групп.
- Урок 11 уровня «Начинающий» «Модель связей» — 1:1, 1:N, M:N, ER-диаграммы, зачем нужен `JOIN`.
- Урок 12 уровня «Начинающий» «Внешние ключи и каскады» — `FOREIGN KEY`, `ON DELETE`/`ON UPDATE` (`RESTRICT`/`CASCADE`/`SET NULL`).
- Урок 13 уровня «Начинающий» «INNER JOIN» — соединение двух таблиц по условию `ON`, алиасы, явные столбцы.
- Урок 14 уровня «Начинающий» «LEFT/RIGHT JOIN» — сохранение строк без пары, анти-джойн, разница между условием в `ON` и в `WHERE`.
- Урок 15 уровня «Начинающий» «Мульти-JOIN и self-join» — цепочки соединений из нескольких таблиц, соединение таблицы с самой собой для иерархий.

Уровень «Начинающий» дал нам большой набор самостоятельных инструментов: логические операторы, `LIKE`, вычисления, строковые и числовые функции, `CASE`, агрегаты, `GROUP BY`/`HAVING` и, наконец, весь спектр `JOIN`. Как и в мини-проекте уровня «Основы», настоящая ценность этих инструментов раскрывается не по отдельности, а вместе — когда решается одна сквозная бизнес-задача, требующая последовательного применения нескольких тем сразу. В этом уроке мы построим отчёт по интернет-магазину, объединяя JOIN, группировку, условную фильтрацию групп, `CASE` и функции работы с датами — ровно так, как выглядит типичная аналитическая задача в реальной работе с базой данных.

### Постановка задачи

Представим, что руководитель интернет-магазина попросил подготовить отчёт по клиентам за прошедший период, отвечающий на несколько связанных вопросов: сколько всего заказов у каждого клиента, на какую сумму, когда был последний заказ, и — отдельным пунктом — выделить только «активных» клиентов, то есть тех, кто сделал больше одного заказа. Разберём эту задачу по шагам, показывая, как в неё складываются темы всего уровня.

### Шаг 1. Собрать данные заказа и его позиций через JOIN

Сумма конкретного заказа не хранится в `orders` напрямую — она складывается из позиций `order_items`, каждая из которых хранит `quantity` и `price` (цену товара именно на момент этого заказа, как мы упоминали в одиннадцатом уроке). Чтобы посчитать сумму заказа, нужно соединить `orders` с `order_items` (тема тринадцатого урока):

```sql
SELECT
    o.id AS order_id,
    o.customer_id,
    o.created_at,
    oi.quantity,
    oi.price,
    oi.quantity * oi.price AS line_total
FROM orders o
INNER JOIN order_items oi ON oi.order_id = o.id;
```

Здесь мы уже используем вычисление в `SELECT` из третьего урока (`quantity * price`) прямо на результате `JOIN` — подтверждение того, что всё изученное ранее работает с соединёнными таблицами точно так же, как с одной.

### Шаг 2. Посчитать сумму каждого заказа через GROUP BY

Один заказ обычно состоит из нескольких позиций, а нам нужна именно **сумма всего заказа**, а не отдельная строка на каждую позицию. Здесь пригождается `GROUP BY` и агрегат `SUM` из восьмого и девятого уроков — группируем по заказу и складываем суммы всех его позиций:

```sql
SELECT
    o.id AS order_id,
    o.customer_id,
    o.created_at,
    SUM(oi.quantity * oi.price) AS order_total
FROM orders o
INNER JOIN order_items oi ON oi.order_id = o.id
GROUP BY o.id, o.customer_id, o.created_at;
```

Обратите внимание на список `GROUP BY`: помимо `o.id` мы указали и `o.customer_id`, и `o.created_at` — это следствие правила из девятого урока: любой столбец в `SELECT`, не завёрнутый в агрегатную функцию, обязан присутствовать в `GROUP BY`. Поскольку `customer_id` и `created_at` у конкретного заказа всегда одни и те же (заказ не может «принадлежать» сразу двум разным клиентам или иметь две разные даты создания), добавление их в `GROUP BY` не меняет логической сути группировки — реальные группы всё равно определяются именно уникальностью `o.id`, а два других столбца просто «следуют» за ним.

### Шаг 3. Присоединить клиента и агрегировать уже по клиенту

Теперь поднимемся на уровень выше: нужна не сумма отдельного заказа, а сводка **по каждому клиенту** — сколько у него всего заказов и на какую общую сумму. Присоединим `customers` (тринадцатый урок) и сгруппируем уже по клиенту, а не по заказу:

```sql
SELECT
    c.id AS customer_id,
    c.name AS customer_name,
    COUNT(DISTINCT o.id) AS orders_count,
    SUM(oi.quantity * oi.price) AS total_spent,
    MAX(o.created_at) AS last_order_at
FROM customers c
LEFT JOIN orders o ON o.customer_id = c.id
LEFT JOIN order_items oi ON oi.order_id = o.id
GROUP BY c.id, c.name;
```

Здесь сразу несколько важных решений, каждое из которых опирается на конкретный урок этого уровня:

- Оба соединения — `LEFT JOIN` (четырнадцатый урок), а не `INNER JOIN`, потому что отчёт должен включать **всех** клиентов, включая тех, у кого пока нет ни одного заказа — именно то поведение, которое `INNER JOIN` не мог бы обеспечить.
- `COUNT(DISTINCT o.id)`, а не просто `COUNT(o.id)` или `COUNT(*)` — потому что после соединения с `order_items` строка заказа «размножается» на несколько строк (по числу позиций в нём, ровно как мы видели в пятнадцатом уроке при цепочке из нескольких `JOIN`), и обычный `COUNT` посчитал бы позиции, а не заказы. `DISTINCT` внутри `COUNT` (третий и восьмой уроки) устраняет эти повторы, считая каждый уникальный `order_id` только один раз.
- `MAX(o.created_at)` находит самую позднюю дату среди всех заказов клиента — восьмой урок.
- Для клиента без заказов `COUNT(DISTINCT o.id)` корректно даст `0` (сам `COUNT` не игнорирует ситуацию отсутствия строк, в отличие от `SUM`/`MAX`, что мы разбирали в восьмом уроке), а `SUM` и `MAX` дадут `NULL`, потому что для них нет ни одного значения для агрегирования — это ожидаемое, осмысленное поведение, а не ошибка.

### Шаг 4. Отфильтровать «активных» клиентов через HAVING

Часть задачи — выделить именно клиентов с **больше чем одним** заказом. Это условие описывает свойство целой группы (число заказов клиента), а не отдельной строки, поэтому по правилам десятого урока оно относится к `HAVING`, а не к `WHERE`:

```sql
SELECT
    c.id AS customer_id,
    c.name AS customer_name,
    COUNT(DISTINCT o.id) AS orders_count,
    SUM(oi.quantity * oi.price) AS total_spent,
    MAX(o.created_at) AS last_order_at
FROM customers c
LEFT JOIN orders o ON o.customer_id = c.id
LEFT JOIN order_items oi ON oi.order_id = o.id
GROUP BY c.id, c.name
HAVING COUNT(DISTINCT o.id) > 1;
```

Обратите внимание, что даже при `LEFT JOIN` для клиентов без заказов (`orders_count = 0`) условие `HAVING COUNT(DISTINCT o.id) > 1` корректно отфильтрует их — `0 > 1` даёт `FALSE`, и такие клиенты не попадут в итоговый список «активных», что полностью соответствует смыслу задачи.

### Шаг 5. Добавить категоризацию через CASE и форматирование даты

Финальный штрих — сделать отчёт нагляднее для руководителя, используя `CASE` из седьмого урока для категоризации клиентов по сумме покупок, и `DATE_FORMAT` из пятого урока для читаемого отображения даты последнего заказа:

```sql
SELECT
    c.id AS customer_id,
    c.name AS customer_name,
    COUNT(DISTINCT o.id) AS orders_count,
    SUM(oi.quantity * oi.price) AS total_spent,
    CASE
        WHEN SUM(oi.quantity * oi.price) >= 50000 THEN 'VIP'
        WHEN SUM(oi.quantity * oi.price) >= 10000 THEN 'постоянный'
        ELSE 'обычный'
    END AS customer_tier,
    DATE_FORMAT(MAX(o.created_at), '%d.%m.%Y') AS last_order_date
FROM customers c
LEFT JOIN orders o ON o.customer_id = c.id
LEFT JOIN order_items oi ON oi.order_id = o.id
GROUP BY c.id, c.name
HAVING COUNT(DISTINCT o.id) > 1
ORDER BY total_spent DESC;
```

Здесь `CASE` применён прямо к результату агрегатной функции `SUM(...)`, вычисленному для каждой группы — точно так же, как в девятом уроке мы применяли `ROUND` к результату `AVG`: агрегат, будучи вычислен, становится обычным значением, к которому применимы любые дальнейшие функции и условная логика. Финальный `ORDER BY total_spent DESC` — из девятого урока, где мы уже сортировали по псевдониму агрегата, доступному в `ORDER BY` благодаря логическому порядку выполнения из шестого урока.

### Что показывает этот отчёт о самой природе SQL-аналитики

Обратим внимание на общую структуру получившегося запроса: `FROM` и цепочка `LEFT JOIN` собирают все нужные данные из связанных таблиц (клиенты, заказы, позиции заказов), `GROUP BY` сворачивает эти данные до одной строки на клиента, `HAVING` отбирает нужное подмножество готовых групп, `CASE` в `SELECT` добавляет содержательную интерпретацию цифр, а `ORDER BY` расставляет итоговый результат в осмысленном порядке. Это ровно тот же пятишаговый ритм, который мы видели в логическом порядке выполнения запроса из шестого урока — `FROM → WHERE(отсутствует здесь) → GROUP BY → HAVING → SELECT → ORDER BY` — но теперь применённый не к абстрактному примеру, а к конкретной, содержательной бизнес-задаче. Именно умение собрать одну такую задачу из последовательности уже известных, более простых приёмов — а не знание каждого приёма изолированно — является главным практическим навыком, который стоит вынести с уровня «Начинающий»: на следующем уровне курса, «Средний», тот же навык композиции запросов усложнится подзапросами, CTE и оконными функциями, но сама идея — сборка сложного отчёта из простых, понятных шагов — останется неизменной.

### Резюме

- Реальные аналитические отчёты почти всегда комбинируют несколько тем одновременно: `JOIN` для соединения связанных таблиц, `GROUP BY`/агрегаты для сводной статистики, `HAVING` для отбора нужных групп, `CASE` для содержательной интерпретации чисел, функции дат для читаемого формата.
- При цепочке из нескольких `JOIN`, «размножающих» строки (заказ → позиции заказа), простой `COUNT` посчитает не то, что нужно — для подсчёта уникальных сущностей верхнего уровня (заказов, а не позиций) нужен именно `COUNT(DISTINCT ...)`.
- `LEFT JOIN` на всех уровнях цепочки соединений необходим, если отчёт должен включать сущности без связанных данных (клиентов без заказов) — при этом `HAVING` и агрегаты корректно обрабатывают такие случаи (`COUNT` даёт `0`, `SUM`/`MAX` дают `NULL`).
- `HAVING` фильтрует по свойству уже сформированной группы (число заказов клиента), а не по свойству отдельной строки — ровно то различие, которое мы разбирали в десятом уроке.
- `CASE` и функции форматирования можно применять прямо к результату агрегатной функции внутри одного и того же `SELECT` — агрегат, будучи вычислен, становится обычным значением для дальнейшей обработки.
- Сборка сложного отчёта из последовательности простых, уже известных шагов — ключевой практический навык уровня «Начинающий», который будет развиваться и усложняться на всех следующих уровнях курса.

## Теоретические задания

### Вопрос 1: Почему в сводном отчёте по клиентам урока заказы считаются как `COUNT(DISTINCT o.id)`, а не `COUNT(*)`?

- ✅ После соединения с `order_items` строка заказа «размножается» на строки позиций, и `COUNT(*)` посчитал бы позиции, а не заказы
<!-- DISTINCT внутри COUNT устраняет повторы, созданные размножением JOIN-ом -->
- ❌ `COUNT(*)` запрещён в запросах с `LEFT JOIN` — error_text: не запрещён — он работает, но считает строки результата, а не уникальные заказы. Проблема не в запрете, а в смысле: позиций у одного заказа несколько.
- ❌ `COUNT(DISTINCT)` работает быстрее `COUNT(*)` — error_text: скорость ни при чём: `DISTINCT` здесь — про корректность подсчёта уникальных заказов после «размножения» строк соединением (урок 15 уровня «Начинающий»), а не оптимизация.
- ❌ `COUNT(*)` не учитывает `NULL` и занизил бы число заказов — error_text: наоборот: `COUNT(*)` считает все строки, включая «пустые» пары с `NULL`; игнорирует `NULL` только `COUNT(столбец)` — урок 8 уровня «Начинающий». Обе формы посчитали бы позиции, а не заказы.

### Вопрос 2: Клиент без единого заказа попал в отчёт через `LEFT JOIN`. Что вернут для него `COUNT(DISTINCT o.id)`, `SUM(...)` и `MAX(o.created_at)`?

- ✅ `0`, `NULL` и `NULL` соответственно
<!-- COUNT на пустом наборе возвращает 0; SUM и MAX — NULL -->
- ❌ `NULL`, `NULL` и `NULL` — error_text: `COUNT` — единственный агрегат, возвращающий `0`, а не `NULL`, когда в группе нет ни одной строки (урок 8 уровня «Начинающий»). `SUM` и `MAX` действительно дают `NULL`.
- ❌ `0`, `0` и `NULL` — error_text: ноль для `SUM` — подмена: агрегат без единого значения возвращает `NULL` — «значения нет», а не ноль. Превратить `NULL` в `0` — работа `COALESCE`, если отчёту это нужно.
- ❌ Запрос завершится ошибкой агрегации по пустой группе — error_text: пустая группа — штатный случай: каждый агрегат возвращает своё значение для пустого набора (`COUNT` — 0, `SUM`/`MAX` — `NULL`), никаких ошибок не возникает.

### Вопрос 3: Условие «больше одного заказа» записано как `HAVING COUNT(DISTINCT o.id) > 1`, а не как `WHERE ...`. Почему?

- ✅ Это свойство всей группы (число заказов клиента), которое появляется только после группировки — а `WHERE` фильтрует строки до неё
<!-- HAVING — сито готовых групп: урок 10 уровня «Начинающий» -->
- ❌ Потому что `WHERE` нельзя использовать вместе с `LEFT JOIN` — error_text: можно: `WHERE` спокойно фильтрует строки соединённого набора. Но число заказов существует только после `GROUP BY` — поэтому условие и уезжает в `HAVING`.
- ❌ `HAVING` выполняется раньше `WHERE` и экономит ресурсы на больших таблицах — error_text: порядок обратный: `WHERE` срабатывает до группировки и срезает строки раньше (урок 6 уровня «Начинающий»). Выбор между ними — про смысл условия, а не про скорость.
- ❌ `HAVING` нужен только потому, что `WHERE` не видит алиас `orders_count` из `SELECT` — error_text: недоступность алиаса в `WHERE` — правда (урок 6 уровня «Начинающий»), но причина глубже: условие построено на агрегате, который вычисляется только после группировки. Даже написав его без алиаса, в `WHERE` поставить нельзя — оно описывает свойство готовой группы (урок 10 уровня «Начинающий»).

### Вопрос 4: Клиент без заказов имеет `SUM(...) = NULL`. В какую ветку `CASE WHEN SUM(...) >= 15000 THEN 'VIP' WHEN SUM(...) >= 10000 THEN 'постоянный' ELSE 'обычный' END` он попадёт?

- ✅ В ветку `ELSE`: сравнения `NULL >= 15000` и `NULL >= 10000` дают «неизвестно», и `CASE` переходит к следующей ветке
<!-- сравнение с NULL — «неизвестно»; ELSE — ветка по умолчанию для таких случаев -->
- ❌ Ни в какую: `CASE` вернёт `NULL` и остановится — error_text: `CASE` возвращает `NULL`, только когда ни одна ветка не сработала, а `ELSE` отсутствует (урок 7 уровня «Начинающий»). Здесь `ELSE` есть, а «неизвестно» в `WHEN` означает «проверяй дальше», а не «верни NULL».
- ❌ В ветку `'VIP'`: `NULL` в MySQL считается больше любого числа — error_text: `NULL` не больше и не меньше числа: любое сравнение с ним даёт «неизвестно» — урок 11 уровня «Основы». Поэтому `NULL` проваливается через все `WHEN` до `ELSE`.
- ❌ Запрос завершится ошибкой сравнения `NULL` с числом — error_text: сравнение с `NULL` — не ошибка, а «неизвестно» в трёхзначной логике. `CASE` штатно перебирает ветки дальше и заканчивает на `ELSE` — без ошибок.

### Вопрос 5: Зачем в финальном отчёте `MAX(o.created_at)` обёрнут в `DATE_FORMAT(..., '%d.%m.%Y')`?

- ✅ Функция дат превращает `2026-03-28` в читаемое для человека `28.03.2026`
<!-- DATE_FORMAT — форматирование значения даты: урок 5 уровня «Начинающий» -->
- ❌ Без `DATE_FORMAT` `MAX` вернёт дату в виде числа — error_text: `MAX` по столбцу `DATETIME` возвращает нормальное значение даты. Обёртка — про формат отображения для человека, а не спасение от «числа».
- ❌ Без `DATE_FORMAT` запрос с `GROUP BY` упадёт — error_text: агрегат по дате и группировка никак не требуют форматирования; `DATE_FORMAT` — чисто косметический штрих финального `SELECT`, работать запрос будет и без него.
- ❌ `DATE_FORMAT` переводит дату в часовой пояс пользователя — error_text: функция лишь переупаковывает компоненты даты в строку по шаблону и о часовых поясах не знает ничего — это тема администрирования, а не уровня «Начинающий».

## Практические задания

Каждое задание исполняется в собственной изолированной среде: сид-скрипт
задания создаёт таблицы с нуля, и наборы данных у заданий свои. Состав
может отличаться и от демо-таблиц материала, и от соседних заданий —
опирайтесь на таблицы, описанные в формулировке самого задания.

### Задание 1: Сводка по клиентам

Сквозной отчёт: `LEFT JOIN` + `GROUP BY` + `COUNT(DISTINCT ...)` + `CASE` + `DATE_FORMAT`.

<!-- Эталонное решение (для автора/бота-верификатора): SELECT c.id, c.name, COUNT(DISTINCT o.id) AS orders_count, SUM(oi.quantity * oi.price) AS total_spent, CASE WHEN SUM(oi.quantity * oi.price) >= 15000 THEN 'VIP' WHEN SUM(oi.quantity * oi.price) >= 10000 THEN 'постоянный' ELSE 'обычный' END AS customer_tier, DATE_FORMAT(MAX(o.created_at), '%d.%m.%Y') AS last_order_date FROM customers c LEFT JOIN orders o ON o.customer_id = c.id LEFT JOIN order_items oi ON oi.order_id = o.id GROUP BY c.id, c.name ORDER BY total_spent DESC, c.id; -->

**statement:**

Мини-проект использует таблицы `categories`, `products`, `customers`,
`orders`, `order_items` (все связи — через внешние ключи). Постройте
сводку по клиентам: столбцы `c.id`, `c.name`, `orders_count` (число
заказов — `COUNT(DISTINCT o.id)`: соединение с `order_items` размножает
строку заказа на строки позиций), `total_spent` (через
`SUM(oi.quantity * oi.price)`), `customer_tier` (`CASE`: сумма ≥ 15000 —
`'VIP'`, ≥ 10000 — `'постоянный'`, иначе — `'обычный'`), `last_order_date`
(через `DATE_FORMAT(MAX(o.created_at), '%d.%m.%Y')`). Оба соединения —
`LEFT JOIN`: клиент без заказов (Роман Белов) тоже попадает в отчёт — у
него `orders_count = 0`, `total_spent` и `last_order_date` — `NULL`
(выводится литералом `NULL`), а `CASE` с `NULL`-суммой уходит в ветку
`ELSE`. Группировка по `c.id`, `c.name`; сортировка по убыванию
`total_spent`, при равенстве — по возрастанию `c.id` (строка с `NULL`
в `total_spent` оказывается последней).

**expected_result_text:**

Финальный SELECT возвращает четыре строки. Елена Кузнецова — `VIP`: 3
заказа на 15480.00, последний 28.03.2026. Дмитрий Орлов — «постоянный»:
2 заказа на 10170.00. Светлана Морозова — «обычный»: 2 заказа на 6260.00.
Роман Белов — без заказов: 0, `NULL`, «обычный», `NULL` — и стоит
последним.

**seed_sql:**

```sql
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT NULL,
    name VARCHAR(100) NOT NULL,
    CONSTRAINT fk_categories_parent
        FOREIGN KEY (parent_id) REFERENCES categories(id)
);

INSERT INTO categories (parent_id, name) VALUES
    (NULL, 'Периферия'),
    (NULL, 'Аксессуары');

CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_products_category
        FOREIGN KEY (category_id) REFERENCES categories(id)
);

INSERT INTO products (category_id, name, price) VALUES
    (1, 'Мышь беспроводная', 1590.00),
    (1, 'Клавиатура механическая', 4290.00),
    (2, 'USB-хаб', 990.00),
    (2, 'Коврик для мыши', 490.00),
    (1, 'Веб-камера', 2790.00);

CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    city VARCHAR(50) NOT NULL
);

INSERT INTO customers (name, city) VALUES
    ('Елена Кузнецова', 'Воронеж'),
    ('Дмитрий Орлов', 'Пермь'),
    ('Светлана Морозова', 'Тула'),
    ('Роман Белов', 'Ижевск');

CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    created_at DATE NOT NULL,
    CONSTRAINT fk_orders_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id)
);

INSERT INTO orders (customer_id, created_at) VALUES
    (1, '2026-01-12'),
    (2, '2026-01-25'),
    (1, '2026-02-08'),
    (3, '2026-02-19'),
    (2, '2026-03-03'),
    (3, '2026-03-15'),
    (1, '2026-03-28');

CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_items_order
        FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT fk_items_product
        FOREIGN KEY (product_id) REFERENCES products(id)
);

INSERT INTO order_items (order_id, product_id, quantity, price) VALUES
    (1, 1, 1, 1590.00),
    (1, 3, 2, 990.00),
    (2, 2, 1, 4290.00),
    (3, 1, 2, 1590.00),
    (3, 4, 3, 490.00),
    (4, 2, 1, 4290.00),
    (4, 3, 1, 990.00),
    (5, 1, 1, 1590.00),
    (5, 2, 1, 4290.00),
    (6, 4, 2, 490.00),
    (7, 2, 1, 4290.00),
    (7, 3, 3, 990.00);
```

**expected_rows:**

| id | name              | orders_count | total_spent | customer_tier | last_order_date |
| -- | ----------------- | ------------ | ----------- | ------------- | --------------- |
| 1  | Елена Кузнецова   | 3            | 15480.00    | VIP           | 28.03.2026      |
| 2  | Дмитрий Орлов     | 2            | 10170.00    | постоянный    | 03.03.2026      |
| 3  | Светлана Морозова | 2            | 6260.00     | обычный       | 15.03.2026      |
| 4  | Роман Белов       | 0            | NULL        | обычный       | NULL            |

**runtime:** mysql

### Задание 2: Выручка по месяцам

Отчёт по периодам: `INNER JOIN` + месячный ключ `DATE_FORMAT` + `GROUP BY` + `HAVING`.

<!-- Эталонное решение (для автора/бота-верификатора): SELECT DATE_FORMAT(o.created_at, '%Y-%m') AS month_key, COUNT(DISTINCT o.id) AS orders_count, SUM(oi.quantity * oi.price) AS revenue FROM orders o INNER JOIN order_items oi ON oi.order_id = o.id GROUP BY DATE_FORMAT(o.created_at, '%Y-%m') HAVING SUM(oi.quantity * oi.price) >= 9000 ORDER BY month_key; -->

**statement:**

Мини-проект использует те же таблицы `categories`, `products`,
`customers`, `orders`, `order_items`. Постройте отчёт о выручке по
месяцам: столбцы `month_key` (через `DATE_FORMAT(o.created_at, '%Y-%m')`),
`orders_count` (число заказов месяца — `COUNT(DISTINCT o.id)`), `revenue`
(через `SUM(oi.quantity * oi.price)`). Соединение `orders` с `order_items`
— `INNER JOIN` по `oi.order_id = o.id`; группировка по месячному ключу;
оставьте только месяцы с выручкой не менее 9000.00 — условие в `HAVING`
по `SUM(oi.quantity * oi.price)`. Сортировка по возрастанию `month_key`.

**expected_result_text:**

Финальный SELECT возвращает две строки. Январь (выручка 7860.00 — заказы
на 3570.00 и 4290.00) отсеян `HAVING`; в отчёте февраль с двумя заказами
на 9930.00 и март с тремя заказами на 14120.00.

**seed_sql:**

```sql
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT NULL,
    name VARCHAR(100) NOT NULL,
    CONSTRAINT fk_categories_parent
        FOREIGN KEY (parent_id) REFERENCES categories(id)
);

INSERT INTO categories (parent_id, name) VALUES
    (NULL, 'Периферия'),
    (NULL, 'Аксессуары');

CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_products_category
        FOREIGN KEY (category_id) REFERENCES categories(id)
);

INSERT INTO products (category_id, name, price) VALUES
    (1, 'Мышь беспроводная', 1590.00),
    (1, 'Клавиатура механическая', 4290.00),
    (2, 'USB-хаб', 990.00),
    (2, 'Коврик для мыши', 490.00),
    (1, 'Веб-камера', 2790.00);

CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    city VARCHAR(50) NOT NULL
);

INSERT INTO customers (name, city) VALUES
    ('Елена Кузнецова', 'Воронеж'),
    ('Дмитрий Орлов', 'Пермь'),
    ('Светлана Морозова', 'Тула'),
    ('Роман Белов', 'Ижевск');

CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    created_at DATE NOT NULL,
    CONSTRAINT fk_orders_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id)
);

INSERT INTO orders (customer_id, created_at) VALUES
    (1, '2026-01-12'),
    (2, '2026-01-25'),
    (1, '2026-02-08'),
    (3, '2026-02-19'),
    (2, '2026-03-03'),
    (3, '2026-03-15'),
    (1, '2026-03-28');

CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_items_order
        FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT fk_items_product
        FOREIGN KEY (product_id) REFERENCES products(id)
);

INSERT INTO order_items (order_id, product_id, quantity, price) VALUES
    (1, 1, 1, 1590.00),
    (1, 3, 2, 990.00),
    (2, 2, 1, 4290.00),
    (3, 1, 2, 1590.00),
    (3, 4, 3, 490.00),
    (4, 2, 1, 4290.00),
    (4, 3, 1, 990.00),
    (5, 1, 1, 1590.00),
    (5, 2, 1, 4290.00),
    (6, 4, 2, 490.00),
    (7, 2, 1, 4290.00),
    (7, 3, 3, 990.00);
```

**expected_rows:**

| month_key | orders_count | revenue  |
| --------- | ------------ | -------- |
| 2026-02   | 2            | 9930.00  |
| 2026-03   | 3            | 14120.00 |

**runtime:** mysql

### Задание 3: Товары — продажи и сегменты

Отчёт по товарам: смешение `INNER`/`LEFT JOIN` в цепочке + `CASE` на агрегате + даты + товар без продаж через `LEFT JOIN`.

<!-- Эталонное решение (для автора/бота-верификатора): SELECT p.id, p.name AS product_name, cat.name AS category_name, SUM(oi.quantity) AS units_sold, CASE WHEN SUM(oi.quantity) >= 6 THEN 'лидер' WHEN SUM(oi.quantity) >= 3 THEN 'стабильный' ELSE 'без продаж' END AS sales_segment, DATE_FORMAT(MAX(o.created_at), '%d.%m.%Y') AS last_sale_date FROM products p INNER JOIN categories cat ON p.category_id = cat.id LEFT JOIN order_items oi ON oi.product_id = p.id LEFT JOIN orders o ON o.id = oi.order_id GROUP BY p.id, p.name, cat.name ORDER BY p.id; -->

**statement:**

Мини-проект использует те же таблицы `categories`, `products`,
`customers`, `orders`, `order_items`. Постройте отчёт по каждому товару:
столбцы `p.id`, `p.name` с алиасом `product_name`, `cat.name` с алиасом
`category_name`, `units_sold` (продано штук — `SUM(oi.quantity)`),
`sales_segment` (`CASE`: продано ≥ 6 — `'лидер'`, ≥ 3 — `'стабильный'`,
иначе — `'без продаж'`), `last_sale_date` (через
`DATE_FORMAT(MAX(o.created_at), '%d.%m.%Y')`). В отчёт входят все товары,
включая ни разу не проданные: соединения — `products INNER JOIN
categories cat ON p.category_id = cat.id`, затем `LEFT JOIN order_items
oi ON oi.product_id = p.id` и `LEFT JOIN orders o ON o.id = oi.order_id`.
У товара без продаж `units_sold` и `last_sale_date` — `NULL` (выводится
литералом `NULL`), а сегмент — `'без продаж'`: `CASE` с `NULL` уходит в
ветку `ELSE`. Группировка по `p.id`, `p.name`, `cat.name`; сортировка по
возрастанию `p.id`.

**expected_result_text:**

Финальный SELECT возвращает пять строк. USB-хаб — «лидер» (6 штук,
последняя продажа 28.03.2026); мышь, клавиатура и коврик — «стабильные»
(4, 4 и 5 штук); веб-камера не продана ни разу — `NULL`, «без продаж»,
`NULL`, но из отчёта не пропала благодаря `LEFT JOIN`.

**seed_sql:**

```sql
CREATE TABLE categories (
    id INT AUTO_INCREMENT PRIMARY KEY,
    parent_id INT NULL,
    name VARCHAR(100) NOT NULL,
    CONSTRAINT fk_categories_parent
        FOREIGN KEY (parent_id) REFERENCES categories(id)
);

INSERT INTO categories (parent_id, name) VALUES
    (NULL, 'Периферия'),
    (NULL, 'Аксессуары');

CREATE TABLE products (
    id INT AUTO_INCREMENT PRIMARY KEY,
    category_id INT NOT NULL,
    name VARCHAR(150) NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_products_category
        FOREIGN KEY (category_id) REFERENCES categories(id)
);

INSERT INTO products (category_id, name, price) VALUES
    (1, 'Мышь беспроводная', 1590.00),
    (1, 'Клавиатура механическая', 4290.00),
    (2, 'USB-хаб', 990.00),
    (2, 'Коврик для мыши', 490.00),
    (1, 'Веб-камера', 2790.00);

CREATE TABLE customers (
    id INT AUTO_INCREMENT PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    city VARCHAR(50) NOT NULL
);

INSERT INTO customers (name, city) VALUES
    ('Елена Кузнецова', 'Воронеж'),
    ('Дмитрий Орлов', 'Пермь'),
    ('Светлана Морозова', 'Тула'),
    ('Роман Белов', 'Ижевск');

CREATE TABLE orders (
    id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    created_at DATE NOT NULL,
    CONSTRAINT fk_orders_customer
        FOREIGN KEY (customer_id) REFERENCES customers(id)
);

INSERT INTO orders (customer_id, created_at) VALUES
    (1, '2026-01-12'),
    (2, '2026-01-25'),
    (1, '2026-02-08'),
    (3, '2026-02-19'),
    (2, '2026-03-03'),
    (3, '2026-03-15'),
    (1, '2026-03-28');

CREATE TABLE order_items (
    id INT AUTO_INCREMENT PRIMARY KEY,
    order_id INT NOT NULL,
    product_id INT NOT NULL,
    quantity INT NOT NULL,
    price DECIMAL(10,2) NOT NULL,
    CONSTRAINT fk_items_order
        FOREIGN KEY (order_id) REFERENCES orders(id),
    CONSTRAINT fk_items_product
        FOREIGN KEY (product_id) REFERENCES products(id)
);

INSERT INTO order_items (order_id, product_id, quantity, price) VALUES
    (1, 1, 1, 1590.00),
    (1, 3, 2, 990.00),
    (2, 2, 1, 4290.00),
    (3, 1, 2, 1590.00),
    (3, 4, 3, 490.00),
    (4, 2, 1, 4290.00),
    (4, 3, 1, 990.00),
    (5, 1, 1, 1590.00),
    (5, 2, 1, 4290.00),
    (6, 4, 2, 490.00),
    (7, 2, 1, 4290.00),
    (7, 3, 3, 990.00);
```

**expected_rows:**

| id | product_name           | category_name | units_sold | sales_segment | last_sale_date |
| -- | ---------------------- | ------------- | ---------- | ------------- | -------------- |
| 1  | Мышь беспроводная      | Периферия     | 4          | стабильный    | 03.03.2026     |
| 2  | Клавиатура механическая | Периферия    | 4          | стабильный    | 28.03.2026     |
| 3  | USB-хаб                | Аксессуары    | 6          | лидер         | 28.03.2026     |
| 4  | Коврик для мыши        | Аксессуары    | 5          | стабильный    | 15.03.2026     |
| 5  | Веб-камера             | Периферия     | NULL       | без продаж    | NULL           |

**runtime:** mysql
