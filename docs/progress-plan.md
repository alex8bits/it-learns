# План внедрения остального (Этапы 7+)

> Опорный документ. Продолжение `docs/platform-plan.md` (Этапы 0–6 сданы:
> инфраструктура, auth, роли/админка, премиум+оплата на dummy-гейте,
> ИИ-каркас с промптами и лимитами, SQLite-изоляция практики, каталог
> курсов с админ-CRUD). Домен — `docs/concept.md`; правила разработки —
> `AGENTS.md`. Здесь только порядок и содержание следующих этапов.
>
> Нумерация Этапов 7–8 наследует `platform-plan.md`. Его «Этап 9
> (премиум-гейтинг практики и ИИ)» поглощён нашим Этапом 8 — middleware
> `EnsurePremium` уже существует со времён Этапа 3, отдельно его
> выносить некуда. Бывший «Этап 3.1» (боевой платёжный гейт) стал
> Этапом 9 этого плана.

## Сквозные принципы (без изменений)

- Все 20 правил `AGENTS.md` — non-negotiable: FormRequest-валидация,
  тонкие контроллеры, Action/Service для бизнес-логики, enum'ы,
  Policy/Gate, `DB::transaction` для мутаций в 2+ таблицы, audit-лог
  каждой админ-операции, Resource для API.
- Тестовая политика §3.2 `AGENTS.md`: Unit — максимальное покрытие
  веток, Feature — только smoke HTTP-границ.
- Каждый этап заканчивается зелёными `php artisan test` / `pint` /
  `phpstan` + ручным smoke в браузере.
- Все новые поверхности — `web` (пользовательская зона) или `admin`;
  версионированное `api v1` расширяется только при реальной
  потребности внешних потребителей.

## Текущее состояние (точка отсчёта)

Готово и переиспользуется дальше:

- Каркас практики: `PracticeEnvironmentManager`,
  `LocalSqlitePracticeEnvironment` (6 защит), `RunPracticeTaskAction`
  (try/finally), `CanonicalResultSerializer`, HTTP-заглушка
  `POST /practice-tasks/{task}/submit` (opaque id, без model binding).
- Каркас ИИ: `LlmClient` (+5 реализаций), `PromptResolver`,
  `PromptVersionService`, `AiFeedbackService`,
  `AiTaskGeneratorService` (сервисные методы без UI),
  `AiLimitGuard` + `AiTokenUsageService`, DTO `FeedbackInput` /
  `ExtraTaskInput` / `GeneratedExtraTask`.
- Премиум: `EnsurePremium`, `SubscriptionService`,
  `DummyPaymentGateway` + `YooKassaPaymentGateway` (Этап 9, боевой
  гейт ЮKassa) + фабрика гейтов.
- Прохождение (Этап 7, реализовано): `LessonCompletionChecker`,
  `StartCourse`, `AnswerTheoryTask`, страница урока `/lessons/{slug}`
  (материал + quiz, Inertia), `POST /courses/{course}/start`
  (начать/продолжить — один эндпоинт), `POST /theory-tasks/{task}/answer`
  (`throttle:theory-answer`), прогресс в карточке курса (percent
  вычисляется на чтении), гость → redirect на `/login`; админ-CRUD
  теоретических заданий с audit (`TheoryTaskCreated/Updated/Deleted`).

Отложенные хвосты Этапов 0–6 (не блокируют Этап 7): сортировка
каталога, WebP-конверсия превью + queue-job, playground промптов,
предпросмотр курса «глазами пользователя» — собраны в Этап 11.

## Порядок выполнения и зависимости

| Этап | Название                              | Зависит от | Размер |
| ---- | ------------------------------------- | ---------- | ------ |
| 7    | Теоретические задания и прогресс      | 6 (сдан)   | большой |
| 8    | Практика + ИИ + премиум-гейтинг       | 7, 5, 4    | большой |
| 9    | Боевой платёжный гейт (быв. 3.1)      | 3 (сдан)   | средний |
| 10   | Docker-изоляция практики              | 8          | большой |
| 11   | Эксплуатационные хвосты (quick wins)  | —          | малый   |

