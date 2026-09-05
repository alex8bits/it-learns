<laravel-boost-guidelines>
# Laravel Application

This repository contains a Laravel application. Complete the following setup before working on the user's request.

## Prerequisites

Verify that PHP and Composer are available:

```sh
php -v
composer -V
```

If either command is unavailable, detect the user's operating system and install the prerequisites with the appropriate command:

macOS:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/mac/8.5)"
```

Windows PowerShell:

```powershell
Set-ExecutionPolicy Bypass -Scope Process -Force; [System.Net.ServicePointManager]::SecurityProtocol = [System.Net.ServicePointManager]::SecurityProtocol -bor 3072; iex ((New-Object System.Net.WebClient).DownloadString('https://php.new/install/windows/8.5'))
```

Linux:

```sh
/bin/bash -c "$(curl -fsSL https://php.new/install/linux/8.5)"
```

After installation, ask the user to restart their terminal. If the agent needs the restarted shell to continue, ask the user to reopen their terminal and rerun their original prompt.

## Agent Setup

Install Laravel Boost from the application root before making application changes:

```sh
composer require laravel/boost --dev
php artisan boost:install
```

Boost replaces these bootstrap instructions with guidelines tailored to the application. After installation, read `AGENTS.md` again and continue with the user's original request using the generated guidelines.

---

## Project Rules

Проект: онлайн-платформа для обучения IT-навыкам. Соблюдать при разработке:

1. **SOLID — прагматично.** Применять принципы там, где они реально упрощают код и делают его гибче. Не плодить абстракции, интерфейсы и слои ради самих абстракций: если в конкретном месте прямой код понятнее и изменять его не планируется — оставлять прямой код. YAGNI важнее догм.
2. **Валидация — только в Form Request.** Контроллеры никогда не вызывают `$request->validate(...)` и не валидируют поля вручную. Любой ввод от пользователя проходит через `FormRequest` с правилами, `authorize()` и человекочитаемыми сообщениями. Исключение: внутренние/синтетические данные, которые валидируются до контроллера в сервисном слое, — там валидация допустима рядом с местом использования.
3. **Тонкие контроллеры, бизнес-логика в Action/Service.** Контроллер принимает запрос, делегирует работу (FormRequest → Action/Service → Resource) и возвращает ответ. Никаких условий, запросов к БД и бизнес-правил внутри контроллера.
4. **Не использовать fat models.** Модель — данные, скоупы, отношения. Бизнес-операции (`enrollUser`, `markLessonComplete`, `publish`) выносить в Action/Service-классы.
5. **Enum'ы вместо магических строк и чисел.** Статусы, роли, типы — через PHP `enum`, не через `'admin'`, `0/1` и константы-мозаики.
6. **Транзакции для множественных записей.** Любая операция, пишущая в 2+ таблицы — внутри `DB::transaction(...)` (в Action-классе).
7. **Авторизация только через Policy/Gate.** Не проверять роли вручную (`auth()->user()->role === 'admin'` — запрещено). В контроллере — `$this->authorize(...)`, в Blade — `@can`, в сервисах — `Gate::allows`. В Form Request — `authorize()`.
8. **API ответы — единый формат через Resource.** Никаких `Model::toArray()` / `json_encode($model)` для API. Пагинация — через `paginate()`/`cursorPaginate()` + Resource collection.
9. **Eager loading, запрет N+1.** Все коллекции, отдаваемые во view/Resource, идут с `with`/`load`. В dev-окружении включать `Model::preventLazyLoading()`.
10. **Версионирование API с первого дня.** `routes/api.php` → `v1/...`, `v2/...`. «Добавим потом» — нельзя.
11. **Rate limiting на критичных эндпоинтах** (логин, регистрация, сброс пароля, оплата, любые публичные формы) — `throttle` middleware.
12. **Mass assignment — только через `$fillable` или `$guarded`.** Никаких `Model::unguard()` в боевом коде.
13. **Миграции только forward.** Никогда не редактировать уже применённую миграцию — пишем новую. Сидеры идемпотентны.
14. **Не хардкодить SQL/логику в Blade.** Только `@foreach`, `@can`, простые условия отображения. Всё остальное — через Action/ViewModel.
15. **Тестирование: Unit — максимальное покрытие, Feature — smoke.** Unit-тесты покрывают всю ключевую бизнес-логику: Action/Service-классы, скоупы, политики, enum'ы, форматирование. Feature-тесты — только smoke-проверки критичных HTTP-границ (роут отвечает 200, ключевой элемент в ответе есть, основной happy-path работает). Глубокое покрытие веток валидации, авторизации, ошибок — на уровне Unit. В тестах использовать фабрики/сидеры, не `Model::create([...])` с захардкоженными полями.
16. **Лимиты LLM — обязательны.** Глобальный дефолт и per-user лимит — в конфиге (`config/ai.php`) и `.env` (`AI_TOKEN_LIMIT_GLOBAL_PER_DAY`, `AI_TOKEN_LIMIT_PER_USER_PER_DAY`). При исчерпании — fail loud (`HTTP 429`, без ретраев). Админ может через админку скорректировать лимит для конкретного пользователя (ручное добавление токенов). Превышение лимита — блокирующее условие в `AiFeedbackService`/`AiTaskGeneratorService`. Покрытие Unit — все ветки: лимит не исчерпан / глобальный исчерпан / пользовательский исчерпан / ручное добавление админом.
17. **Аудит-лог админ-операций — обязателен.** Любое действие админа, меняющее состояние системы (смена роли пользователя, ручные операции с оплатой, изменение курса/урока/задания, изменение промпта, изменение LLM-лимита пользователя, блокировка пользователя), пишется в `admin_audit_logs` с полями: `admin_id`, `action` (enum), `subject_type`, `subject_id`, `meta` (json), `created_at`. Запись — в `DB::transaction` с самой операцией, либо через observer (если операция в `Action`). **Нельзя** выполнять админ-операцию без записи в audit-log. Покрытие Unit — каждый Action/observer, пишущий в audit-log, должен тестироваться на корректность записи.
18. **Фронт — Vue.** Single-page на Vue (через Inertia.js или отдельный SPA-фронт). **Blade используется только как host-шаблон для Inertia** (single root template, выводит `<div id="app">`) и для статических публичных страниц без авторизации (главная без авторизации, страница ошибки, приветствие после оплаты). Никаких Blade-страниц с формами, навигацией или бизнес-логикой для авторизованных пользователей (включая админку). Если таск создаёт/меняет UI для авторизованной зоны — он создаёт/меняет **Vue-страницу через Inertia** (а не Blade-шаблон с `@if`/`@foreach`).
19. **Аутентификация — Laravel Fortify + Vue/Inertia-фронт.** Бэкенд auth (login, register, forgot-password, reset-password, update-password) — через **Laravel Fortify** (`composer require laravel/fortify`). Без email-верификации (правило + решение пользователя). **Без 2FA — вообще не подключаем** (Fortify `Features::twoFactorAuthentication()` не активируем; решение пользователя). **UI — свой**, Vue-страницы в `resources/js/Pages/Auth/`, шлют POST на Fortify-маршруты через Inertia. **Не пишем** свои auth-контроллеры — Fortify работает на `Action`-классах (`app/Actions/Fortify/CreateNewUser.php`, `PasswordReset.php`). Кастомная логика (создание пользователя с правильным `UserRole`, валидация имени, опциональный профиль) — внутри этих Action-классов, через `DB::transaction` (правило №6), с enum'ами (правило №5). Breeze не используем (даёт Blade-контроллеры, конфликтует с правилом №18). Throttle из коробки (правило №11) — конфиг в `config/fortify.php`.

---

## Структура `.mavis/` и роли команд

Артефакты Mavis и команды лежат в `.mavis/`:

- `.mavis/agents/` — определения subagent'ов (`worker`, `code-reviewer`, `codebase-researcher`).
- `.mavis/commands/` — команды (entry points): `research-codebase.md`, `design-feature.md`, `create-implementation-plan.md`, `implement-feature.md`, `write-documentation.md`.
- `.mavis/research/` — research-отчёты (только факты, `file:line`).
- `.mavis/design/` — design-документы (решения, не факты).
- `.mavis/tasks/` — папки с тасками реализации (`<NN>-<slug>.md`).

`.cursor/` в этом репо — чужой ИИ-инструмент (см. `user.md`), **не таргет** для Mavis-выхода. Не трогать, не копировать, не ссылаться как на истину.

Документы домена:

- `docs/concept.md` — что строим (иерархия курсов, роли, премиум, ИИ, изоляция среды, прогресс).
- `docs/platform-plan.md` — порядок реализации (этапы 0–6, дальше — за границей).

---

## 1. Project overview

**it-learns** — Laravel 13 (PHP 8.3) + Vue (Inertia.js) — онлайн-платформа обучения IT-навыкам. Пользователь проходит курсы (уровни → уроки → теория + практика), практика исполняется в изолированной среде, премиум-подписчикам доступен ИИ-помощник (фидбэк на ошибку, генерация доп. задач). Управление контентом, пользователями, оплатами, промптами и LLM-лимитами — через админку. Бэкенд отдаёт данные через Resource + JSON API, фронт — Vue-компоненты.

### Поверхности и версионирование

С первого дня заложено версионирование (правило №10). Маршруты лежат в:

| Поверхность | Файл                     | Назначение                                |
| ----------- | ------------------------ | ----------------------------------------- |
| `public`    | `routes/web.php`         | каталог, личный кабинет                   |
| `auth`      | `routes/auth.php`        | аутентификация                            |
| `admin`     | `routes/admin.php`       | админ-панель                              |
| `api`       | `routes/api.php`         | API: `v1/...`, `v2/...` с первого дня     |

Дополнительные доменные оси в `.mavis/design/<date>-<topic>.md` (frontmatter): `affects_premium`, `affects_ai`, `affects_practice_env`.

### Auth

- Web: `auth` guard. API: `auth:api`.
- **Email-верификация не используется** (решение пользователя, зафиксировано в `concept.md §2.1` и `platform-plan.md` Этап 1). Пользователь активен сразу после регистрации.
- Роли — `App\Enums\UserRole` (`User` | `Admin`).
- Middleware: `role:admin` для админ-маршрутов, `EnsurePremium` для премиум-маршрутов, `throttle:...` для критичных эндпоинтов (правило №11).

### Слои

- `app/Http/Controllers/...` — тонкие контроллеры, делегируют в Action/Service.
- `app/Http/Controllers/Admin/...` — админ-контроллеры.
- `app/Actions/...` — бизнес-логика, вызывается из контроллеров.
- `app/Services/...` — внешние интеграции: `PaymentGateway`, `LlmClient`, `PracticeEnvironmentManager`.
- `app/Http/Requests/...` — FormRequest'ы, **вся валидация только здесь** (правило №2).
- `app/Http/Resources/...` — API-ответы, **никаких `Model::toArray()`** (правило №8).
- `app/Enums/...` — роли, статусы, типы, уровни (правило №5).
- `app/Policies/...` — авторизация, **только через `Gate`/`Policy`** (правило №7).
- `app/Models/...` — данные, скоупы, отношения, **никакой бизнес-логики** (правило №4).
- `database/migrations/...` — forward-only (правило №13).
- `database/factories/...`, `database/seeders/...` — тестовые данные, сидеры идемпотентны.
- `routes/` — см. таблицу выше.

### Scheduled work & queue

Задачи, которые удобно держать вне HTTP — через `routes/console.php` (Laravel 11+/12+/13 стиль) или `bootstrap/app.php → withSchedule(...)`. Премиум-истечение (`subscriptions:expire`) — ежедневно. Очередь — `database` driver, идемпотентные job'ы.

### Ключевые интеграции (каркас)

`PaymentGateway` (интерфейс; `InstantPaymentGateway` — единственная реализация на старте, см. `concept.md §9.2.2` и `platform-plan.md` Этап 3), `LlmClient` (интерфейс; whitelist реализаций: `OpenAiLlmClient`, `AnthropicLlmClient`, `MiniMaxLlmClient`, `OpenAiCompatibleLlmClient` + `DummyLlmClient` для dev/тестов; единый активный провайдер через `.env`, single-tenant — см. `concept.md §9.2.5`), `PracticeEnvironmentManager` (интерфейс; `LocalSqlitePracticeEnvironment` для dev/тестов). Секреты и тумблеры — в `.env`, см. `.env.example`.

---

## 2. Commands (как запускать)

```bash
# Все тесты
php artisan test
# Один файл / фильтр
php artisan test --filter=SomeTest
# С покрытием (если настроен Xdebug/PCOV)
php artisan test --coverage

