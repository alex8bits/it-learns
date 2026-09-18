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
2. Установить **базовые пакеты** (фиксируются здесь, чтобы не размазывать по этапам):
   - `composer require laravel/fortify` — auth (правило №19 `AGENTS.md`, Этап 1).
   - `composer require spatie/laravel-permission` — RBAC (правило №20 `AGENTS.md`, Этап 2). **Используем с самого начала**, чтобы Этап 1 уже создавал пользователей с Spatie-ролью `User`, а Этап 2 не пришлось переделывать.
3. Настроить **качество кода**:
   - **Pint** — стиль (уже в dev-зависимостях). Сконфигурировать `pint.json` под командные правила (если есть), прогнать `vendor/bin/pint --test`.
   - **PHPStan / Larastan** на **уровне 7** (статический анализ; зафиксировано пользователем). Подключить через `composer require --dev larastan/larastan`, настроить `phpstan.neon` с `level: 7`, прогнать на текущей кодовой базе, зафиксить baseline. Повышение до 8–9 — отдельное решение с явным baseline.
4. Тест-раннер: **PHPUnit** (зафиксировано). В `composer.json` уже есть `phpunit/phpunit: ^12.5.12` — оставляем. Конфиг `phpunit.xml` настраиваем под it-learns (`:memory:` SQLite для тестов, разделение `test` / `test-coverage`). Pest не подключаем.
5. Включить **запрет lazy loading в dev-окружении**: в `AppServiceProvider::boot()` добавить `Model::preventLazyLoading(! app()->isProduction())`.
6. **Конфигурация `phpunit.xml`**: разделить `test` (полный набор) и `test-coverage`, проверить, что БД для тестов — `:memory:` SQLite (или отдельная test-БД).
8. Создать папку `docs/` (уже создана) и зафиксировать в `README.md` ссылки на `AGENTS.md`, `docs/concept.md`, `docs/platform-plan.md`.
9. `.env.example` — актуализировать под нужды платформы (mail, queue, payment, AI-провайдер — заглушки пока).

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
- ~~CI-платформа (GitHub Actions / GitLab CI / локально)~~ — закрыто: **не используем CI** (решение пользователя). Все проверки (`php artisan test`, `vendor/bin/pint --test`, `vendor/bin/phpstan analyse`) запускаются локально перед коммитом, см. `AGENTS.md §3.1` и §3.4. Если в будущем понадобится CI — отдельное решение, не блокирует старт.

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
   - Внутри `DB::transaction` (правило №6) создаём `User` с `name`, `email`, `password` (хеш через `Hash::make`).
   - Назначаем Spatie-роль `User` через `$user->assignRole(UserRole::User->value)` (правило №20). `UserRole` enum остаётся как справочник, **не** как колонка в `users` (миграции spatie/laravel-permission создают свои таблицы `roles` / `permissions` / `model_has_roles`).
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
   - **Unit:** `CreateNewUser` (валидные данные / дубликат email / хеширование пароля / создан с назначенной Spatie-ролью `User` / транзакция откатывается при ошибке), `ResetUserPassword` (валидный токен / просроченный / использованный повторно), `UpdateUserPassword` (старый пароль неверный / новый совпадает со старым — запрет), enum `UserRole` (метки, значения — для типизации, не для хранения). **Не тестируем** внутренности Fortify — это апстрим-пакет. **Не тестируем** внутренности spatie/laravel-permission — тоже апстрим-пакет; тестируем только **своё использование** (что `assignRole` вызван с правильным значением).
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

1. **Enum'ы:** `App\Enums\UserRole` с кейсами `User`, `Admin` (**справочник для типизации, не хранилище**). `App\Enums\AdminAuditAction` (стартовый набор: `UserRoleChanged`, `UserBlocked`, `UserUnblocked`; расширяется по мере появления операций).
2. **Spatie RBAC (по правилу №20 `AGENTS.md`):**
   - Пакет уже установлен в Этапе 0. Публикуем миграции и конфиг: `php artisan vendor:publish --provider="Spatie\Permission\PermissionServiceProvider"`.
   - Прогоняем `php artisan migrate` — создаются таблицы `roles`, `permissions`, `model_has_permissions`, `model_has_roles`, `role_has_permissions`.
   - `User` модель использует трейт `Spatie\Permission\Traits\HasRoles` (правило №20).
   - `users.role` колонка **не** создаётся — это хранение в Spatie, не в `users`.
3. **Миграция:** добавить только `users.is_blocked` (default `false`). Создать таблицу `admin_audit_logs` (`id`, `admin_id` FK, `action` enum, `subject_type` string, `subject_id` bigint nullable, `meta` json, `ip` string nullable, `user_agent` string nullable, `created_at`) с индексами по `admin_id`, `action`, `subject_type+subject_id`, `created_at`.
4. **Сидер:**
   - `RolesAndPermissionsSeeder` — создаёт Spatie-роли `User` и `Admin` через `Role::create(['name' => UserRole::User->value])` (значение из enum, не хардкод).
   - `AdminSeeder` — создаёт пользователя-админа из `ADMIN_EMAIL`/`ADMIN_PASSWORD` (или генерирует пароль и пишет в лог), назначает роль `Admin` через `$user->assignRole(UserRole::Admin->value)`.
