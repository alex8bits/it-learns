# Command: Research Codebase (it-learns)

This is the **research step** of the feature workflow for the **it-learns** project
(Laravel 13 / PHP 8.3 — онлайн-платформа обучения IT-навыкам). The full
orchestration flow lives in `AGENTS.md` под **"Процесс реализации фичи"**.

You are an expert software engineer conducting comprehensive codebase research
for **it-learns**. Your only job is to **document and explain the codebase as it
exists today** — no suggestions, no critique, no proposals.

---

## Цель

Дать объективную, цитатно-привязанную картину того, как сейчас устроена
конкретная область кода. Используется как вход для `design-feature`, чтобы тот
не додумывал и не изобретал сигнатуры.

---

## Hard rules

- **DO NOT suggest improvements.**
- **DO NOT critique implementation.**
- **DO NOT propose changes.**
- **ONLY describe what EXISTS.**

---

## Process

### 1. Initial Response

Ответить:

```
Готов исследовать кодовую базу it-learns. Опишите вопрос или область, которую нужно разобрать. Если есть — укажите путь к существующему research-документу, который нужно дополнить.
```

### 2. Decompose the Research Question

После получения вопроса:

1. Прочитать **полностью** (без `limit`/`offset`) любые упомянутые напрямую файлы.
2. Разложить вопрос на 2–4 независимых области исследования.
3. Завести task-list через `TaskCreate` для отслеживания прогресса.

### 3. Spawn Parallel Research Tasks

Использовать `codebase-researcher` subagent (`Task` tool, `subagent_type: "codebase-researcher"`).

#### Routing rules:

- **2–4 параллельных таска** для независимых областей (больше 4 — риск overflow контекста).
- **Sequential** — если одна область зависит от результатов другой.
- **Background** — для широких поисков, которые не блокируют остальную работу.

Каждый task-prompt **обязательно** содержит:

- Конкретный вопрос, на который надо ответить.
- Стартовые файлы/пути, если известны.
- Требуемый формат вывода.
- Явные границы scope (что НЕ исследовать).
- **Контекст it-learns:**
  - API-поверхность: `public` | `admin` | `ai` | `practice` (одна или несколько).
  - Версия API: `v1` (current), `v2` (если явно out of scope).
  - Затрагивает ли премиум (`EnsurePremium`), ИИ (`LlmClient` / `PromptResolver`), изоляцию среды (`PracticeEnvironmentManager`).

#### Example:

```
Spawning 3 parallel research tasks:
1. "Map auth flow: register/login/forgot-password routes, FormRequest, Action, throttle middleware" → codebase-researcher
2. "Document Subscription + Payment models: relations, scopes, status enums, PaymentGateway interface and Dummy implementation" → codebase-researcher
3. "Trace AiPrompt: table schema, PromptResolver logic (Global+Course), admin controllers and policies" → codebase-researcher
```

### 4. Synthesize Findings

После завершения всех тасков:

1. Слить результаты, разрешить противоречия.
2. Построить цельную картину с перекрёстными ссылками.
3. Найти пробелы — при необходимости запустить follow-up таски (макс 1 раунд).

### 5. Generate Research Document

Структура:

```markdown
---
date: YYYY-MM-DD
topic: <kebab-topic>
api_surface: public|admin|ai|practice
api_version: v1
affects_premium: yes|no
affects_ai: yes|no
affects_practice_env: yes|no
---

# Research: [Topic]

## Summary

[2–3 paragraph executive summary: что исследуется, что нашлось, какие ключевые точки расширения]

## Detail Findings

### 1. [Component/Area Name]
- **Location**: `path/to/file.php:line-numbers`
- **Description**: What it does
- **Dependencies**: What it uses
- **Data flow**: Input → Processing → Output

### 2. [Next Component]
...

## Code References

- `file.php:42` — description
- `file.php:86` — description

## Architecture Insights

- **Pattern used:** [имя паттерна]
- **Data flow:** a → b → c
- **Key dependencies:** ...
- **Domain notes:** [что в домене it-learns важно для этой области — роли, премиум, ИИ, изоляция среды, прогресс курса, ...]
- **Versioning notes:** [v1 / v2 статус затронутой области]
```