# Качество кода
vendor/bin/pint --test
vendor/bin/phpstan analyse
```

Локальная БД для тестов — `:memory:` SQLite или отдельная test-БД (настраивается в `phpunit.xml`). **Без Docker.** Никаких `XDEBUG_MODE=off` — тесты идут в обычном PHP-окружении, Xdebug включать не требуется.

### Команды Mavis (entry points)

| Что нужно                                | Команда (файл в `.mavis/commands/`)             |
| ---------------------------------------- | ----------------------------------------------- |
| Исследовать кодовую базу                 | `research-codebase.md`                          |
| Оформить дизайн фичи                     | `design-feature.md`                             |
| Разбить фичу на таски                    | `create-implementation-plan.md`                 |
| Запустить реализацию (оркестрация)       | `implement-feature.md` (см. §5)                 |
| Синхронизировать документацию маршрутов  | `write-documentation.md`                        |

---

## 3. Hard rules

### 3.1 Запуск тестов — локально, всегда через `php artisan test`

**Никаких Docker-обёрток, никаких ручных вызовов PHPUnit напрямую.** Все тесты — через `php artisan test` (Pint, PHPStan, миграции, сидеры — аналогично).

```bash
# ✅ GOOD
php artisan test
php artisan test --filter=SomeTest
vendor/bin/pint --test
vendor/bin/phpstan analyse