5. **Только Policy** (по правилу №7 `AGENTS.md`):
   - На каждую доменную сущность (User, Course, Level, Lesson, TheoryTask, PracticeTask, Payment, AiPrompt, AdminAuditLog, UserLlmLimit) — своя `XxxPolicy` с методами `viewAny`, `view`, `create`, `update`, `delete`.
   - **Не используем** `Gate::define('admin', ...)` — это конфликтует с правилом №7.
   - **Не используем** прямое `$user->role === 'admin'` нигде в Policy — только `$user->hasRole(UserRole::Admin->value)` или `$user->can('manage users')` (правило №20).
   - Middleware `role:admin` (Spatie) — **только как групповой gatekeeper** на уровне маршрутов (`/admin/*`). Бизнес-операции внутри — через Policy.
6. **Routes:**
   - Группа `/admin` с middleware `['auth', 'role:admin']` (`role` — это Spatie middleware из `Spatie\Permission\Middleware\RoleMiddleware`).
   - Подгруппы: `admin.users.*`, `admin.payments.*`, `admin.courses.*`, `admin.prompts.*`, `admin.audit-logs.*` — пока пустые контроллеры с `index()`-заглушками.
7. **Контроллеры `App\Http\Controllers\Admin\...`:**
   - `DashboardController@index` — счётчики: пользователи всего, премиум сейчас, оплаты за месяц, курсы опубликованные. Получает данные через `AdminDashboardService` (Action), не из контроллера.
   - `UserController` (index/show/update) — список с фильтрами, карточка, операции смены роли / блокировки / разблокировки. Смена роли — через `$user->syncRoles([UserRole::Admin->value])` или `removeRole/assignRole`. **Каждая операция пишет запись в `admin_audit_logs`** (через `AdminAuditLogger` сервис).
   - `PaymentController` (index/show) — список с фильтрами (заглушка, реальные данные — в Этапе 3).
   - `CourseController` (index/show) — заглушка, реальный CRUD — в Этапе 5+ «Курсы».
   - `PromptController` (index/edit) — заглушка, реальный CRUD — в Этапе 4 «ИИ».
   - `AuditLogController@index` — только чтение, фильтры по `admin_id`, `action`, периоду. Удаление/правка запрещены на уровне Policy.
8. **FormRequest'ы:** `AdminUpdateUserRequest` (смена роли, блокировка), фильтры — через отдельные Request-классы или query-builder.
9. **Layout:** `layouts/admin.blade.php` — **только host-шаблон для Inertia** (single root template с `<div id="app">`), как требует правило №18 `AGENTS.md`. Никаких Blade-страниц с формами, навигацией или бизнес-логикой внутри авторизованной админки. Контент админки (навигация, страницы) — Vue-компоненты.
10. **Сервис аудита:** `App\Services\Admin\AdminAuditLogger` с методом `log(AdminAuditAction $action, Model $subject, array $meta = []): void` — пишет запись от имени текущего админа, выдёргивает `ip` и `user_agent` из `request()`. Используется во всех админ-Action'ах в одной `DB::transaction` с самой операцией.
11. **Политики:** `UserPolicy@view/admin` (админ может смотреть/редактировать — внутри `view`/`update` методов проверка через `$user->hasRole(UserRole::Admin->value)`), `CoursePolicy`/`PromptPolicy` (заглушки, готовятся к наполнению), `AdminAuditLogPolicy@view` (только Admin).
12. **Тесты:**
    - **Unit:** `RolesAndPermissionsSeeder` (создаёт роли User и Admin), `AdminSeeder` (создаёт админа + назначает роль), `UserPolicy`/`AdminPolicy`, `AdminDashboardService` (формирование счётчиков), `AdminAuditLogger` (формирует корректную запись, мерджит meta, обрабатывает null-subject), валидация в `AdminUpdateUserRequest`, enum `UserRole` (метки, значения) и `AdminAuditAction` (метки, значения).
    - **Feature (smoke):** не-админ → `/admin` → 403; админ → `/admin` → 200; `/admin/users` → 200; попытка смены роли самого себя админом → 422/403; смена роли → запись в `admin_audit_logs` создана.
13. **Seeders/factories:** `UserFactory` обновлена — состояния `admin()` (назначает роль Admin), `blocked()`. Поле `role` в `users` **не** существует.

