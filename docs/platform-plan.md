# План реализации платформы обучения IT-навыкам

> Опорный документ. Дополняет `docs/concept.md` (что строим) правилами
> реализации (в каком порядке и как именно). Согласован с `AGENTS.md`
> (15 правил проекта). Сам контент курсов (иерархия Курс→Уровень→Урок,
> теория, практика) — за границей этого плана; здесь только фундамент
> и сквозные механизмы.

## Сквозные принципы реализации

- **Тонкие контроллеры, бизнес-логика в Action/Service.** Контроллер — только маршрутизация запроса к FormRequest → Action → Resource.
- **Валидация только в Form Request** (правило №2 `AGENTS.md`).
- **Enum'ы для ролей, статусов, типов.** Никаких магических строк/чисел.
- **Авторизация только через Policy/Gate.** Никаких проверок ролей вручную.
- **Транзакции для всех мутаций в 2+ таблицы** — на уровне Action.
- **API ответы — через Resource**, единый формат.
- **Eager loading по умолчанию**, в dev включаем `Model::preventLazyLoading()`.
- **Unit — максимальное покрытие, Feature — smoke** (правило №15). Сначала пишем Unit, Feature — только на HTTP-границы.
- **Фабрики и сидеры** для всех моделей. Никаких хардкод-данных в тестах.
- **Миграции только forward**, без правок уже применённых.
- **Каждый этап заканчивается зелёными тестами + smoke-проверкой в браузере.** Этап не считается сданным, пока не проходит `php artisan test`.

## Этапы

Каждый этап имеет: **Цель** / **Что делаем** / **Что получаем на выходе** / **Готовность** / **Открытые вопросы этапа**.

---

### Этап 0. Базовая инфраструктура и настройка проекта

**Цель:** подготовить проект к разработке по правилам `AGENTS.md`. Чтобы дальше каждый этап стартовал из согласованной точки и не приходилось переделывать фундамент.

**Что делаем:**

1. Установить и настроить **Laravel Boost** (`composer require laravel/boost --dev`, `php artisan boost:install`). Он перепишет блок-инструкцию в `AGENTS.md` под приложение.
2. Настроить **качество кода**:
   - **Pint** — стиль (уже в dev-зависимостях). Сконфигурировать `pint.json` под командные правила (если есть), прогнать `vendor/bin/pint --test`.
   - **PHPStan / Larastan** на **уровне 7** (статический анализ; зафиксировано пользователем). Подключить через `composer require --dev larastan/larastan`, настроить `phpstan.neon` с `level: 7`, прогнать на текущей кодовой базе, зафиксить baseline. Повышение до 8–9 — отдельное решение с явным baseline.
3. Тест-раннер: **PHPUnit** (зафиксировано). В `composer.json` уже есть `phpunit/phpunit: ^12.5.12` — оставляем. Конфиг `phpunit.xml` настраиваем под it-learns (`:memory:` SQLite для тестов, разделение `test` / `test-coverage`). Pest не подключаем.
4. Включить **запрет lazy loading в dev-окружении**: в `AppServiceProvider::boot()` добавить `Model::preventLazyLoading(! app()->isProduction())`.
5. **Конфигурация `phpunit.xml`**: разделить `test` (полный набор) и `test-coverage`, проверить, что БД для тестов — `:memory:` SQLite (или отдельная test-БД).
6. **CI-смоук** (если используется CI): workflow на `composer install` + `php artisan test` + `vendor/bin/pint --test` + `vendor/bin/phpstan analyse`.
7. Создать папку `docs/` (уже создана) и зафиксировать в `README.md` ссылки на `AGENTS.md`, `docs/concept.md`, `docs/platform-plan.md`.
8. `.env.example` — актуализировать под нужды платформы (mail, queue, payment, AI-провайдер — заглушки пока).

**Что получаем на выходе:**
- Проект собирается, тесты проходят, статанализ запускается, стиль проверяется Pint'ом.
- Lazy loading отлавливается в dev.
- `AGENTS.md` дополнен Boost-инструкциями; `docs/concept.md` и `docs/platform-plan.md` доступны команде.
- `.env.example` готов к дальнейшему расширению.

**Готовность:**
- `composer install` без warning'ов.
- `php artisan test` — зелёный (даже если тестов пока мало).
- `vendor/bin/pint --test` — зелёный.
- `vendor/bin/phpstan analyse` — зелёный (или с принятым baseline).
- Все три команды выполняются одной командой `composer test` или аналогом.

**Открытые вопросы этапа:**
- ~~Выбор Pest vs PHPUnit~~ — закрыто: PHPUnit.
- ~~Уровень строгости PHPStan~~ — закрыто: уровень 7.
- CI-платформа (GitHub Actions / GitLab CI / локально).

---

### Этап 1. Пользователь: регистрация, вход, сброс пароля

**Цель:** полностью рабочий пользовательский flow аутентификации на email + пароль с восстановлением пароля. Без ролей (User один), без админки, без премиума — это следующие этапы.

**Архитектурное решение (зафиксировано, см. `AGENTS.md` правило №19):** **Laravel Fortify** для бэкенда + **Vue/Inertia** для UI. Без email-верификации. Breeze не используем.

**Что делаем:**

1. **Подключение Fortify:**
   - `composer require laravel/fortify`.
   - `php artisan fortify:install` → создаёт `config/fortify.php`, `app/Actions/Fortify/CreateNewUser.php`, `app/Actions/Fortify/PasswordReset.php`, `app/Actions/Fortify/ResetUserPassword.php`, `app/Actions/Fortify/UpdateUserPassword.php`, добавляет `FortifyServiceProvider`.
   - В `config/fortify.php`:
     - `'features' => [Features::registration(), Features::resetPasswords(), Features::updatePasswords()]` — **БЕЗ** `Features::emailVerification()` (решение пользователя).
     - `'home' => '/dashboard'` — куда редиректить после входа.
     - `'username' => 'email'` — логин по email.
     - Throttle: `RateLimiter::for('login', ...)` и `RateLimiter::for('two-factor', ...)` в `App\Providers\RouteServiceProvider` (или `bootstrap/app.php` для Laravel 11+/13) — `10,1` на login, `5,1` на register/forgot-password.
