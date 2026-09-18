# it-learns

Онлайн-платформа для обучения IT-навыкам. Laravel 13 + Vue (Inertia.js) +
PHP 8.3. Пользователь проходит курсы (уровни → уроки → теория + практика),
практика исполняется в изолированной среде, премиум-подписчикам доступен
ИИ-помощник.

## Документация

- [`AGENTS.md`](./AGENTS.md) — 20 правил проекта (SOLID, FormRequest,
  Policy, Spatie RBAC, Vue/Inertia, audit-лог и др.). **Источник истины**
  для архитектурных решений.
- [`docs/concept.md`](./docs/concept.md) — что строим: роли, премиум,
  ИИ, изоляция среды, иерархия курс→уровень→урок.
- [`docs/platform-plan.md`](./docs/platform-plan.md) — порядок реализации
  (этапы 0–6).
- [`docs/commands-playbook.md`](./docs/commands-playbook.md) — операционный
  playbook Mavis-команд (`research-codebase`, `design-feature`,
  `create-implementation-plan`, `implement-feature`, `write-documentation`).

## Quick start

```sh
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate
php artisan storage:link
npm install
npm run build
php artisan serve --port=28080
```

После этого приложение доступно на `http://localhost:28080`
(в Docker-окружении nginx слушает тот же порт — см. `docker-compose.yml`).

`php artisan storage:link` создаёт symlink `public/storage` → `storage/app/public` —
без него превью-картинки курсов не отдаются.

## Команды качества

> **Для `composer test` нужен Docker.** Контейнер `db-testing` (MariaDB) поднимается
> автоматически. Без Docker — `composer test` упадёт. См. [`AGENTS.md §3.1`](./AGENTS.md).

```sh
# Все проверки одной командой: docker up db-testing + pint + phpstan + tests (fail-fast)
composer test

# Только тесты (требует поднятый db-testing)
php artisan test

# С покрытием (требует Xdebug или PCOV)
php artisan test --coverage-text
```

## Стек

- **Backend:** Laravel 13, PHP 8.3.
- **Тесты:** PHPUnit 12.
- **Качество кода:** Pint, PHPStan (Larastan) level 7.
- **Авторизация:** spatie/laravel-permission, Laravel Fortify.
- **Frontend:** Vue 3 + Inertia.js (Этап 1+).

## Лицензия

MIT.