**Что получаем на выходе:**
- Роли через Spatie (`spatie/laravel-permission`), `UserRole` enum как справочник типизации (правило №20 `AGENTS.md`). Проверки — `$user->hasRole(UserRole::Admin->value)`, `$user->can('manage users')` или Policy. Никакого хардкода `auth()->user()->role === 'admin'`.
- Каркас админки: layout + навигация + 6 разделов (часть — заглушки).
- Spatie middleware `role:admin` reusable для будущих маршрутов.
- **Audit-лог инфраструктура:** таблица `admin_audit_logs`, enum `AdminAuditAction`, `AdminAuditLogger` сервис, `AuditLogController@index` (только чтение). Готова к использованию во всех последующих этапах.
- Seeded admin-аккаунт + Spatie-роли для локальной разработки.
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

**Цель:** пользователь может оформить месячную премиум-подписку. Состояние подписки хранится в системе и влияет на доступ к ИИ (флаг `is_premium`). История оплат видна в админке. **Интеграции с боевым платёжным провайдером на этапе НЕТ, и она не является критерием приёмки**: сдаём фабрику гейтов + фиктивный (`dummy`) гейт, позволяющий получить премиум без реальной оплаты; боевые гейты подключаются позже (Этап 3.1) добавлением класса в фабрику.

**Что делаем:**