2. **Миграции и модель:**
   - `users`: `id`, `name`, `email` (unique), `email_verified_at` nullable, `password`, `remember_token`, timestamps. Поле `email_verified_at` остаётся для будущего расширения, но **не используется** (см. `concept.md §2.1`).
   - `password_reset_tokens` (дефолт Laravel), `failed_jobs` и пр. — стандартно.
3. **Фабрики:** `UserFactory` (с дефолтами, без `unverified()` state — он нам не нужен).
4. **Кастомизация `CreateNewUser` (Action):**
   - Внутри `DB::transaction` (правило №6) создаём `User` с `name`, `email`, `password` (хеш через `Hash::make`), `role = UserRole::User` (правило №5 — через enum, не строку).
   - Создаём связанные записи: `Subscription` со статусом `Free`/`Pending` (через сервис, подготовленный в Этапе 3; на этом этапе — простая заглушка, если нужно).
   - Возвращаем созданного `User`.
5. **Routes:** `routes/web.php` подключает маршруты Fortify (`Fortify::routes()` уже делает это в `FortifyServiceProvider`). `routes/auth.php` **не создаём** — Fortify даёт `/login`, `/register`, `/forgot-password`, `/reset-password`, `/logout`, `/user/password` из коробки.
6. **UI — Vue/Inertia:**
   - Vue-страницы в `resources/js/Pages/Auth/`: `Login.vue`, `Register.vue`, `ForgotPassword.vue`, `ResetPassword.vue`, `ConfirmPassword.vue`.
   - Inertia-форма: `<Form>`-компонент с `Inertia.post('/login', {...})` или `route('login')`. CSRF через `@csrf` или Inertia автоматически.
   - Blade — только host-шаблон `app.blade.php` с `<div id="app">` для Inertia (правило №18).
   - Поля формы с ошибками валидации через `usePage().props.errors` (стандартный Inertia-паттерн).
7. **Email-канал:**
   - `MAIL_MAILER=log` в `.env` для dev (видно в `storage/logs/laravel.log`).
   - В `.env.example` — комментарий: «`MAIL_MAILER=log` для dev. На проде администратор указывает SMTP-параметры самостоятельно (`.env` / деплой-конфиг) — выбор провайдера и реквизиты не наша забота».
   - Шаблоны писем (Fortify): `resources/views/auth/emails/password-reset.blade.php` — простые Blade-шаблоны, **допустимы** (это email-вёрстка, не UI приложения).
8. **Тесты (по правилу №15, Unit-max / Feature-smoke):**
   - **Unit:** `CreateNewUser` (валидные данные / дубликат email / хеширование пароля / создан с правильным `UserRole` / транзакция откатывается при ошибке), `ResetUserPassword` (валидный токен / просроченный / использованный повторно), `UpdateUserPassword` (старый пароль неверный / новый совпадает со старым — запрет), enum `UserRole`. **Не тестируем** внутренности Fortify — это апстрим-пакет.
   - **Feature (smoke):** `POST /register` через Inertia-mock → 302 + пользователь создан; `POST /login` с верными кредами → 302 на `/dashboard`; `POST /login` с неверными → 302 обратно с `errors.email`; `POST /forgot-password` → 200 + email в `Mail::fake()`; `POST /reset-password` с токеном → 302 + пароль изменён; `POST /logout` → 302 на главную + пользователь разлогинен.
9. **Seeders:** `DatabaseSeeder` создаёт 1 тестового пользователя (`user@example.com` / `password`) для локальной разработки.

**Что получаем на выходе:**
- Регистрация, вход, выход, сброс пароля, обновление пароля работают end-to-end.
- Бэкенд — Fortify (без своих контроллеров), фронт — Vue/Inertia.
- Все формы — через Inertia + Vue, без Blade-форм.
- Покрытие Unit-тестами наших кастомных Action'ов + Feature-smoke HTTP-границ.
- Email-канал настроен (для dev — `log` driver).
- Throttle из коробки (правило №11), настраивается через `RateLimiter`.

**Готовность:**
- Регистрация → вход под новым пользователем.
- Забыл пароль → письмо со ссылкой (видно в `storage/logs/laravel.log`) → смена → вход под новым.
- `php artisan test` зелёный, `pint` зелёный, `phpstan` зелёный.
- Smoke в браузере пройден вручную под пользователем.

**Открытые вопросы этапа:**
- ~~Аутентификация: Laravel Breeze (готовый UI) / Fortify (только бэкенд) / ручная реализация~~ — закрыто: **Fortify**.
- ~~Фронт: Blade + Breeze / Inertia + Vue/React / Livewire~~ — закрыто: **Vue/Inertia** (правило №18).
- ~~Email-верификация: включаем с первого дня или отложенно~~ — закрыто: НЕ используется.
- Требования к паролю: минимальная длина 8, минимум 1 буква + 1 цифра, без словаря запрещённых (минимум). Зафиксировать в `Fortify`'s password validation rules или в `CreateNewUser`. **Предлагаю:** стандартные Laravel rules (`Password::min(8)->letters()->numbers()`), без словаря.
- ~~Лимит попыток входа: throttling на уровне middleware или логика в `LoginAction`~~ — закрыто: **throttle middleware** из Fortify (`10,1` login, `5,1` register/forgot-password).
- ~~**Email-провайдер для прода** (SMTP-реквизиты) — отдельная задача, решаем перед деплоем~~ — **закрыто**: на dev и в Этапе 1 — `MAIL_MAILER=log`. На проде SMTP-параметры указывает администратор самостоятельно через `.env` / деплой-конфиг — мы это **не выбираем и не реализуем**.

---

### Этап 2. Роли и админ-панель (каркас)

**Цель:** ввести ролевую модель (`User` / `Admin`), закрыть пользовательские маршруты, дать админу базовый вход в админку с разделами, которые перечислены в `concept.md §8`. CRUD курсов и контента — отдельный этап; здесь только **каркас админки** (навигация, layout, доступ, пустые разделы с заглушками), чтобы дальше наращивать функциональность.

**Что делаем:**