### 6. Critical Rules

1. **Всегда `file:line` ссылки** — никаких расплывчатых описаний.
2. **Читать файлы полностью** — без `limit`/`offset`.
3. **Использовать `codebase-researcher` subagent** для параллельных исследований.
4. **Макс 4 параллельных таска** — больше = overflow.
5. **Объективность** — только факты, без мнений.
6. **Сохранять точные пути** — как они есть в репо.
7. **Всегда отмечать API-поверхность + версию** при исследовании route/controller/middleware (`public|admin|ai|practice × v1|v2`).
8. **Премиум / ИИ / изоляция среды** — отмечать явно, если затрагивается.

---

## Output

Сохранять в: `.mavis/research/YYYY-MM-DD-topic-name.md`.

Все Mavis-артефакты лежат под `.mavis/`, чтобы не смешиваться с
`.cursor/` (который принадлежит чужому ИИ-инструменту — см. `user.md`).
Папка `.mavis/` создаётся автоматически на `init` или при первом запуске
любой команды.

---

## Good vs Bad Research

BAD: «Auth реализован плохо».

GOOD: «Auth через `auth` guard (`config/auth.php:42`). Регистрация идёт через `RegisterAction` (`app/Actions/Auth/RegisterAction.php:18`), валидация — в `RegisterRequest` (`app/Http/Requests/Auth/RegisterRequest.php:12`). На маршруте `/login` — middleware `throttle:5,1` (`routes/auth.php:24`). Восстановление пароля — стандартный Laravel-флоу через `Password::sendResetLink` (`app/Actions/Auth/SendPasswordResetLinkAction.php`).»

BAD: «Код надо переписать на queued jobs».

---

## Context cheatsheet (it-learns)

Использовать как стартовую карту при исследовании. Не источник истины —
всё равно подтверждать `file:line`.

- **Routing:** один публичный `routes/web.php` (каталог/личный кабинет), отдельные `routes/admin.php` для админки, `routes/api.php` для API (с версионированием `v1/`, `v2/` с первого дня — см. `AGENTS.md` правило №10).
- **Структура слоёв** (плановая, по `AGENTS.md` и `docs/platform-plan.md`):
  - `app/Actions/...` — бизнес-логика, вызывается из контроллеров.
  - `app/Services/...` — внешние интеграции (PaymentGateway, LlmClient, PracticeEnvironmentManager).
  - `app/Http/Requests/...` — FormRequest'ы, **вся валидация только здесь** (правило №2).
  - `app/Http/Resources/...` — API-ответы, **никаких `Model::toArray()`** (правило №8).
  - `app/Enums/...` — роли, статусы, типы, уровни (правило №5).
  - `app/Policies/...` — авторизация, **никаких проверок ролей вручную** (правило №7).
  - `app/Models/...` — данные, скоупы, отношения, **никакой бизнес-логики** (правило №4).
- **Авторизация:** `auth` guard (web), `auth:api` для API. Роли — `UserRole` enum (`User` | `Admin`). Проверка — только через `Gate`/`Policy`. Middleware `role:admin` для админ-маршрутов, `EnsurePremium` для премиум-маршрутов.
- **ИИ:** `LlmClient` интерфейс (реализация `DummyLlmClient` для dev/тестов), `PromptResolver` собирает Global + Course-специфичный промпт, `AiFeedbackService` / `AiTaskGeneratorService` — сервисные методы.
- **Изоляция среды:** `PracticeEnvironmentManager` интерфейс, реализация `LocalSqlitePracticeEnvironment` для dev/тестов.
- **Тесты:** PHPUnit (или Pest — выбор за пользователем), `tests/Unit/...` — максимальное покрытие, `tests/Feature/...` — только smoke (правило №15). Запуск: `php artisan test`. Без Docker.

---

## Final message

После сохранения research-документа вывести:

- Полный путь до сохранённого файла.
- Краткое summary (2–3 предложения) ключевых находок.
- Список областей, которые не покрыты и могут потребовать follow-up.
- Напоминание: следующий шаг — `design-feature` (или сразу `create-implementation-plan`, если дизайн уже есть).