# ❌ BAD
./vendor/bin/phpunit                # мимо Laravel-окружения, без pest.php / bootstrap
phpunit                             # аналогично
```

### 3.2 Тестовая политика (Unit vs Feature) — **строже**, чем «risk-based»

Это **наш стандарт**, отличающийся от стандартной пирамиды. Соблюдать **всегда**:

- **Unit (`tests/Unit/...`) — максимальное покрытие.** Покрывать всю ключевую бизнес-логику: каждый `Action`/`Service`, публичные методы `Policy`, `scope`-методы моделей, `Enum`-методы (метки, сравнения, переходы), форматирование, валидаторы. Покрывать все ветки, граничные случаи, исключения, негативные сценарии. **Изолированы** (mock'и БД/HTTP/queue/файловой системы, где это уместно). Фабрики обязательны — никаких `Model::create([...])` с захардкоженными полями.
- **Feature (`tests/Feature/...`) — только smoke.** Для каждого endpoint'а: **1 happy-path** + проверка, что **ключевой элемент ответа присутствует** (например, что ответ содержит ожидаемое поле). Никаких веток валидации, никаких перечислений 4xx-классов, никаких проверок авторизации на уровне HTTP — всё это уже покрыто в Unit. Если в Feature-тесте появляется if-ветвление или проверка `assertStatus(422) на каждое правило валидации` — это **архитектурная ошибка** (правило №15 нарушено), переносить в Unit.

**Где Unit-покрытие обязательно (отказ = дорого):**

- деньги, тарифы, скидки, даты и длительности;
- маппинг ответов платёжного шлюза и нормализация статусов;
- переходы состояний (`SubscriptionStatus`, `PracticeEnvironmentStatus` и т.п.);
- проверки прав и ролей;
- сборка промпта через `PromptResolver` (Global + Course);
- изоляция среды: provision / execute / compare / destroy (особенно destroy в `finally`);
- весь lifecycle подписки и идемпотентность webhook'ов.

**Чеклист перед коммитом нового теста:**

- [ ] Изменённое поведение покрыто, включая нетривиальные ветки.
- [ ] Деньги/даты/платежи/state-машины/права — **исчерпывающе** в Unit.
- [ ] Повторяющиеся кейсы — через `dataProvider` (`PHPUnit`) / `with(dataset)` (Pest), не copy-paste.
- [ ] Feature-тесты добавлены только там, где **нельзя** проверить в Unit (HTTP-граница).
- [ ] Unit-тесты **не зависят** от БД/HTTP/queue/файловой системы (если это уместно).

### 3.3 Соблюдение 18 правил проекта

Все 18 правил выше — **non-negotiable**. Любое отклонение в таске или в PR — кандидат в `HIGH` (см. §4.2). Если задача требует нарушить правило — явно зафиксировать как `non-goal` в design-документе с обоснованием, не в коде.

### 3.4 PHPStan — уровень 7

PHPStan (через Larastan) запускается с **уровнем 7** (из 9). Это компромисс между строгостью и практикой: ловит почти все ошибки типов, но не требует переписывать легаси-код под максимальную строгость. Повышение уровня до 8–9 — отдельное решение с явным baseline.

---

## 4. Agents

Три subagent'а определены в `.mavis/agents/`. Используются оркестрацией в §5 и командами в `.mavis/commands/`.

### 4.1 `worker` — implementation specialist

Полный контракт — в `.mavis/agents/worker.md`. Краткая суть:

- Пишет код строго в рамках **одного таска** из `.mavis/tasks/YYYY-MM-DD-<topic>/<NN>-<slug>.md`.
- Реализует по разделам «Что нужно реализовать» и «Файлы для изменения» таска.
- НЕ трогает «Что НЕ нужно менять».
- Пишет Unit-тесты (максимальное покрытие) + Feature-smoke (если HTTP-граница).
- Прогоняет `php artisan test --filter=...`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`.
- Definition of Done чеклист — в `.mavis/agents/worker.md`.