1. **Enum'ы:** `App\Enums\UserRole` с кейсами `User`, `Admin`. Cast на `users.role`. `App\Enums\AdminAuditAction` (стартовый набор: `UserRoleChanged`, `UserBlocked`, `UserUnblocked`; расширяется по мере появления операций).
2. **Миграция:** добавить `users.role` (default `User`), `users.is_blocked` (default `false`). Создать таблицу `admin_audit_logs` (`id`, `admin_id` FK, `action` enum, `subject_type` string, `subject_id` bigint nullable, `meta` json, `ip` string nullable, `user_agent` string nullable, `created_at`) с индексами по `admin_id`, `action`, `subject_type+subject_id`, `created_at`.
3. **Сидер:** `AdminSeeder` — создаёт админа из `ADMIN_EMAIL`/`ADMIN_PASSWORD` (или генерирует пароль и пишет в лог при первом запуске).
4. **Только Policy** (по правилу №7 `AGENTS.md`):
   - На каждую доменную сущность (User, Course, Level, Lesson, TheoryTask, PracticeTask, Payment, AiPrompt, AdminAuditLog, UserLlmLimit) — своя `XxxPolicy` с методами `viewAny`, `view`, `create`, `update`, `delete`.
   - **Не используем** `Gate::define('admin', ...)` — это конфликтует с правилом №7.
   - Middleware `EnsureUserHasRole::admin` (или `role:admin`) — **только как групповой gatekeeper** на уровне маршрутов (`/admin/*`). Бизнес-операции внутри — через Policy.
5. **Routes:**
   - Группа `/admin` с middleware `['auth', 'role:admin']`.
   - Подгруппы: `admin.users.*`, `admin.payments.*`, `admin.courses.*`, `admin.prompts.*`, `admin.audit-logs.*` — пока пустые контроллеры с `index()`-заглушками.
6. **Контроллеры `App\Http\Controllers\Admin\...`:**
   - `DashboardController@index` — счётчики: пользователи всего, премиум сейчас, оплаты за месяц, курсы опубликованные. Получает данные через `AdminDashboardService` (Action), не из контроллера.
   - `UserController` (index/show/update) — список с фильтрами, карточка, операции смены роли / блокировки / разблокировки. **Каждая операция пишет запись в `admin_audit_logs`** (через `AdminAuditLogger` сервис).
   - `PaymentController` (index/show) — список с фильтрами (заглушка, реальные данные — в Этапе 3).
   - `CourseController` (index/show) — заглушка, реальный CRUD — в Этапе 5+ «Курсы».
   - `PromptController` (index/edit) — заглушка, реальный CRUD — в Этапе 4 «ИИ».
   - `AuditLogController@index` — только чтение, фильтры по `admin_id`, `action`, периоду. Удаление/правка запрещены на уровне Policy.
7. **FormRequest'ы:** `AdminUpdateUserRequest` (смена роли, блокировка), фильтры — через отдельные Request-классы или query-builder.
8. **Layout:** `layouts/admin.blade.php` — **только host-шаблон для Inertia** (single root template с `<div id="app">`), как требует правило №18 `AGENTS.md`. Никаких Blade-страниц с формами, навигацией или бизнес-логикой внутри авторизованной админки. Контент админки (навигация, страницы) — Vue-компоненты.
9. **Сервис аудита:** `App\Services\Admin\AdminAuditLogger` с методом `log(AdminAuditAction $action, Model $subject, array $meta = []): void` — пишет запись от имени текущего админа, выдёргивает `ip` и `user_agent` из `request()`. Используется во всех админ-Action'ах в одной `DB::transaction` с самой операцией.
10. **Политики:** `UserPolicy@view/admin` (админ может смотреть/редактировать), `CoursePolicy`/`PromptPolicy` (заглушки, готовятся к наполнению), `AdminAuditLogPolicy@view` (только Admin).
11. **Тесты:**
    - **Unit:** `EnsureUserHasRole` middleware, `UserPolicy`/`AdminPolicy`, `AdminDashboardService` (формирование счётчиков), `AdminAuditLogger` (формирует корректную запись, мерджит meta, обрабатывает null-subject), валидация в `AdminUpdateUserRequest`, enum `UserRole` и `AdminAuditAction` (метки, цвета, сравнения).
    - **Feature (smoke):** не-админ → `/admin` → 403; админ → `/admin` → 200; `/admin/users` → 200; попытка смены роли самого себя админом → 422/403; смена роли → запись в `admin_audit_logs` создана.
12. **Seeders/factories:** `UserFactory` обновлена — состояния `admin()`, `blocked()`.

**Что получаем на выходе:**
- Роли в коде через enum, проверка через Gate/Policy, никакого хардкода.
- Каркас админки: layout + навигация + 6 разделов (часть — заглушки).
- Middleware `role:admin` reusable для будущих маршрутов.
- **Audit-лог инфраструктура:** таблица `admin_audit_logs`, enum `AdminAuditAction`, `AdminAuditLogger` сервис, `AuditLogController@index` (только чтение). Готова к использованию во всех последующих этапах.
- Seeded admin-аккаунт для локальной разработки.
- Покрытие Unit-тестами ролей/политик/middleware/audit-logger, Feature-smoke HTTP-границ.

**Готовность:**
- Пользователь `User` не видит `/admin/*` (403), `Admin` видит.
- Все 5 разделов админки открываются с навигацией.
- `php artisan test` зелёный, `pint` зелёный, `phpstan` зелёный.
- Smoke в браузере пройден под обеими ролями.

**Открытые вопросы этапа:**
- ~~`Gate` vs `Policy` для админ-доступа: один `Gate::admin` или много `Policy` на каждую сущность с `viewAny/admin`~~ — закрыто: **только Policy** (по правилу №7 `AGENTS.md`). На каждую доменную сущность (User, Course, Level, Lesson, Payment, AiPrompt, AdminAuditLog, UserLlmLimit) — своя `XxxPolicy` с методами `viewAny`, `view`, `create`, `update`, `delete`. **Не используем** глобальный `Gate::admin` — это конфликтует с правилом №7 (проверка ролей вручную в коде). Middleware `role:admin` допустим как **групповой gatekeeper** на уровне маршрутов (`/admin/*`), но бизнес-операции — через Policy.
- ~~Аудит-лог админ-операций (см. `concept.md §9.3`)~~ — закрыто: включаем сразу, в этом же этапе.
- ~~Двухфакторка для админов — со старта или позже~~ — закрыто: **не подключаем** (решение пользователя). `Fortify::twoFactorAuthentication()` feature не активируется. Поле `two_factor_*` в `users` не появляется.