1. **Enum'ы:** `SubscriptionTier { Free, Premium }`, `SubscriptionStatus { Active, Cancelled, Expired, Pending }`, `PaymentStatus { Pending, Succeeded, Failed, Refunded }`.
2. **Миграции:**
   - `subscriptions`: `user_id`, `tier`, `status`, `starts_at`, `ends_at`, `cancelled_at`, `external_id` (от провайдера), `provider` (yookassa/stripe/etc), timestamps.
   - `payments`: `user_id`, `subscription_id` nullable, `amount`, `currency`, `status`, `external_id`, `provider`, `payload` (json — для аудита webhook'ов), timestamps.
3. **Фабрики/seeders:** `SubscriptionFactory` (состояния `active`, `expired`, `cancelled`), `PaymentFactory`.
4. **Сервисный слой:**
   - `SubscriptionService` (или `SubscriptionAction`): `subscribe(User, tier)`, `cancel(User)`, `isActive(User)`, `expiresAt(User)`. Все мутации — в `DB::transaction`.
   - Интерфейс `App\Services\Payments\PaymentGateway` с методами `createCheckoutSession(User, tier)`, `handleWebhook(Request)`, `refund(Payment)`.
   - **Фабрика гейтов (зафиксировано):** whitelist-подход, единый паттерн с `LlmClient` (Этап 4). `config/payments.php` → ключ `provider` из `PAYMENT_PROVIDER` (`.env`); на этом этапе whitelist = `['dummy']`. Интерфейс биндится в `AppServiceProvider` по конфигу. **Добавление нового гейта (в т.ч. боевого)** = новый класс-реализация + значение в whitelist + секция ключей в `.env.example` — без правок `SubscriptionService`, контроллеров и фронтенда.
   - **`DummyPaymentGateway`** — фиктивный гейт для dev/тестов/демо (имя консистентно с `DummyLlmClient`): «нажал Купить премиум → сразу получил подписку на месяц» **без реальной оплаты**. `createCheckoutSession()` возвращает URL `/subscription/checkout/return?session=...`, Action по этому URL сам помечает подписку `Active` с `ends_at = +1 month`. `handleWebhook()` — обрабатывает только синтетический тестовый payload (идемпотентно); реальный трафик провайдеров не принимается. `refund()` — no-op с возвратом `true` (для совместимости с интерфейсом). UI-флоу: короткая задержка 1–2 сек на `/subscription/checkout/return` со спиннером «Обрабатываем платёж...» + редирект.
5. **Контроллеры/маршруты пользователя:**
   - `/pricing` — страница тарифов.
   - `/subscription` — текущий статус, кнопка «Оформить/Отменить».
   - `/subscription/checkout` → редирект на gateway.
   - `/subscription/webhook` — приём callback'ов от провайдера, идемпотентная обработка. Эндпоинт и идемпотентность строятся сейчас как инфраструктура для боевых гейтов (Этап 3.1); критерий приёмки — синтетический payload в тесте, реальный трафик не требуется.
6. **Контроллеры админки (Этап 2, заглушки → реальные):**
   - `Admin\PaymentController@index` — список с фильтрами.
   - `Admin\PaymentController@show` — детальный просмотр, payload от провайдера.
   - `Admin\UserController@show` — добавить блок «Подписки/оплаты» в карточку пользователя.
7. **Gate/Policy:** `SubscriptionPolicy@accessPremium` (проверка `isActive`). Middleware `EnsurePremium` для маршрутов ИИ-функционала.
8. **Тесты:**
   - **Unit:** `SubscriptionService` (subscribe/cancel/expire/expiry cron логика), `DummyPaymentGateway` (создание сессии, формирование return-URL, поведение refund=no-op), валидация, enum'ы.
   - **Feature (smoke):** оформление подписки через `DummyPaymentGateway` → подписка `Active` → `is_premium` true; отмена → `Cancelled`; webhook на `/subscription/webhook` со синтетическим payload → платёж записан; `/admin/payments` → 200, видит запись.
9. **Команда/Scheduler:** `subscriptions:expire` — перевод просроченных подписок в `Expired`. В `routes/console.php` (Laravel 11/12) настроить ежедневный запуск.

**Что получаем на выходе:**
- Пользователь видит тарифы, оформляет, отменяет.
- Состояние подписки консистентно: одно `Active` в момент времени, история не теряется.
- Админ видит все оплаты с деталями.
- Middleware `EnsurePremium` готов для следующего этапа.
- Фабрика гейтов `PaymentGateway` (конфиг `PAYMENT_PROVIDER`, whitelist в `config/payments.php`): боевой провайдер подключается позже новым классом + строкой whitelist, без переписывания остального кода (Этап 3.1).

**Готовность (без боевого гейта):**
- Оформление через `DummyPaymentGateway` → подписка `Active`, `is_premium` true — премиум получается без реальной оплаты.
- Webhook со синтетическим payload → платёж записан, идемпотентно (повторный вызов не дублирует).
- Отмена → подписка `Cancelled`, истечение срока → `Expired` через команду.
- `/admin/payments` показывает все записи, фильтры работают.
- Новый гейт добавляется классом-реализацией + строкой whitelist в `config/payments.php`, без правок остального кода.
- `php artisan test` зелёный, `pint` зелёный, `phpstan` зелёный.
- **Наличие боевого платёжного провайдера НЕ входит в критерии приёмки Этапа 3** — вынесено в Этап 3.1.

**Открытые вопросы этапа:**
- **Этап 3.1 «Боевой платёжный гейт» (не критерий приёмки Этапа 3):** выбор провайдера (ЮKassa, Stripe, Robokassa, CloudPayments и т.д.), реализация в whitelist фабрики, реальные ключи/вебхуки, тестирование на боевых транзакциях.
- Поддержка нескольких валют / региональные ограничения — к Этапу 3.1.
- Возврат (refund) реальными средствами — к Этапу 3.1 (в `DummyPaymentGateway` — no-op).
- Пробный период (trial) — нужен ли? (Добавляется в `SubscriptionService` без изменения фабрики, может быть и на Этапе 3.)
- Продление автоплатежом — к Этапу 3.1 (требует реального провайдера).

---

### Этап 4. ИИ-каркас и промпты

**Цель:** заложить абстракцию для работы с ИИ (LLM), реализовать хранение и редактирование промптов через админку, сделать базовые сценарии (фидбэк на ошибку, генерация доп. задачи) **как сервисные методы без UI** — чтобы их мог дёргать любой будущий код. Премиум-доступ закрыт middleware.

**Архитектурное решение (зафиксировано):** single-tenant. **Единый** активный LLM-провайдер на всю платформу, выбирается через `.env` / `config/ai.php`. **UI для выбора провайдера в админке не предусмотрен** — только промпты.

**Что делаем:**

1. **Enum'ы:** `AiProvider { Openai, Anthropic, Minimax, OpenaiCompatible, Dummy }`. (Enum `AiPromptScope` больше не нужен — структура хранения промптов изменилась, см. ниже.)
2. **Хранение промптов (зафиксировано, см. `concept.md §9.2.4`):**
   - **Источник истины — таблица `ai_prompt_versions`.** Каждое изменение промпта = новая запись, история не перезаписывается.
   - **`settings.ai.global_system_prompt`** и **`courses.ai_course_prompt`** — это **кэш** активной версии для быстрого чтения `PromptResolver`'ом. Источник истины — `ai_prompt_versions`, активная = `MAX(version_number) WHERE prompt_key = ?`.
   - **Ключи в `ai_prompt_versions.prompt_key`:**
     - `'ai.global_system_prompt'` — общий системный промпт.
     - `course.{id}.ai_course_prompt` — уточняющий промпт курса.
   - **Никаких** таблиц `ai_prompts` / `ai_course_prompts` — проще, меньше сущностей, легче администрировать.
   - *Реализация: кэш-цель course-ключей (`courses.ai_course_prompt`) и `CoursePromptController` подключаются в Этапе 6 вместе с таблицей `courses`; в Этапе 4 `PromptVersionService` generic по `prompt_key`, кэш реализован для `ai.global_system_prompt` (решение от 2026-09-14).*
3. **Миграции:**
   - **`ai_prompt_versions`:** `id`, `prompt_key` (string), `body` (longtext), `version_number` (int, автоинкремент в пределах `prompt_key`), `comment` (text nullable), `created_by` (FK users), `meta` (json nullable), `created_at` (timestamp). Индексы по `(prompt_key, version_number)` (уникальный) и `created_at`.
   - `settings`: `id`, `key` (string, unique), `value` (longtext), `updated_by` (FK users nullable), timestamps. Универсальная key/value-таблица для «глобальных настроек» платформы (промпт, лимиты по умолчанию и т.п.). Первый сидер создаёт запись `key = 'ai.global_system_prompt'` с дефолтным значением **и** первую запись в `ai_prompt_versions` с `version_number = 1`.
   - `courses` (в Этапе 6) получает поле `ai_course_prompt` (longtext, nullable) — добавляется миграцией, когда Этап 6 начнётся. Создание курса с уточняющим промптом = создание первой версии в `ai_prompt_versions`.
4. **Сервисный слой:**
   - **`PromptVersionService`** (см. `concept.md §9.2.4`):
     - `createNewVersion(string $promptKey, string $body, ?string $comment, User $author): AiPromptVersion` — в `DB::transaction`: вычисляет `MAX(version_number) WHERE prompt_key = ?`, создаёт новую запись с `+1`, обновляет кэш (для `ai.global_system_prompt` — строка `settings`; кэш-цель course-ключей — Этап 6), пишет в `admin_audit_logs` (`action = PromptVersionCreated`, `meta = { prompt_key, version, comment }`).
     - `rollbackTo(AiPromptVersion $version, User $author): AiPromptVersion` — принимает модель (route model binding), вызывает `createNewVersion` с её `body` и `comment = 'Rollback to v{N}'`. Никаких «указателей на прошлое» — линейная история (решение от 2026-09-14).
     - `getHistory(string $promptKey, int $limit = 50): Collection` — все версии по ключу, newest first.
   - `PromptResolver` — глобальный текст читает из кэша `settings`, course-текст получает параметром от вызывающего (в Этапе 4 — DTO сценарных сервисов, с Этапа 6 — `courses.ai_course_prompt`), не из `ai_prompt_versions` напрямую. Склеивает `global + "\n\n" + course` если оба есть.
   - Интерфейс `App\Services\Ai\LlmClient` с методом `complete(string $systemPrompt, string $userMessage, array $options = []): LlmResponse`. Биндится в DI как singleton по конфигу `config('ai.provider')`.
   - **Реализации на старте (whitelist):**
     - `OpenAiLlmClient` — OpenAI API (`gpt-4o-mini` и аналоги).
     - `AnthropicLlmClient` — Anthropic Messages API (Claude).
     - `MiniMaxLlmClient` — MiniMax (контракт фиксируется при подключении).
     - `OpenAiCompatibleLlmClient` — для любого OpenAI-совместимого endpoint'а (локальные модели через Ollama/vLLM, прокси, региональные сервисы). Использует `AI_BASE_URL` из конфига.
     - `DummyLlmClient` — для dev/тестов: возвращает предсказуемый ответ, без HTTP.
   - `PromptResolver` — собирает финальный system-prompt: берёт активный Global + (если есть) активный Course-специфичный, склеивает по правилу.
   - `AiFeedbackService` — `generateFeedback(FeedbackInput $input, User $user): string`. Использует `PromptResolver` + шаблон промпта для фидбэка.
   - `AiTaskGeneratorService` — `generateExtraTask(ExtraTaskInput $input, User $user): GeneratedExtraTask` (readonly-DTO: текст задания + ожидаемый результат).
   - DTO на примитивах (`FeedbackInput`: taskText / expectedResult / submittedSolution / errorMessage + `?coursePrompt`; `ExtraTaskInput`: taskText / expectedResult + `?coursePrompt`); Этап 8 строит их из `PracticeTaskSubmission` (решение от 2026-09-14).
5. **Конфигурация (`config/ai.php`):**
   - `provider` → enum `AiProvider`, по умолчанию `Dummy`.
   - `api_key` → строка из `AI_API_KEY` (обязательна для всех, кроме `Dummy`).
   - `model` → строка из `AI_MODEL` (например, `gpt-4o-mini`).
   - `base_url` → строка из `AI_BASE_URL` (только для `OpenAiCompatible`; обязательна).
   - `token_limit_global_per_day` → int из `AI_TOKEN_LIMIT_GLOBAL_PER_DAY` (по умолчанию `100000`).
   - `token_limit_per_user_per_day` → int из `AI_TOKEN_LIMIT_PER_USER_PER_DAY` (по умолчанию `5000`).
   - `log_full_prompts` → bool из `AI_LOG_FULL_PROMPTS` (по умолчанию `false`) — полный текст промпта/ответа в логи, только для dev.
   - **Валидация на старте приложения** (отдельный `AiConfigValidator`, вызывается из `AppServiceProvider`): формат URL, блокировка внутренних адресов для `OpenAiCompatible` (`localhost`, `127.0.0.1`, `10.0.0.0/8`, `172.16.0.0/12`, `192.168.0.0/16`) — защита от SSRF.
6. **Лимиты токенов (правило №16 `AGENTS.md`):**
   - **Миграция `ai_token_usages`:** `id`, `user_id` (FK), `model` (string), `tokens` (int), `action` (enum `AiTokenUsageAction` { Feedback, ExtraTask }), `created_at`. Индексы по `user_id`, `created_at` (для агрегации за день).
   - **Миграция `user_llm_limits`:** `user_id` (FK, primary), `extra_tokens` (int, default 0) — ручное добавление админом поверх дефолтного лимита. При создании пользователя запись создаётся автоматически.
   - **`AiTokenUsageService`** — `recordUsage(User, int $tokens, AiTokenUsageAction $action, string $model): void` пишет в `ai_token_usages`. `usedToday(User): int` агрегирует за сегодня. `remainingForUserToday(User): int` = `default_limit + extra_tokens - usedToday`. `remainingGlobalToday(): int` = `global_limit - sum(used today)`.
   - **`AiLimitGuard`** — middleware-обёртка (или вызов в `AiFeedbackService`/`AiTaskGeneratorService`): проверяет `remainingForUserToday > 0` **и** `remainingGlobalToday > 0` перед каждым вызовом `LlmClient`. При исчерпании — `AiLimitExceededException` (HTTP-слой отразит как `429` — Этап 8; решение от 2026-09-14), без ретраев. **Fail loud**: лучше отказать в вызове, чем молчаливо превысить лимит.
   - **Интеграция в `AiFeedbackService`/`AiTaskGeneratorService`:** каждый вызов обёрнут в `AiLimitGuard::ensureQuotaAvailable()` → `LlmClient::complete()` → `AiTokenUsageService::recordUsage()` (по фактическим потраченным токенам, если провайдер отдаёт, иначе — оценка `strlen`).
   - **Unit-покрытие:** `AiLimitGuard` (лимит не исчерпан / глобальный исчерпан / пользовательский исчерпан / ручное добавление через `extra_tokens`), `AiTokenUsageService` (агрегация за день, корректная запись), `AiFeedbackService`/`AiTaskGeneratorService` (проверка лимита перед вызовом, запись после).
7. **Контроллеры админки (только для промптов и LLM-лимитов):**
   - `Admin\GlobalPromptController@edit`/`update` — редактирование **общего** системного промпта. Через `FormRequest` (`AdminUpdateGlobalPromptRequest` с валидацией `body` не пустой, `comment` опционально). `update` вызывает `PromptVersionService::createNewVersion('ai.global_system_prompt', $body, $comment, $user)`, обновляющий кэш в `settings` и пишущий audit-log. **Не пишет напрямую в `settings`** — только через сервис.
   - `Admin\CoursePromptController@edit`/`update` — редактирование **уточняющего** промпта курса (поле `courses.ai_course_prompt`). **Перенесено в Этап 6** вместе с миграцией `courses.ai_course_prompt`; в Этапе 4 сервис generic по `prompt_key` (решение от 2026-09-14). Через `FormRequest` (`AdminUpdateCoursePromptRequest` с валидацией `body` не пустой, `comment` опционально). `update` вызывает `PromptVersionService::createNewVersion('course.{id}.ai_course_prompt', ...)`. Audit-log: `action = PromptVersionCreated`, `meta = { prompt_key, version, comment }`.
   - `Admin\PromptHistoryController@index` — **просмотр истории версий**. URL: `/admin/prompts/history?key=ai.global_system_prompt` или `?key=course.{id}.ai_course_prompt`. Через `PromptVersionService::getHistory()`. UI: список версий (newest first), каждая с автором, датой, комментарием, кнопками «Сделать активной» (= `rollbackTo`) и «Посмотреть diff» (на старте — просто показать обе версии текстом рядом, plain-text, без fancy-инструмента).
   - `Admin\UserLlmLimitController@edit`/`update` (роуты `admin.users.llm-limit.edit` / `admin.users.llm-limit.update`, nested под `users/{user}/llm-limit`) — корректировка LLM-лимита пользователя. Через `FormRequest` (`AdminUpdateUserLlmLimitRequest` с валидацией `extra_tokens` int >= 0); `update` делегирует в Action `App\Actions\Admin\AdjustUserLlmLimit` (транзакция: upsert `user_llm_limits` + audit). Операция пишет запись в `admin_audit_logs` с `action = UserLlmLimitAdjusted` и `meta = { old_extra, new_extra }`.
   - **UI для выбора провайдера/ключа — не делаем.** Это инфраструктурная настройка, не бизнес-сущность.
8. **Политики:** `AiPromptVersionPolicy` (`viewAny`/`view`/`update` — только Admin; заменил stub `PromptPolicy` из Этапа 2), `UserPolicy@updateLlmLimit` — только Admin. `CoursePolicy@update` (включая редактирование `ai_course_prompt`) — появляется в Этапе 6 вместе с курсами (решение от 2026-09-14).
9. **Routes:** `admin.prompts.index`, `admin.prompts.global.update`, `admin.prompts.history.index`, `admin.prompts.history.rollback` (`PromptHistoryController`), `admin.users.llm-limit.edit`, `admin.users.llm-limit.update` — всё под middleware `['auth', 'role:admin']`.
10. **Тесты:**
   - **Unit:** `PromptResolver` (Global-only / Global+Course / Course-only / empty Global fallback / склейка через `\n\n`), `PromptVersionService` (создание первой версии / инкремент `version_number` / rollback через создание новой версии / обновление кэша в `settings` или `courses.ai_course_prompt` / запись в `admin_audit_logs` / работа в `DB::transaction` — откат при ошибке), `AiFeedbackService` (формирует запрос к LLM с правильным промптом, проверяет лимит, записывает usage), `AiTaskGeneratorService`, `AiLimitGuard` (все ветки: лимит не исчерпан / глобальный исчерпан / пользовательский исчерпан / ручное добавление через `extra_tokens`), `AiTokenUsageService` (агрегация за день), `DummyLlmClient`, каждый реальный клиент (мокается внешний HTTP), валидация конфига провайдера (формат URL, SSRF-блокировка), enum, `AiProvider`/`AiTokenUsageAction` (метки, сравнения), `AdjustUserLlmLimit` (корректировка лимита + запись audit-log), `Setting` (key/value, static-файндер `findByKey` для чтения `ai.global_system_prompt`).
   - **Feature (smoke):** админ редактирует Global-промпт → новая запись в `ai_prompt_versions` + обновлённый кэш в `settings`, видна на `/admin/prompts` и `/admin/prompts/history?key=ai.global_system_prompt`; админ редактирует уточняющий промпт курса (`course.{id}.ai_course_prompt` + обновлённое поле `courses.ai_course_prompt`) → Этап 6 (решение от 2026-09-14); админ делает rollback на старую версию → новая запись с `comment = 'Rollback to v{N}'`; `PromptResolver` дёргается из теста через DI и возвращает ожидаемую склейку; админ корректирует LLM-лимит пользователя → запись в `admin_audit_logs` с правильным `meta`.
11. **Логирование:** все обращения к LLM (провайдер, model, длина запроса/ответа, длительность, ошибки) — через `Log::info('ai.llm_call', [...])` для последующего анализа расходов. **Полный текст промпта/ответа в production-логи не пишем** (риск утечки + раздувание storage). В dev-окружении допустимо через флаг `config('ai.log_full_prompts', false)`.

**Что получаем на выходе:**
- Абстракция `LlmClient` + 4 whitelist-реализации + `DummyLlmClient` для dev/тестов.
- **Версионирование промптов:** таблица `ai_prompt_versions` (источник истины) + `PromptVersionService` (`createNewVersion`, `rollbackTo`, `getHistory`). UI истории в `/admin/prompts/history?key=...`. `settings` — кэш активной версии глобального промпта (кэш-цель course-ключей `courses.ai_course_prompt` — Этап 6).
- `PromptResolver` с предсказуемой логикой склейки.
- `AiFeedbackService` и `AiTaskGeneratorService` готовы к подключению из практики, с проверкой лимитов и записью usage.
- **Лимиты токенов:** глобальный + per-user, из конфига, с возможностью ручной корректировки админом (audit-log).
- Админка для редактирования промптов (с историей) и LLM-лимитов пользователей.
- Конфиг провайдера через `.env` с валидацией на старте.
- `AiPromptVersionPolicy` (авторизация промпт-операций) и Action `AdjustUserLlmLimit` (ручная корректировка per-user лимита, audit-log).
- `AiLimitExceededException` — доменное исключение при исчерпании лимита; HTTP-слой отразит его как `429` в Этапе 8 (решение от 2026-09-14).
- Все вызовы логируются (метаданные).

**Готовность:**
- Admin может редактировать Global-промпт и видеть изменения.
- **Версионирование:** каждое изменение создаёт новую запись в `ai_prompt_versions`; история доступна в `/admin/prompts/history`; rollback создаёт новую версию со старым текстом; кэш в `settings` всегда согласован с `MAX(version_number)` (кэш-цель course-ключей — Этап 6).
- `PromptResolver` корректно склеивает Global + Course.
- `DummyLlmClient` используется в тестах, ответы детерминированы.
- **Лимиты:** пользователь с исчерпанным лимитом получает `AiLimitExceededException` (без ретраев; HTTP-слой отразит как `429` — Этап 8; решение от 2026-09-14); админ через `/admin/users/{user}/llm-limit` может добавить `extra_tokens` (запись в `admin_audit_logs`).
- `php artisan test` зелёный, `pint` зелёный, `phpstan` зелёный.
- `config('ai.provider') = 'dummy'` по умолчанию — приложение стартует без реального ключа.

**Открытые вопросы этапа:**
- ~~Конкретный LLM-провайдер~~ — закрыто: whitelist (OpenAI, Anthropic, MiniMax, OpenAI-compatible) + выбор через `AI_PROVIDER` в `.env`. Single-tenant.
- ~~Лимиты на расход токенов/бюджет~~ — закрыто: глобальный + per-user лимит в `config/ai.php`, ручная корректировка админом через `/admin/users/{user}/llm-limit` (см. `concept.md §2.3`).
- ~~Хранение промптов: одна таблица с `scope` или две отдельные (`ai_prompts` + `ai_course_prompts`)~~ — закрыто: **общий в `settings` (key/value)**, **уточняющий как поле `courses.ai_course_prompt`**. Никаких таблиц `ai_prompts` / `ai_course_prompts`. Подробности в `concept.md §9.2.4` и в пункте 2 этого этапа.
- ~~Версионирование промптов (см. `concept.md §9.2.4`)~~ — закрыто: **вариант A, полная история в БД**, таблица `ai_prompt_versions` (источник истины), `settings` / `courses.ai_course_prompt` — кэш активной версии, активная = `MAX(version_number) WHERE prompt_key = ?`. Откат = создание новой версии со старым текстом. UI истории в `/admin/prompts/history?key=...`. Подробности в `concept.md §9.2.4` и в пункте 2 этого этапа.
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
   - `courses`: `id`, `slug` (unique), `title`, `description`, `preview_image_path` (string, nullable) — путь к файлу в `Storage::disk('public')`, `status` (enum), `ai_course_prompt` (longtext, nullable), `created_by`, timestamps. Сами файлы лежат в `storage/app/public/courses/previews/{uuid}.{ext}`. Поле `ai_course_prompt` — кэш-цель course-ключей промптов, перенесённая из Этапа 4 (решение от 2026-09-14): `PromptVersionService::createNewVersion('course.{id}.ai_course_prompt', ...)` обновляет его, создание/редактирование промпта курса = новая версия в `ai_prompt_versions`.
   - `levels`: `id`, `course_id` (FK), `level` (enum), `order` (int), `title` (nullable). Unique `(course_id, level)`.
   - `lessons`: `id`, `level_id` (FK), `slug` (unique), `title`, `order` (int), `material` (longtext), `is_published` (bool), timestamps.
3. **Модели:** `Course`, `Level`, `Lesson` — relations, scopes (`published`, `ordered`), accessors. `slug` через `Str::slug` в мутаторе/observer.
4. **Контроллеры пользователя:**
   - `CatalogController@index` — список опубликованных курсов.
   - `CourseController@show` — карточка курса с разворотом уровней/уроков (без прогресса).
5. **Контроллеры админки (Этап 2, заглушки → реальные):**
   - `Admin\CourseController` — CRUD курса.
   - `Admin\CoursePromptController@edit`/`update` — редактирование уточняющего промпта курса (`courses.ai_course_prompt`) через `PromptVersionService` (перенесено из Этапа 4 — решение от 2026-09-14). Audit-log: `action = PromptVersionCreated`, `meta = { prompt_key, version, comment }`.
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
- ~~Превью-картинки: загрузка через админку или только URL~~ — закрыто: **загрузка через админку** (поле `courses.preview_image` остаётся nullable, но в админке — file-upload через `multipart/form-data`). Хранение — **через Laravel Storage** на диске (`storage/app/public/courses/previews/{uuid}.{ext}`), `Storage::disk('public')`, `preview_image_path` строка в БД. **Не используем** внешние URL-ы (CDN, s3) на старте — переход на S3-подобное делается заменой диска в `config/filesystems.php`, без правок кода. UI: Vue-компонент с `<input type="file" accept="image/*">` + `FormData` + `POST` через Inertia. Валидация в FormRequest: `mimes:jpg,jpeg,png,webp`, `max:2048` (2 МБ), `dimensions:min_width=200,min_height=200`. На диске — `Intervention\Image` (или встроенный GD) для **ресайза до 1200×630** + WebP-конверсия (опционально, через отдельный job). Тримминг EXIF (защита от утечки гео-данных). В Этапе 6 реализовано: GD fit-ресайз до 1200×630 + ре-энкод (EXIF-стрип) синхронно в Action; WebP-конверсия и queue-job — отложены.
- Сортировка курсов в каталоге: по дате / вручную / по популярности?

---

## Граница плана

**Этап 7. Теоретические задания и прогресс** — на стыке с Этапом 6, идёт сразу после. **За границей** этого документа.

**Этап 8. Практические задания + ИИ-фидбэк + ИИ-генерация** — опирается на Этапы 4 (ИИ) и 5 (среда исполнения). **За границей** этого документа.

**Этап 9. Премиум-гейтинг практики и ИИ** — middleware `EnsurePremium` уже готов из Этапа 3, в Этапе 8 навешивается на маршруты. **За границей** этого документа.

Дальнейшие этапы (расширенная админка, аналитика, сертификация, импорт контента, антифрод для прод-нагрузок) оформляются отдельными документами по мере проработки.