**Контекст стека:** Laravel 13 / PHP 8.3, PHPUnit, версионирование API (`v1/`/`v2/`), middleware `role:admin` / `EnsurePremium` / `throttle`, сервисы `PaymentGateway` / `LlmClient` (whitelist + выбор через `.env`, single-tenant) / `PracticeEnvironmentManager`.

**Сквозные правила реализации (из 15 правил проекта):** тонкие контроллеры, FormRequest для валидации, enum'ы для ролей/статусов, Policy/Gate для авторизации, `DB::transaction` для мутаций в 2+ таблицы, Resource для API-ответов, eager loading по умолчанию, `$fillable` вместо `Model::unguard`, миграции forward-only, премиум через `EnsurePremium`, ИИ через `LlmClient` + `PromptResolver`, практика через `PracticeEnvironmentManager` с `try/finally`.

### 4.2 `code-reviewer` — read-only quality gate

Полный контракт — в `.mavis/agents/code-reviewer.md`. Краткая суть:

- Read-only. **Никогда** не правит файлы.
- Определяет изменённые файлы (`git diff --name-only HEAD` или из контекста таска).
- Проверяет по чеклисту: Critical (BLOCKER), Architecture (HIGH), Code Quality (MEDIUM), Tests (HIGH/MEDIUM — по правилу №15), Laravel-specific (LOW-MED), it-learns Domain (LOW-MED).
- Возвращает `PASS` или `NEEDS FIX`.