---

### Этап 3. Премиум-подписка и оплата

**Цель:** пользователь может оформить месячную премиум-подписку. Состояние подписки хранится в системе и влияет на доступ к ИИ (флаг `is_premium`). История оплат видна в админке. **Без интеграции с реальным платёжным провайдером** — нужен абстрактный слой, чтобы потом подключить выбранного провайдера.

**Что делаем:**

1. **Enum'ы:** `SubscriptionTier { Free, Premium }`, `SubscriptionStatus { Active, Cancelled, Expired, Pending }`, `PaymentStatus { Pending, Succeeded, Failed, Refunded }`.
2. **Миграции:**
   - `subscriptions`: `user_id`, `tier`, `status`, `starts_at`, `ends_at`, `cancelled_at`, `external_id` (от провайдера), `provider` (yookassa/stripe/etc), timestamps.
   - `payments`: `user_id`, `subscription_id` nullable, `amount`, `currency`, `status`, `external_id`, `provider`, `payload` (json — для аудита webhook'ов), timestamps.
3. **Фабрики/seeders:** `SubscriptionFactory` (состояния `active`, `expired`, `cancelled`), `PaymentFactory`.
4. **Сервисный слой:**
   - `SubscriptionService` (или `SubscriptionAction`): `subscribe(User, tier)`, `cancel(User)`, `isActive(User)`, `expiresAt(User)`. Все мутации — в `DB::transaction`.
   - Интерфейс `PaymentGateway` (или `PaymentProvider`) с методами `createCheckoutSession(User, tier)`, `handleWebhook(Request)`, `refund(Payment)`. **На старте — единственная реализация `InstantPaymentGateway`** (см. ниже). Реальный провайдер добавляется позже без переписывания остального кода.
   - **`InstantPaymentGateway`** — реализация `PaymentGateway` для MVP/демо: «нажал Купить премиум → сразу получил подписку на месяц». `createCheckoutSession()` возвращает URL `/subscription/checkout/return?session=...`, Action по этому URL сам помечает подписку `Active` с `ends_at = +1 month`. `handleWebhook()` отсутствует (нечего принимать). `refund()` — no-op с возвратом `true` (для совместимости с интерфейсом). UI-флоу: короткая задержка 1–2 сек на `/subscription/checkout/return` со спиннером «Обрабатываем платёж...» + редирект. Переключение на реального провайдера — замена биндинга в DI + новый класс-реализация.
5. **Контроллеры/маршруты пользователя:**
   - `/pricing` — страница тарифов.
   - `/subscription` — текущий статус, кнопка «Оформить/Отменить».
   - `/subscription/checkout` → редирект на gateway.
   - `/subscription/webhook` — приём callback'ов от провайдера, идемпотентная обработка.
6. **Контроллеры админки (Этап 2, заглушки → реальные):**
   - `Admin\PaymentController@index` — список с фильтрами.
   - `Admin\PaymentController@show` — детальный просмотр, payload от провайдера.
   - `Admin\UserController@show` — добавить блок «Подписки/оплаты» в карточку пользователя.
7. **Gate/Policy:** `SubscriptionPolicy@accessPremium` (проверка `isActive`). Middleware `EnsurePremium` для маршрутов ИИ-функционала.
8. **Тесты:**
   - **Unit:** `SubscriptionService` (subscribe/cancel/expire/expiry cron логика), `InstantPaymentGateway` (создание сессии, формирование return-URL, поведение refund=no-op), валидация, enum'ы.
   - **Feature (smoke):** оформление подписки через Dummy-провайдера → подписка `Active` → `is_premium` true; отмена → `Cancelled`; webhook на `/subscription/webhook` с известным payload → платёж записан; `/admin/payments` → 200, видит запись.
9. **Команда/Scheduler:** `subscriptions:expire` — перевод просроченных подписок в `Expired`. В `routes/console.php` (Laravel 11/12) настроить ежедневный запуск.

**Что получаем на выходе:**
- Пользователь видит тарифы, оформляет, отменяет.
- Состояние подписки консистентно: одно `Active` в момент времени, история не теряется.
- Админ видит все оплаты с деталями.
- Middleware `EnsurePremium` готов для следующего этапа.
- Абстракция `PaymentGateway` — реальный провайдер подключается без переписывания логики.

**Готовность:**
- Оформление через Dummy → подписка активна, `is_premium` true.
- Webhook с payload → платёж записан, идемпотентно (повторный вызов не дублирует).
- Отмена → подписка `Cancelled`, истечение срока → `Expired` через команду.
- `/admin/payments` показывает все записи, фильтры работают.
- `php artisan test` зелёный, `pint` зелёный, `phpstan` зелёный.

**Открытые вопросы этапа:**
- Реальный платёжный провайдер (ЮKassa, Stripe, Robokassa, CloudPayments и т.д.) — выбираем, подключаем в Этапе 3.1.
- Поддержка нескольких валют / региональные ограничения.
- Возврат (refund) — реализуем сразу или отложенно?
- Пробный период (trial) — нужен ли?
- Продление автоплатежом — сразу или со второй итерации?

---

### Этап 4. ИИ-каркас и промпты

**Цель:** заложить абстракцию для работы с ИИ (LLM), реализовать хранение и редактирование промптов через админку, сделать базовые сценарии (фидбэк на ошибку, генерация доп. задачи) **как сервисные методы без UI** — чтобы их мог дёргать любой будущий код. Премиум-доступ закрыт middleware.

**Архитектурное решение (зафиксировано):** single-tenant. **Единый** активный LLM-провайдер на всю платформу, выбирается через `.env` / `config/ai.php`. **UI для выбора провайдера в админке не предусмотрен** — только промпты.

**Что делаем:**

1. **Enum'ы:** `AiProvider { Openai, Anthropic, Minimax, OpenaiCompatible, Dummy }`. (Enum `AiPromptScope` больше не нужен — структура хранения промптов изменилась, см. ниже.)
2. **Хранение промптов (зафиксировано, см. `concept.md §9.2.4`):**
   - **Общий системный промпт** — в таблице `settings` (key/value, `key = 'ai.global_system_prompt'`, `value` = longtext). Один на всю платформу, редактируется через админку. Никаких отдельных таблиц `ai_prompts` / `ai_course_prompts`.
   - **Уточняющий промпт курса** — поле `courses.ai_course_prompt` (longtext, nullable). Свой для каждого курса, опционально дополняет общий.
   - При отсутствии уточняющего — используется только общий. При наличии — `PromptResolver` склеивает `global + "\n\n" + course`.
   - **Никаких** таблиц `ai_prompts` / `ai_course_prompts` — проще, меньше сущностей, легче администрировать.
3. **Миграции:**
   - `settings`: `id`, `key` (string, unique), `value` (longtext), `updated_by` (FK users nullable), timestamps. Универсальная key/value-таблица для «глобальных настроек» платформы (промпт, лимиты по умолчанию и т.п.). Первый сидер создаёт запись `key = 'ai.global_system_prompt'` с дефолтным значением.
   - `courses` (в Этапе 6) получает поле `ai_course_prompt` (longtext, nullable) — добавляется миграцией, когда Этап 6 начнётся.
4. **Сервисный слой:**
   - Интерфейс `App\Services\Ai\LlmClient` с методом `complete(string $systemPrompt, string $userMessage, array $options = []): LlmResponse`. Биндится в DI как singleton по конфигу `config('ai.provider')`.
   - **Реализации на старте (whitelist):**
     - `OpenAiLlmClient` — OpenAI API (`gpt-4o-mini` и аналоги).
     - `AnthropicLlmClient` — Anthropic Messages API (Claude).
     - `MiniMaxLlmClient` — MiniMax (контракт фиксируется при подключении).
     - `OpenAiCompatibleLlmClient` — для любого OpenAI-совместимого endpoint'а (локальные модели через Ollama/vLLM, прокси, региональные сервисы). Использует `AI_BASE_URL` из конфига.
     - `DummyLlmClient` — для dev/тестов: возвращает предсказуемый ответ, без HTTP.
   - `PromptResolver` (Action) — собирает финальный system-пrompt: берёт активный Global + (если есть) активный Course-специфичный, склеивает по правилу.
   - `AiFeedbackService` — `generateFeedbackFor(PracticeTaskSubmission $submission): string`. Использует `PromptResolver` + шаблон промпта для фидбэка.
   - `AiTaskGeneratorService` — `generateExtraTask(PracticeTask $task): array` (текст задания + ожидаемый результат).
5. **Конфигурация (`config/ai.php`):**
   - `provider` → enum `AiProvider`, по умолчанию `Dummy`.
   - `api_key` → строка из `AI_API_KEY` (обязательна для всех, кроме `Dummy`).
   - `model` → строка из `AI_MODEL` (например, `gpt-4o-mini`).
   - `base_url` → строка из `AI_BASE_URL` (только для `OpenAiCompatible`; обязательна).
   - `token_limit_global_per_day` → int из `AI_TOKEN_LIMIT_GLOBAL_PER_DAY` (по умолчанию, например, `100000`).
   - `token_limit_per_user_per_day` → int из `AI_TOKEN_LIMIT_PER_USER_PER_DAY` (по умолчанию, например, `5000`).
   - **Валидация на старте приложения** (`AppServiceProvider::boot()` или отдельный `AiConfigValidator`): формат URL, блокировка внутренних адресов для `OpenAiCompatible` (`localhost`, `127.0.0.1`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`) — защита от SSRF.
6. **Лимиты токенов (правило №16 `AGENTS.md`):**
   - **Миграция `ai_token_usages`:** `id`, `user_id` (FK), `model` (string), `tokens` (int), `action` (enum `AiTokenUsageAction` { Feedback, ExtraTask }), `created_at`. Индексы по `user_id`, `created_at` (для агрегации за день).
   - **Миграция `user_llm_limits`:** `user_id` (FK, primary), `extra_tokens` (int, default 0) — ручное добавление админом поверх дефолтного лимита. При создании пользователя запись создаётся автоматически.
   - **`AiTokenUsageService`** — `recordUsage(User, int $tokens, AiTokenUsageAction $action): void` пишет в `ai_token_usages`. `usedToday(User): int` агрегирует за сегодня. `remainingForUserToday(User): int` = `default_limit + extra_tokens - usedToday`. `remainingGlobalToday(): int` = `global_limit - sum(used today)`.
   - **`AiLimitGuard`** — middleware-обёртка (или вызов в `AiFeedbackService`/`AiTaskGeneratorService`): проверяет `remainingForUserToday > 0` **и** `remainingGlobalToday > 0` перед каждым вызовом `LlmClient`. При исчерпании — `HTTP 429`, без ретраев. **Fail loud**: лучше отказать в вызове, чем молчаливо превысить лимит.
   - **Интеграция в `AiFeedbackService`/`AiTaskGeneratorService`:** каждый вызов обёрнут в `AiLimitGuard::check()` → `LlmClient::complete()` → `AiTokenUsageService::recordUsage()` (по фактическим потраченным токенам, если провайдер отдаёт, иначе — оценка `strlen`).
   - **Unit-покрытие:** `AiLimitGuard` (лимит не исчерпан / глобальный исчерпан / пользовательский исчерпан / ручное добавление через `extra_tokens`), `AiTokenUsageService` (агрегация за день, корректная запись), `AiFeedbackService`/`AiTaskGeneratorService` (проверка лимита перед вызовом, запись после).
7. **Контроллеры админки (только для промптов и LLM-лимитов):**
   - `Admin\GlobalPromptController@edit`/`update` — редактирование **общего** системного промпта (через key/value в `settings` table). Через `FormRequest` (`AdminUpdateGlobalPromptRequest` с валидацией `body` не пустой).
   - `Admin\CoursePromptController@edit`/`update` — редактирование **уточняющего** промпта курса (поле `courses.ai_course_prompt`, через `Admin\CourseController` или выделенный контроллер). Через `FormRequest`. Операция попадает в audit-log (наследуется от логирования редактирования курса, см. `Admin\CourseController`).
   - `Admin\UserLlmLimitController@edit`/`update` (route `admin.users.llm-limit`, nested под `users/{user}/llm-limit`) — корректировка LLM-лимита пользователя. Через `FormRequest` (`AdminUpdateUserLlmLimitRequest` с валидацией `extra_tokens` int >= 0). Операция пишет запись в `admin_audit_logs` с `action = UserLlmLimitAdjusted` и `meta = { old_extra, new_extra }`.
   - **UI для выбора провайдера/ключа — не делаем.** Это инфраструктурная настройка, не бизнес-сущность.
8. **Политики:** `CoursePolicy@update` (включает редактирование `ai_course_prompt`), `UserPolicy@updateLlmLimit` — только Admin. Отдельная `AiPromptPolicy` не нужна — промпты редактируются как часть курса / настройки.
9. **Routes:** `admin.prompts.*`, `admin.users.llm-limit.*` под middleware `['auth', 'role:admin']`.
10. **Тесты:**
   - **Unit:** `PromptResolver` (Global-only / Global+Course / Course-only / empty Global fallback / склейка через `\n\n`), `AiFeedbackService` (формирует запрос к LLM с правильным промптом, проверяет лимит, записывает usage), `AiTaskGeneratorService`, `AiLimitGuard` (все ветки: лимит не исчерпан / глобальный исчерпан / пользовательский исчерпан / ручное добавление через `extra_tokens`), `AiTokenUsageService` (агрегация за день), `DummyLlmClient`, каждый реальный клиент (мокается внешний HTTP), валидация конфига провайдера (формат URL, SSRF-блокировка), enum, `AiProvider`/`AiTokenUsageAction` (метки, сравнения), `AdminUpdateUserLlmLimitAction` (корректировка лимита + запись audit-log), `Setting` (key/value) + `SettingRepository` (чтение `ai.global_system_prompt`).
   - **Feature (smoke):** админ редактирует Global-промпт → запись в `settings` с ключом `ai.global_system_prompt`, видна на `/admin/global-prompt`; админ редактирует уточняющий промпт курса → запись в `courses.ai_course_prompt`; `PromptResolver` дёргается из теста через DI и возвращает ожидаемую склейку; админ корректирует LLM-лимит пользователя → запись в `admin_audit_logs` с правильным `meta`.
11. **Логирование:** все обращения к LLM (провайдер, model, длина запроса/ответа, длительность, ошибки) — через `Log::info('ai.llm_call', [...])` для последующего анализа расходов. **Полный текст промпта/ответа в production-логи не пишем** (риск утечки + раздувание storage). В dev-окружении допустимо через флаг `config('ai.log_full_prompts', false)`.

**Что получаем на выходе:**
- Абстракция `LlmClient` + 4 whitelist-реализации + `DummyLlmClient` для dev/тестов.
- `PromptResolver` с предсказуемой логикой склейки.
- `AiFeedbackService` и `AiTaskGeneratorService` готовы к подключению из практики, с проверкой лимитов и записью usage.
- **Лимиты токенов:** глобальный + per-user, из конфига, с возможностью ручной корректировки админом (audit-log).
- Админка для редактирования промптов и LLM-лимитов пользователей.
- Конфиг провайдера через `.env` с валидацией на старте.
- Все вызовы логируются (метаданные).

**Готовность:**
- Admin может редактировать Global-промпт и видеть изменения.
- `PromptResolver` корректно склеивает Global + Course.
- `DummyLlmClient` используется в тестах, ответы детерминированы.
- **Лимиты:** пользователь с исчерпанным лимитом получает `HTTP 429`; админ через `/admin/users/{user}/llm-limit` может добавить `extra_tokens` (запись в `admin_audit_logs`).
- `php artisan test` зелёный, `pint` зелёный, `phpstan` зелёный.
- `config('ai.provider') = 'dummy'` по умолчанию — приложение стартует без реального ключа.

**Открытые вопросы этапа:**
- ~~Конкретный LLM-провайдер~~ — закрыто: whitelist (OpenAI, Anthropic, MiniMax, OpenAI-compatible) + выбор через `AI_PROVIDER` в `.env`. Single-tenant.
- ~~Лимиты на расход токенов/бюджет~~ — закрыто: глобальный + per-user лимит в `config/ai.php`, ручная корректировка админом через `/admin/users/{user}/llm-limit` (см. `concept.md §2.3`).
- ~~Хранение промптов: одна таблица с `scope` или две отдельные (`ai_prompts` + `ai_course_prompts`)~~ — закрыто: **общий в `settings` (key/value)**, **уточняющий как поле `courses.ai_course_prompt`**. Никаких таблиц `ai_prompts` / `ai_course_prompts`. Подробности в `concept.md §9.2.4` и в пункте 2 этого этапа.
- Версионирование промптов (см. `concept.md §9.2.4`).
- Тестовый запуск промпта из админки (UI playground) — со старта или позже.

---

### Этап 5. Каркас изоляции среды для практики

**Цель:** заложить абстракцию изолированной среды исполнения практических заданий, чтобы **Этап «Курсы»** мог на неё опереться. На старте — **in-process SQLite-файлы** (вариант A), без Docker-зависимости. Контейнерная изоляция (вариант B) — отдельный этап после Этапа 6, см. §5.4 `concept.md` и «Открытые вопросы» ниже.

**Архитектурное решение (зафиксировано):**
- **Старт — `LocalSqlitePracticeEnvironment`**, единственная реализация. Каждая попытка = новый временный файл `storage/framework/practice/{uuid}.sqlite`, запрос студента выполняется через PDO, файл удаляется в `finally`. **Работает на Windows-OSPanel и любом dev-окружении** без Docker.
- **Прод — отдельный этап «Docker-изоляция для практики»** (после Этапа 6 «Базовый каталог курсов»). На этом этапе появится `DockerPracticeEnvironment` (контейнеры `mysql:8`, `postgres:16`, `python:3.12` и т.п.). Переключение через `config('practice.driver')` — никаких правок в Action/Service/Controller, как у нас с `PaymentGateway` и `LlmClient`.
- **Не используем** Firecracker/gVisor/kata-containers (C), serverless-функции (D), внешние sandbox-сервисы типа Judge0 (E), WebAssembly-рантаймы (F). Зафиксировано.
- **Рантаймы на старте — только SQL через SQLite.** Поддержка Bash/Python/Node — добавляется отдельными этапами, когда появится контент.

**Что делаем:**

1. **Enum'ы:** `PracticeEnvironmentStatus { Provisioning, Ready, Running, Failed, Destroyed }`, `PracticeRuntime { Sqlite }` (расширяется, когда добавляются Docker-реализации).
2. **Миграции (если храним в БД):**
   - `practice_environments`: `id`, `user_id`, `practice_task_id` nullable, `runtime`, `status`, `connection_meta` (json, без секретов), `started_at`, `destroyed_at`, `error` (text nullable), timestamps.
3. **Интерфейс `PracticeEnvironmentManager`:**
   - `provision(User, PracticeTask $task): PracticeEnvironment` — поднимает среду.
   - `execute(PracticeEnvironment, string $code): ExecutionResult` — запускает запрос, возвращает результат или ошибку.
   - `compare(ExecutionResult $actual, mixed $expected): bool` — сравнение с эталоном (хеш-эталон + каноническая сериализация, см. ниже).
   - `destroy(PracticeEnvironment): void` — чистит среду.
4. **Реализация на старте — `LocalSqlitePracticeEnvironment`:**
   - На `provision()`: создаёт `storage/framework/practice/{uuid}.sqlite`, прогоняет seed-скрипт задания (если есть), возвращает `PracticeEnvironment` с метаданными (путь к файлу).
   - На `execute()`: открывает PDO, применяет **обязательные защиты** (см. пункт 6), выполняет запрос, формирует `ExecutionResult` (rows + columns + duration + error).
   - На `destroy()`: закрывает PDO, удаляет файл. **Всегда** вызывается через `finally` в `RunPracticeTaskAction`.
5. **Service/Action:** `RunPracticeTaskAction` — оркестратор: provision → execute → compare → (если success) destroy, (если error) feedback через ИИ (Этап 4) для премиум → destroy. Всё в `try/finally`, среда уничтожается **всегда** (даже при исключении).
6. **Безопасность `LocalSqlitePracticeEnvironment` (обязательные защиты, покрыты Unit-тестами):**
   - **Таймаут** на исполнение (5 секунд по умолчанию, конфиг `config('practice.sqlite.timeout_seconds')`).
   - **Лимит на размер результата** (1 МБ JSON по умолчанию, `config('practice.sqlite.max_result_bytes')`).
   - **Запрет `ATTACH DATABASE`** — парсим SQL перед выполнением, отклоняем если найдено.
   - **Запрет множественных statements** — через `SQLite` single-statement mode (разбиваем по `;` и оставляем только первый, либо используем низкоуровневый API).
   - **`SQLite::enableLoadExtension(false)`** — отключает загрузку расширений.
   - **Лимит на размер БД** (100 МБ, `config('practice.sqlite.max_db_bytes')`) — чтобы студент не забил диск.
   - **Анти-DoS:** лимит одновременных сред на пользователя (1) — через `Cache::lock`.
7. **Сравнение результатов — хеш-эталон + каноническая сериализация:**
   - `PracticeTask.expected_hash` (string, 64 hex) — хеш эталона, вычисляется при создании/редактировании задания через `CanonicalResultSerializer::hash(mixed $rows, array $columns)`.
   - `CanonicalResultSerializer`: `ORDER BY` по всем колонкам, строки `trim`+`lower`, числа как строки с фиксированной точностью, JSON-сериализация, `hash('sha256', ...)`.
   - `compare()` сериализует результат студента **тем же методом** и сравнивает хеши. Совпало — пройдено. Не совпало — `ExecutionResult::diff` показывает разницу (эталон vs попытка) для UI.
8. **Конфиг `config/practice.php`:**
   - `driver` → `local-sqlite` (по умолчанию). Будет `docker` после этапа «Docker-изоляция».
   - `sqlite.timeout_seconds`, `sqlite.max_result_bytes`, `sqlite.max_db_bytes` — лимиты.
   - `storage_path` → `storage/framework/practice` (создаётся автоматически).
9. **Тесты:**
   - **Unit:** `LocalSqlitePracticeEnvironment` (provision / execute SELECT / execute INSERT / execute с ошибкой синтаксиса / execute с таймаутом / execute с превышением размера / execute с запрещённым `ATTACH` / execute с множественными statements / destroy / destroy вызывается даже при исключении), `RunPracticeTaskAction` (success / error / таймаут / destroy вызван всегда), `CanonicalResultSerializer` (хеш одинаков для разного порядка строк / регистр строк / формат чисел), сравнение, enum.
   - **Feature (smoke):** HTTP-эндпоинт `POST /practice-tasks/{task}/submit` (заглушка) → вызывает `RunPracticeTaskAction` → возвращает `{ status, result, expected }` (минимум).

**Что получаем на выходе:**
- Контракт `PracticeEnvironmentManager` + единственная реализация `LocalSqlitePracticeEnvironment`.
- `RunPracticeTaskAction` оркестрирует полный цикл в `try/finally`.
- Все 6 защит реализованы и покрыты Unit-тестами.
- `CanonicalResultSerializer` для предсказуемого сравнения результатов.
- Анти-DoS через `Cache::lock`.
- Подключение Docker-реализации — замена одной строки в конфиге, без правок остального кода.

**Готовность:**
- SQL-запрос пользователя выполняется в изолированной SQLite-БД, возвращает результат, среда уничтожается.
- Все 6 защит работают (запрет `ATTACH`, no-multiple, таймаут, лимит размера, лимит БД, `enableLoadExtension(false)`).
- Таймаут/ошибка/DoS-кейсы покрыты тестами.
- Сравнение хешей работает (порядок строк и регистр не ломают «правильный» ответ).
- `php artisan test` зелёный, `pint` зелёный, `phpstan` зелёный.

**Открытые вопросы этапа:**
- ~~Технология изоляции для прода~~ — закрыто: на старте SQLite, в проде — Docker отдельным этапом (см. ниже).
- ~~Сравнение результатов: точное / нормализация / структурное~~ — закрыто: хеш-эталон + `CanonicalResultSerializer`.
- ~~Поддержка нескольких рантаймов (MySQL, PostgreSQL, Bash, Python) на старте~~ — закрыто: только SQLite. Остальные — когда появится контент.
- **Этап «Docker-изоляция для практики» (после Этапа 6):** добавляет `DockerPracticeEnvironment`, поддержку `Mysql`, `Postgres`, `Python`, `Bash` рантаймов. Задачи: образы, сидинг, лимиты по памяти/CPU, очистка висящих контейнеров, метрики. Будет детально проработан, когда дойдём.
- **Антифрод/лимиты на проде:** общая очередь исполнения, чтобы не положить хост — отдельная задача, решается вместе с Docker-этапом.

---

### Этап 6. Базовый каталог курсов (только список, без прохождения)

**Цель:** пользователь видит каталог курсов на главной, может открыть карточку курса, увидеть структуру (уровни → уроки). Без прохождения, без заданий, без прогресса — это следующие этапы. **Минимум моделей и контроллеров**, чтобы каркас админки и фронт каталога сшились.

**Что делаем:**

1. **Enum'ы:** `CourseStatus { Draft, Published, Archived }`, `Level { Beginner, Intermediate, Advanced }`.
2. **Миграции:**
   - `courses`: `id`, `slug` (unique), `title`, `description`, `preview_image_path` (string, nullable) — путь к файлу в `Storage::disk('public')`, `status` (enum), `ai_course_prompt` (longtext, nullable), `created_by`, timestamps. Сами файлы лежат в `storage/app/public/courses/previews/{uuid}.{ext}`.
   - `levels`: `id`, `course_id` (FK), `level` (enum), `order` (int), `title` (nullable). Unique `(course_id, level)`.
   - `lessons`: `id`, `level_id` (FK), `slug` (unique), `title`, `order` (int), `material` (longtext), `is_published` (bool), timestamps.
3. **Модели:** `Course`, `Level`, `Lesson` — relations, scopes (`published`, `ordered`), accessors. `slug` через `Str::slug` в мутаторе/observer.
4. **Контроллеры пользователя:**
   - `CatalogController@index` — список опубликованных курсов.
   - `CourseController@show` — карточка курса с разворотом уровней/уроков (без прогресса).
5. **Контроллеры админки (Этап 2, заглушки → реальные):**
   - `Admin\CourseController` — CRUD курса.
   - `Admin\LevelController` — CRUD уровня внутри курса.
   - `Admin\LessonController` — CRUD урока внутри уровня (без заданий — это Этап 7).
6. **Routes:**
   - `/` и `/courses` — публичный каталог.
   - `/courses/{slug}` — публичная карточка.
   - `/admin/courses/*`, `/admin/courses/{course}/levels/*`, `/admin/levels/{level}/lessons/*` — админ.
7. **FormRequest'ы:** `AdminCreateCourseRequest`, `AdminUpdateCourseRequest`, аналоги для уровня/урока.
8. **Policy:** `CoursePolicy@view` (опубликованный виден всем, остальное — admin), `CoursePolicy@manage` (только admin).
9. **Resource'ы (для API или AJAX):** `CourseResource`, `LevelResource`, `LessonResource` (без `material` в списке, с `material` в show).
10. **Seeders:** `DemoCourseSeeder` — 1 опубликованный курс с двумя уровнями и парой уроков для проверки каталога.
11. **Тесты:**
    - **Unit:** модели (relations, scopes, slug), политики, валидация FormRequest, enum'ы.
    - **Feature (smoke):** `/` → 200, виден seed-курс; `/courses/{slug}` → 200, видна структура; `/admin/courses` CRUD smoke.

**Что получаем на выходе:**
- Публичный каталог и карточка курса.
- Полный CRUD курсов/уровней/уроков в админке.
- Без прохождения и заданий — это Этапы 7–8.
- Slug-based URL, транзакции на создание связки «курс → уровень → урок» в одном Action.

**Готовность:**
- Каталог и карточка курса показывают seed-данные.
- Админ создаёт курс с уровнями и уроками, всё видно публично.
- `php artisan test` зелёный, `pint` зелёный, `phpstan` зелёный.

**Открытые вопросы этапа:**
- ~~Поле `material` — хранить как longtext в БД или вынести в Markdown-файлы / отдельный `lesson_contents`~~ — закрыто: **longtext в БД** (поле `lessons.material`, как уже зафиксировано в миграции). Markdown-рендеринг — на стороне Vue-фронта (библиотека типа `marked` или `markdown-it`). Загрузка картинок внутри материала — по URL. Никаких файлов на диске, никакой отдельной таблицы `lesson_contents` — проще администрировать, всё в одном месте, версионируется через `updated_at`.
- ~~Превью-картинки: загрузка через админку или только URL~~ — закрыто: **загрузка через админку** (поле `courses.preview_image` остаётся nullable, но в админке — file-upload через `multipart/form-data`). Хранение — **через Laravel Storage** на диске (`storage/app/public/courses/previews/{uuid}.{ext}`), `Storage::disk('public')`, `preview_image_path` строка в БД. **Не используем** внешние URL-ы (CDN, s3) на старте — переход на S3-подобное делается заменой диска в `config/filesystems.php`, без правок кода. UI: Vue-компонент с `<input type="file" accept="image/*">` + `FormData` + `POST` через Inertia. Валидация в FormRequest: `mimes:jpg,jpeg,png,webp`, `max:2048` (2 МБ), `dimensions:min_width=200,min_height=200`. На диске — `Intervention\Image` (или встроенный GD) для **ресайза до 1200×630** + WebP-конверсия (опционально, через отдельный job). Тримминг EXIF (защита от утечки гео-данных).
- Сортировка курсов в каталоге: по дате / вручную / по популярности?

---

## Граница плана

**Этап 7. Теоретические задания и прогресс** — на стыке с Этапом 6, идёт сразу после. **За границей** этого документа.

**Этап 8. Практические задания + ИИ-фидбэк + ИИ-генерация** — опирается на Этапы 4 (ИИ) и 5 (среда исполнения). **За границей** этого документа.

**Этап 9. Премиум-гейтинг практики и ИИ** — middleware `EnsurePremium` уже готов из Этапа 3, в Этапе 8 навешивается на маршруты. **За границей** этого документа.

Дальнейшие этапы (расширенная админка, аналитика, сертификация, импорт контента, антифрод для прод-нагрузок) оформляются отдельными документами по мере проработки.