Этап 9 не зависит от 7–8 — его можно выполнять параллельно или в любой
момент (провайдер выбран и подключён, см. статус Этапа 9). Этап 11
можно втыкать между большими этапами как «разгрузку».

---

## Этап 7. Теоретические задания и прогресс

> **Статус: реализован (сдан).** Основная часть «Что делаем» выполнена
> (5 миграций, enum'ы, модели/фабрики, `LessonCompletionChecker`,
> `StartCourse`, `AnswerTheoryTask`, роуты auth-зоны, админ-CRUD
> теории с audit, Vue-страница урока, прогресс в карточке курса) —
> с двумя осознанными ограничениями: кнопка «Начать/Продолжить»
> реализована только в карточке курса (`/courses/{slug}`), в сетке
> каталога её нет; отдельного Action `ResolveCurrentLesson(User,
> Course)` не существует — резолв текущего урока инкапсулирован
> private-методом внутри `app/Actions/Progress/StartCourse.php`.
> Открытые вопросы этапа закрыты ниже; домен зафиксирован в
> `docs/concept.md §4, §6`.

**Цель:** пользователь авторизуется, жмёт «Начать/Продолжить» на курсе,
читает материал урока, отвечает на теоретические вопросы (radio, один
правильный вариант), платформа ведёт прогресс и умеет возобновлять
прохождение с места остановки.

**Что делаем:**

1. **Enum'ы:** `TheoryTask`-объём не требует новых статусов кроме
   прогресса: `LessonProgressStatus { InProgress, Completed }`,
   `CourseProgressStatus { InProgress, Completed }` (статус «не начат»
   не храним — отсутствие записи и есть «не начат»).
2. **Миграции:**
   - `theory_tasks`: `lesson_id` FK, `question` (text), `order` (int),
     `is_published` (bool), timestamps.
   - `theory_task_options`: `theory_task_id` FK, `text` (text),
     `is_correct` (bool), `error_text` (text nullable — объяснение,
     почему вариант неверный), `order` (int), timestamps.
   - `user_theory_task_answers`: `user_id` FK, `theory_task_id` FK,
     `option_id` FK, `is_correct` (bool — снимок на момент ответа),
     `answered_at`. Unique `(user_id, theory_task_id)` — один итоговый
     ответ на задачу (перепройждение перезаписывает, историю попыток
     на старте не храним).
   - `user_lesson_progress`: `user_id` FK, `lesson_id` FK, `status`
     (enum), `started_at`, `completed_at` nullable, timestamps. Unique
     `(user_id, lesson_id)`.
   - `user_course_progress`: `user_id` FK, `course_id` FK, `status`
     (enum), `current_lesson_id` FK nullable (указатель возобновления),
     timestamps. Unique `(user_id, course_id)`.
3. **Модели/фабрики/сидеры:** relations + scopes (`published`,
   `ordered`), `TheoryTaskFactory` (+`withOptions()`), фабрики
   прогресса. `DemoCourseSeeder` дополняется теоретическими заданиями
   (2–3 вопроса в первом уроке).
4. **Бизнес-логика (Action-классы, всё в транзакциях):**
   - `StartCourse(User, Course)` — создаёт `user_course_progress`,
     возвращает первый незавершённый урок (первый опубликованный урок
     курса при старте).
   - `AnswerTheoryTask(User, TheoryTask, TheoryTaskOption)` — проверяет
     вариант, пишет `user_theory_task_answers`, апдейтит
     `user_lesson_progress` (InProgress → при полной теории —
     Completed), `user_course_progress.current_lesson_id`.
   - `ResolveCurrentLesson(User, Course)` — вычисление «где остановился»
     (первый незавершённый урок).
   - Условие завершения урока инкапсулируется в одном месте
     («все опубликованные теоретические задачи отвечены верно») так,
     чтобы Этап 8 расширил его практикой, а не переписал.