**Главный арбитр — 15 правил проекта выше.** Любое отклонение — кандидат в `HIGH`.

**Сверяется с:**

- `AGENTS.md` (этот файл).
- `docs/concept.md` (если затрагивается домен — роли, премиум, ИИ, изоляция среды, прогресс).
- `docs/platform-plan.md` (текущий этап и его ограничения).

**В чеклисте особо отмечено для it-learns:**

- практика: `destroy()` всегда в `finally` (BLOCKER если не так);
- ИИ: вызовы только через `LlmClient`, **не напрямую** к провайдеру (BLOCKER);
- ИИ: все вызовы логируются (`Log::info('ai.llm_call', ...)`);
- ИИ: `PromptResolver` учитывает Global + Course-специфичный;
- премиум: маршруты под `EnsurePremium`, **не** под проверкой `is_premium` в коде (HIGH);
- прогресс: только через Action-классы, **не** прямой записью в `user_*_progress` (MEDIUM);
- подписки: `SubscriptionService` для всех мутаций (MEDIUM).

### 4.3 `codebase-researcher` — read-only mapping

Полный контракт — в `.mavis/agents/codebase-researcher.md`. Краткая суть:

- Только факты. Никаких предложений, никакой критики.
- Каждое утверждение с `file_path:line_number`.
- Читает файлы **полностью**, без `limit`/`offset`.
- При исследовании route/controller/middleware — указывает `public|admin|api` × `v1|v2`.
- При затрагивании премиум/ИИ/изоляции среды/прогресса — помечает явно.
- Запускается параллельно (2–4 таска одновременно) из `research-codebase` команды.

---

## 5. Процесс реализации фичи (orchestration)

Сквозной flow от идеи до сданного кода. Entry point: `.mavis/commands/implement-feature.md` (собирает 4 входа и делегирует сюда).

### 5.1 Phases

1. **Research** — `.mavis/commands/research-codebase.md` пишет `.mavis/research/<date>-<topic>.md`. Только факты, `file:line` везде, surface/version помечены.
2. **Design** — `.mavis/commands/design-feature.md` пишет `.mavis/design/<date>-<topic>.md`. Решения, не факты. API-поверхность и версия зафиксированы во frontmatter (`affects_premium`, `affects_ai`, `affects_practice_env` — тоже).
3. **Plan** — `.mavis/commands/create-implementation-plan.md` пишет `.mavis/tasks/<date>-<topic>/<NN>-<slug>.md` (3–7 тасков, упорядоченных, с явными «Зависимости» и «Что НЕ нужно менять»).
4. **Implement** — запуск тасков волнами (этот раздел).

### 5.2 Построение плана выполнения (волны)

До запуска любого `worker`:

1. Прочитать design-док и **каждый** task-файл **полностью**.
2. Кратко (1–3 предложения) сформулировать цель.
3. Выписать все таски в числовом порядке.
4. Извлечь сигналы параллелизации из design и task-листа:
   - явные «параллельно» / «независимо» / «parallel» / «wave»;
   - явные «depends on» / «after» / «требует» / «зависит от»;
   - непересекающиеся file/module scope.
5. Построить волны:
   - Внутри одной волны — таски идут **параллельно**.
   - Волны идут **строго последовательно**.
   - Таск уходит в более позднюю волну, если зависит от таска из ранней.
   - Два таска могут быть в одной волне **только если ОБА**: design/tasks помечают их как parallel-safe **И** их file-scope не пересекается.
6. Если информации для параллелизации не хватает — **по умолчанию последовательно** (один таск на волну).
7. Кратко показать пользователю план до старта:

```text
План выполнения:
- Волна 1 (параллельно): [task 01], [task 02]
- Волна 2 (последовательно): [task 03]
- Волна 3 (параллельно): [task 04], [task 05]

Начинаю с волны 1.
```

8. Один короткий clarification — только если материалы противоречат друг другу, file-scope параллельной группы пересекается, или таск неисполним.

### 5.3 Цикл на таск

Для каждого таска (соло или одного из параллельной волны):

1. Запустить `worker` (см. `.mavis/agents/worker.md`). Worker-prompt **обязательно** содержит:
   - цель реализации;
   - summary research или путь к research-файлу;
   - design-решение или путь к design-файлу;
   - текст таска или путь к task-файлу;
   - API-поверхность и версию, которых таск касается (например, `public × v1`, `admin`, `api × v1`);
   - явные scope-границы — реализовать строго этот таск, без посторонних рефакторингов;
   - если таск в параллельной волне: явное «этот таск идёт параллельно с другими; не трогать файлы вне объявленного scope; не трогать файлы, принадлежащие соседним таскам»;
   - ожидания по валидации из секции «Проверка» таска.
2. Дождаться завершения worker.
3. Запустить `code-reviewer` (см. `.mavis/agents/code-reviewer.md`) на изменения worker'а. Reviewer-prompt **обязательно** содержит:
   - цель таска;
   - список изменённых файлов (или `git diff --name-only HEAD` от reviewer);
   - требование вернуть `PASS` или `NEEDS FIX`;
   - требование фокусироваться на корректности, архитектуре, безопасности, тестах и соответствии поверхности/версии;
   - если таск в параллельной волне: явное проверить, что worker не вышел за объявленный scope.
4. Если вердикт `PASS` — таск готов.
5. Если `NEEDS FIX` или есть блокирующие находки (BLOCKER / HIGH / correctness-affecting MEDIUM):
   - Перезапустить `worker` с полным review-репортом. Попросить исправить **только** указанные проблемы, **не** затирая не относящиеся к таску изменения пользователя.
   - Перезапустить `code-reviewer`.
   - Повторять до `PASS` или до второго появления той же нерешённой проблемы.

**Если одна и та же блокирующая проблема появляется дважды** — **остановить оркестрацию** и сообщить пользователю. Не продолжать.

### 5.4 Параллельные волны

Когда волна содержит несколько тасков:

1. Запустить по одному `worker` на таск **параллельными tool-calls в одном assistant turn**, каждый с self-contained prompt (см. §5.3 шаг 1). **Не делиться контекстом** между соседними worker'ами — каждый prompt самодостаточен.
2. Дождаться завершения **всех** sibling worker'ов в волне, прежде чем запускать reviewer'ов.
3. На каждый завершённый worker — отдельный `code-reviewer` (тоже можно параллельно).
4. Собрать все вердикты.
5. Для каждого `NEEDS FIX` запустить fix-loop из §5.3 шаг 5. Fix-loop'ы **разных** тасков в одной волне тоже можно параллелить; цепочка worker → reviewer → worker для одного таска — строго последовательна.
6. Волна завершена, **только** когда все таски имеют финальный `PASS`.
7. Если worker в параллельной волне сообщает о конфликте (пришлось тронуть файлы вне объявленного scope, или обнаружена незакрытая зависимость от соседнего таска):
   - Остановить волну.
   - Перепланировать: разбить волну на две последовательные, конфликтующий таск уходит в более позднюю.
   - Кратко сообщить пользователю о перепланировании, продолжить.

### 5.5 Валидация после всех волн

1. Запустить проверки, релевантные затронутой области.
2. Тесты — локально, **без Docker**, через `php artisan test` (см. §3.1):

   ```bash
   php artisan test
   # или самый узкий фильтр
   php artisan test --filter=SomeTest
   ```

3. Pint и PHPStan (если подключены в Этапе 0):

   ```bash
   vendor/bin/pint --test
   vendor/bin/phpstan analyse
   ```

4. Если какую-то проверку запустить нельзя — явно сказать и подсказать пользователю, что запустить руками.

### 5.6 Финальный ответ (Russian)

После того, как все таски реализованы и проревьюены:

```markdown
Готово.

Реализовано:
- [high-level result 1]
- [high-level result 2]

Параллельные волны:
- Волна 1 (параллельно): [task A], [task B] — PASS
- Волна 2 (последовательно): [task C] — PASS
- Волна 3 (параллельно): [task D], [task E] — PASS

Проверка:
- [commands run and result, e.g. `php artisan test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`]

Осталось/риски:
- [only if something could not be completed or verified]
```

Кратко. Без поканального changelog'а, если пользователь не просил. Опустить секцию «Параллельные волны», если весь прогон был последовательным.

### 5.7 Hard rules (orchestration)

1. Никогда не пропускать `code-reviewer` для таска, включая параллельные волны.
2. Никогда не батчить несколько тасков в один worker-prompt. Один worker = один таск.
3. Worker/reviewer-prompt'ы **self-contained** — `worker` не получает скрытого контекста диалога. Обязательно для параллельных волн.
4. Сохранять пользовательские изменения в working tree. `worker` не должен откатывать/перетирать файлы, не относящиеся к таску.
5. Параллелизм — оптимизация, не требование. Корректность важнее скорости: если независимость тасков не доказана — последовательно.
6. Версионирование API: явно объявлять поверхность (`public|admin|api`) и версию (`v1|v2`) для каждого route/controller в таске. При сомнениях — спросить, относится ли изменение к `v1` или должно быть реплицировано на `v2`.
7. Финальные тесты — локально через `php artisan test` (§3.1). **Никаких** Docker-обёрток.
8. **Премиум-доступ:** премиум-маршруты — только под `EnsurePremium`. Если таск создаёт/меняет премиум-эндпоинт — middleware обязателен.
9. **ИИ-вызовы:** только через `LlmClient`. Прямой HTTP к провайдеру в коде приложения — BLOCKER.
10. **Практика:** `PracticeEnvironmentManager::destroy()` всегда в `finally`. Без этого — BLOCKER.

---

## 6. Quick reference

| Нужно…                                  | Использовать                                                   |
| --------------------------------------- | -------------------------------------------------------------- |
| Исследовать кодовую базу                | `codebase-researcher` (§4.3) через `research-codebase` команду |
| Оформить дизайн фичи                    | `design-feature` команду                                       |
| Разбить фичу на таски                   | `create-implementation-plan` команду                           |
| Реализовать спланированную фичу         | `implement-feature` команду (вход в §5)                        |
| Синхронизировать документацию маршрутов | `write-documentation` команду                                  |
| Запустить тесты                         | `php artisan test` (см. §3.1)                                  |
| Прочитать research-отчёт                | `.mavis/research/<date>-<topic>.md`                            |
| Прочитать design-решение                | `.mavis/design/<date>-<topic>.md`                              |
| Посмотреть, как фича сдавалась раньше   | `.mavis/tasks/<date>-<topic>/<NN>-<slug>.md`                   |
| Свериться с доменом                     | `docs/concept.md`                                              |
| Свериться с планом реализации           | `docs/platform-plan.md`                                        |
</laravel-boost-guidelines>