5. **Процент прохождения:** на старте — вычисляем на чтении из
   нижележащих сущностей (без колонки `percent`, см. открытие вопроса
   ниже), ViewModel/Resource отдаёт `percent` для UI.
6. **Роуты (auth-зона):**
   - `POST /courses/{course}/start` — начать/возобновить (редирект на
     урок).
   - `GET /lessons/{lesson:slug}` — страница урока: материал + теория.
   - `POST /theory-tasks/{task}/answer` (throttle) — ответ.
   - Каталог и карточка курса получают кнопки «Начать»/«Продолжить»,
     карточка — индикатор прогресса.
7. **Доступ:** материал урока — только авторизованные (бесплатный
   тариф включает прохождение, концепт §2.2); гость, открывший урок, —
   редирект на login. Опубликованность (`is_published`) проверяется
   скоупом, не в контроллере.
8. **Админ:** CRUD теоретических заданий внутри урока
   (`/admin/lessons/{lesson}/theory-tasks`), Actions
   `Create/Update/DeleteTheoryTask` с audit-записями; расширить
   `AdminAuditAction` (`TheoryTaskCreated/Updated/Deleted`).
9. **Vue-страницы:** `Lessons/Show.vue` (материал + вопрос с radio,
   ошибка с `error_text` при неверном, переход к следующему вопросу),
   прогресс в `Courses/Show.vue`, кнопка «Продолжить» на дашборде
   (опционально, если успевает).
10. **Тесты:** Unit — все Action'ы (правильный/неправильный ответ,
    повторный ответ, завершение урока, resume-указатель, транзакции),
    скоупы, Policy, FormRequest'ы, enum'ы. Feature-smoke — страницы
    отвечают 200, ответ записывается, гость перенаправлен на login.

**Готовность:**

- Зарегистрированный пользователь проходит демо-курс: материал →
  вопросы → урок отмечается пройденным; выход/возврат возобновляет с
  того же места.
- Прогресс виден в карточке курса; черновики уроков/заданий недоступны.
- Админ создаёт/редактирует теоретические задания, audit-лог пишется.
- `php artisan test`, `pint`, `phpstan` — зелёные.

**Открытые вопросы этапа:**

- ~~Хранить `percent` в `user_course_progress` (денормализация) или
  вычислять на чтении (предложение — вычислять, обновим `concept.md`
  при фиксации).~~ — закрыто: **вычисляем на чтении** (завершённые
  опубликованные уроки / все опубликованные уроки), колонки `percent`
  нет (см. `concept.md §6`).
- ~~Блокировать ли следующий урок до прохождения текущего (концепт
  требует последовательность заданий внутри урока; между уроками не
  зафиксировано).~~ — закрыто: **свободная навигация** между
  опубликованными уроками (последовательность — только внутри урока);
  гейтинга между уроками нет.
- ~~Показывать ли правильный ответ после N неверных попыток.~~ —
  закрыто: **не показываем** — только `error_text` выбранного неверного
  варианта; `is_correct` не покидает сервер (options маппятся в
  `{id, text}`).

---

## Этап 8. Практические задания + ИИ + премиум-гейтинг

**Цель:** после теории урока пользователь решает практические SQL-задачи
в изолированной среде; при неверном решении премиум-пользователь может
запросить ИИ-фидбэк и генерацию доп. задачи; лимиты токенов отражаются
как HTTP 429.

**Что делаем:**

1. **Миграции:**
   - `practice_tasks`: `lesson_id` FK, `statement` (text — что нужно
     сделать), `expected_result_text` (text — что должно получиться,
     для показа студенту), `seed_sql` (longtext nullable — сидинг
     SQLite-среды), `expected_hash` (char(64) — хеш эталонного набора
     строк через `CanonicalResultSerializer`), `order` (int),
     `is_published` (bool), timestamps.
   - `practice_task_submissions`: `user_id` FK, `practice_task_id` FK,
     `code` (text), `status` (существующий `PracticeAttemptStatus`),
     `result_diff` (json nullable — эталон vs попытка), `error_text`
     (text nullable), `duration_ms` (int nullable), `created_at`.
   - `practice_task_feedbacks`: `practice_task_submission_id` FK,
     `user_id` FK, `body` (text — фидбэк ИИ), `created_at`.
2. **Админ:** CRUD `practice_tasks` внутри урока; эталонный набор строк
   вводится как JSON и хешируется при сохранении (`expected_hash`
   пересчитывается в Action). Actions с audit
   (`PracticeTaskCreated/Updated/Deleted`).
3. **Пользовательский флоу:**
   - Практика открывается в уроке после завершения теории
     (последовательность по концепту §3.3).
   - Существующая HTTP-заглушка `POST /practice-tasks/{task}/submit`
     переводится на route model binding `PracticeTask` + авторизацию
     (auth + published + принадлежность к доступному уроку); внутри —
     готовый `RunPracticeTaskAction` + `PracticeTaskInput` DTO,
     запись `practice_task_submissions`, дифф в ответе.
   - `Busy` (анти-DoS lock) и `Error` ветки отражаются в UI.
4. **Завершение урока:** условие из Этапа 7 расширяется — «теория
   отвечена + все опубликованные practice-задачи имеют Passed».
5. **ИИ (только премиум):**
   - Роуты `POST /practice-tasks/{task}/ai-feedback` и
     `POST /practice-tasks/{task}/ai-extra-task` под
     `['auth', EnsurePremium]` + throttle.
   - Вызывают готовые `AiFeedbackService` / `AiTaskGeneratorService`
     (DTO строятся из submission + `courses.ai_course_prompt` через
     `PromptResolver`); фидбэк сохраняется в
     `practice_task_feedbacks`, доп. задача показывается в UI
     (сохранение — открытый вопрос).
   - `AiLimitExceededException` → HTTP 429: маппинг в
     `bootstrap/app.php → withExceptions` (решение от 2026-09-14).
6. **Playground промптов (хвост Этапа 4, опционально сюда):** страница
   `/admin/prompts/playground` — отправить пример запроса к активному
   `LlmClient` и увидеть ответ (только Admin, audit-запись).
7. **Тесты:** Unit — привязка харнеса к реальному заданию (success /
   fail / error / busy), запись submission, завершение урока с
   практикой, 429-маппинг исключения, гейтинг (не-премиум → 403,
   премиум → сервис вызван), сохранение фидбэка. Feature-smoke —
   сабмит через HTTP, ИИ-роуты отвечают, админ-CRUD открывается.

**Готовность:**

- Полный цикл демо-курса: теория → практика (верный SQL → Passed,
  неверный → дифф) → урок Completed → курс 100%.
- Премиум получает ИИ-фидбэк и доп. задачу; free — 403; исчерпание
  лимита — 429 без ретраев.
- Админ управляет практическими заданиями, audit-лог пишется.
- Все проверки зелёные.

**Открытые вопросы этапа:**

- Сохранять ли сгенерированные доп. задачи (история) или только
  показывать (предложение — только показывать, минус сущность).
- Ограничение числа попыток на задание (концепт: «блокировать переход
  до корректного результата» — блокируем переход, но не попытки).

---

## Этап 9. Боевой платёжный гейт (быв. Этап 3.1)

> **Статус: реализован (сдан).** Выполнено: `YooKassaPaymentGateway`
> (прямой HTTP через `Http::` фасад, без SDK), whitelist в
> `config/payments.php` + env-ключи `YOOKASSA_*` в `.env.example`,
> boot-валидация креденшалов (fail-loud), webhook-верификация
> re-fetch'ем `GET /v3/payments/{id}` (тело уведомления не
> доверяется: активирует только платёж, лично увиденный как
> `status=succeeded` + `paid=true`), активация только через webhook
> (return-URL несёт одноразовый uuid и физически не может активировать
> подписку), refund: `RefundPayment` + audit `PaymentRefunded` +
> `POST /admin/payments/{payment}/refund`.
> Осознанные ограничения (non-goals дизайна): автопродление и trial
> не реализованы (остаются открытыми вопросами ниже), мультивалютность
> нет (только RUB), `payment.canceled` не обрабатывается (Pending
> sweep'ится при следующем checkout), refund не отзывает доступ до
> `ends_at`, `PaymentManuallyMarked` не реализован (YAGNI).
> **Ручная приёмка** (критерий готовности — тестовый мерчант ЮKassa:
> оплата → подписка Active → запись в `/admin/payments`; refund →
> статус `Refunded` + audit в `/admin/audit-logs`) — выполняется
> владельцем после сдачи. Чеклист: настроить в личном кабинете ЮKassa
> webhook-URL `https://<публичный хост>/subscription/webhook`,
> выставить `PAYMENT_PROVIDER=yookassa` + креденшалы тестового
> магазина (`YOOKASSA_SHOP_ID` / `YOOKASSA_SECRET_KEY`).

**Цель:** реальная оплата премиум-подписки. Не зависит от Этапов 7–8,
можно выполнять параллельно.

**Что делаем:**

1. Выбор провайдера (ЮKassa - добавляем её на этом этапе, остальные вне этого плана / Robokassa / CloudPayments / Stripe / …) —
   **решение пользователя** (открытый вопрос `concept.md §9`).
2. Новая реализация `PaymentGateway` + строка в whitelist
   `config/payments.php` + ключи в `.env.example` — без правок
   `SubscriptionService`/контроллеров/фронта (паттерн зафиксирован в
   Этапе 3).
3. Webhook провайдера: приём, верификация подписи, идемпотентность
   (инфраструктура `/subscription/webhook` уже готова).
4. Refund: реализация `refund()` + audit `PaymentRefunded`; ручные
   операции админа (`PaymentManuallyMarked`) — если нужны.
5. Валюты/региональные ограничения и автопродление (по потребности).

**Готовность:** тестовая транзакция на тестовом мерчанте провайдера
проходит end-to-end (оплата → подписка Active → webhook → запись в
`/admin/payments`); refund возвращается и отражается.

**Открытые вопросы:** ~~провайдер~~ (закрыт: ЮKassa, Этап 9);
автопродление (требует рекуррентных платежей провайдера); пробный
период (trial) — нужен ли.

---

## Этап 10. Docker-изоляция практики

> **Статус: реализован (сдан).** Выполнено: `DockerPracticeEnvironment`
> (вторая реализация контракта `PracticeEnvironmentManager`; эфемерные
> контейнеры `mysql:8`/`postgres:16` через CLI-клиент `docker` поверх
> `Process`-фасада — ноль новых зависимостей), whitelist `docker` в
> `config/practice.php` + fail-loud boot-валидация полноты конфига
> (доступность демона на boot НЕ проверяется — ловится на provision),
> расширение `PracticeRuntime` (`Mysql`/`Postgres`) + per-task runtime:
> колонка `practice_tasks.runtime` (default `sqlite`), поле DTO
> `PracticeTaskInput`, select в админ-CRUD практики с audit-метой;
> гварды драйвера (`--network none`, `--memory`/`--cpus`/`--pids-limit`,
> single-statement splitter, per-runtime blacklist метакоманд,
> provision/execution-таймауты, лимит размера результата); слоты
> конкурентности `practice.concurrency.max_environments` +
> обобщённый `practice.lock_ttl_seconds`; pruner
> `practice:prune-environments` (`everyFiveMinutes`, TTL 30 мин, sweep
> и контейнеров, и незавершённых строк `practice_environments`);
> structured-логи `practice.environment_*`.
> Осознанные ограничения (non-goals дизайна): Python/Bash-рантаймы
> отложены (контракт сравнивает табличные результаты, контент-плана
> нет), SQLite-in-Docker нет (SQLite остаётся на `local-sqlite`),
> waiting-queue исполнения нет — при насыщении слотов сразу `Busy`,
> integration-тесты с реальным Docker в основном сюите нет (CI нет) —
> ручная приёмка ниже, кастомные образы не поддерживаются (только
> официальные `mysql:8`/`postgres:16`), смешанный sqlite+mysql контент
> в одной инсталляции невозможен — драйвер глобальный
> (`PRACTICE_DRIVER`), несоответствие драйвера и рантайма задачи = fail
> loud (HTTP 500, ошибка конфигурации контента, не студенческая
> ошибка).
> **Ручная приёмка** (машина с Docker Desktop; выполняется владельцем
> после сдачи): выставить `PRACTICE_DRIVER=docker` → admin-CRUD создаёт
> MySQL-задание с `seed_sql` и `expected_rows` → сабмит студентом
> проходит end-to-end (Passed/Failed/дифф) → `docker ps -a` пуст после
> `finally` → `kill -9` php-процесса mid-run → `schedule:work`/
> ожидание расписания подчищает контейнер за TTL → вернуть
> `PRACTICE_DRIVER=local-sqlite` — все тесты зелёные.

**Цель:** прод-безопасная среда исполнения практики + новые рантаймы
(MySQL, PostgreSQL, Python, Bash) — по мере появления контента.

**Что делаем:**

1. `DockerPracticeEnvironment` — реализация контракта
   `PracticeEnvironmentManager` (эфемерные контейнеры `mysql:8` /
   `postgres:16` / `python:3.12` и т.п.), переключение через
   `config('practice.driver')` — без правок Action/Controller.
2. Расширение `PracticeRuntime` (сейчас только `Sqlite`).
3. Лимиты по памяти/CPU на контейнер; сидинг БД задания; таймауты.
4. Очистка висящих контейнеров: scheduler-команда + destroy в
   `finally` (инвариант BLOCKER сохраняется).
5. Общая очередь исполнения (анти-DoS: не клепать N контейнеров на
   пользователя параллельно) и метрики.
6. Тесты: Unit — контракт реализации (мок Docker-клиента), destroy в
   finally, лимиты; integration-тесты с реальным Docker — отдельное
   решение (CI нет, локально).

**Готовность:** SQL-задание выполняется в Docker-контейнере на
dev-машине с Docker, переключение драйвера — одна строка конфига,
висящие контейнеры чистятся по расписанию.

**Открытые вопросы:** какие рантаймы нужны реально (зависит от
контент-плана); лимиты размеров/времени под прод-нагрузку.

---

## Этап 11. Эксплуатационные хвосты (quick wins)

> **Статус: реализован (сдан).** Выполнено: сортировка каталога —
> колонка `courses.sort_order` (unsigned int, default 0, indexed,
> forward-миграция), `Course::scopeOrdered` теперь
> `sort_order ASC, created_at DESC` (tie-breaker сохраняет legacy-порядок
> «сначала новые» при всех `sort_order = 0`), поле «Порядок в каталоге» в
> админ-формах Create/Edit + колонка «Порядок» в списке, аудит —
> существующими `CourseCreated`/`CourseUpdated` (meta дополнена
> `sort_order`); WebP-конверсия превью — первый queue-job проекта
> `ConvertCoursePreviewToWebp` (`$afterCommit`, идемпотентен: уже-`.webp`,
> нет курса, нет пути или невозможная конверсия — no-op; без воркера
> превью остаётся в исходном валидном формате — graceful),
> `PreviewImageProcessor::convertToWebp` (GD, q82, без ресайза — размеры
> уже ≤1200×630 от синхронного `process()`), диспатч из
> `CreateCourse`/`UpdateCourse` после коммита при не-WebP превью;
> предпросмотр курса «глазами пользователя» — read-only GET-роуты
> `/admin/courses/{course}/preview` и
> `/admin/courses/{course}/preview/lessons/{lesson}`
> (`admin.courses.preview.*`), `CoursePolicy::preview` (admin), те же
> пользовательские Vue-страницы `Courses/Show` / `Lessons/Show` с
> `previewMode` (баннер предпросмотра, действия скрыты, черновики видны
> с бейджем, прогресс не пишется — POST-роутов нет), audit не пишется
> (read-only операция); playground промптов — `/admin/prompts/playground`
> (`admin.prompts.playground` + `playground.run` под
> `throttle:ai-playground` 5/min per-user), Action `RunPromptPlayground`:
> полный пайплайн guard → resolve → complete → `DB::transaction`
> { recordUsage (`AiTokenUsageAction::Playground`) + audit
> `PromptPlaygroundRun` — 24-й кейс `AdminAuditAction` }.
> Осознанные ограничения (non-goals дизайна): «сортировки по популярности»
> и счётчиков просмотров нет (только ручной `sort_order`), drag-n-drop
> нет (number-поле достаточно), `sort_order` не экспонируется в публичный
> API-шейп `CourseResource` (порядок применяется на сервере одинаково
> для web/дашборда/API v1), ретро-конверсия уже загруженных превью не
> делается (новые конвертируются по мере замены файлов),
> impersonation и интерактивные действия в предпросмотре нет
> (`is_correct` не покидает сервер — spoiler-гварды сохранены), обхода
> лимитов для админов в playground нет (429 при исчерпании; бюджет
> поднимается существующим механизтом `/admin/users/{user}/llm-limit`),
> diff-инструмент версий и A/B-тестирование в playground нет.
> **Ручная приёмка** (выполняется владельцем после сдачи): порядок в
> каталоге отражает правку `sort_order` (web + дашборд +
> `/api/v1/courses`); при запущенном `composer dev` (воркер
> `queue:listen`) новое jpeg/png-превью становится `.webp`, путь в БД
> переключается; предпросмотр черновика проходится без появления записей
> прогресса; playground отвечает на активном провайдере (`AI_PROVIDER`),
> исчерпание лимита — 429, прогоны видны в `/admin/audit-logs`
> (`PromptPlaygroundRun`).

Малые задачи из открытых вопросов Этапов 4–6, выполняются независимо,
можно втыкать между большими этапами:

1. **Сортировка каталога** (`concept.md`/`platform-plan.md` Этап 6):
   колонка `courses.sort_order` + ручное управление в админке (или
   «по популярности» — решить при взятии в работу).
2. **WebP-конверсия превью** + queue-job (сейчас — синхронный GD-ресайз
   с ре-энкодом; отложено решением Этапа 6).
3. **Предпросмотр курса «глазами пользователя»** в админке
   (`concept.md §9.2.3`) — без влияния на прогресс.
4. **Playground промптов** — если не сделан в Этапе 8.

---

## Граница плана

За границей этого документа (оформляются отдельными планами по мере
проработки, все — открытые вопросы `concept.md §9`):

- **Источник контента курсов**: ручной ввод (готов) vs импорт vs
  контент-команда.
- **Сертификация** по окончании курса.
- **Расширенная админка/аналитика**: воронки, вовлечённость, расходы
  на LLM в дашборде.
- **Антифрод для прод-нагрузок** практики (масштабирование Этапа 10).
- **Персонализация ИИ**: подсказки по ходу урока (сейчас — только
  фидбэк + генерация).
- **Версионирование контента курсов** (сейчас — только промпты).
- **«Супер-админ»** и разделение прав админов.
