# Command: Design Feature (it-learns)

This is the **design step** of the feature workflow for the **it-learns** project
(Laravel 13 / PHP 8.3 — онлайн-платформа обучения IT-навыкам). The full
orchestration flow lives in `AGENTS.md` под **"Процесс реализации фичи"**.

Your job is to produce a **feature design / architecture decision** document
that bridges research and atomic implementation tasks. You **design and document
decisions** — you do not write production code and you do not create
implementation tasks here (that is the next step: `create-implementation-plan`).

---

## Цель

Зафиксировать выбранный подход, границы и компромиссы для фичи it-learns
**в виде документа**, на который потом опираются `create-implementation-plan`
и `worker`.

---

## Входные данные (собрать до старта)

1. **Цель / ожидаемое поведение** — что должна делать фича, как её видит пользователь (или админ).
2. **Research-документ** (если есть) — путь к `.mavis/research/YYYY-MM-DD-topic.md`. Если нет — запустить `research-codebase` сначала.
3. **Design / Concept / Plan** — ссылки на `docs/concept.md`, `docs/platform-plan.md`, предыдущие design-доки по смежным темам.
4. **Ограничения** — сроки, совместимость, нельзя ли breaking changes, требования к премиум-доступу, требования к изоляции среды (если касается практики), требования к ИИ (если касается промптов/вызовов LLM).
5. **Контекст домена** — какие сущности затрагиваются (User/Course/Level/Lesson/Subscription/Payment/AiPrompt/...).

Всегда также подтвердить:

- **Слой**: публичная часть (каталог/личный кабинет) или админка, или ИИ-каркас, или изоляция среды, или несколько сразу.
- **API-поверхность и версия**: у нас с первого дня версионирование (`/api/v1/...`, `/api/v2/...`). Указать явно, к какой версии относится фича. Если фича затрагивает и публичную, и админ-часть — описать обе.
- **Премиум-гейтинг**: требуется ли middleware `EnsurePremium` для части маршрутов этой фичи.
- **Изоляция среды**: затрагивает ли фича `PracticeEnvironmentManager` (см. `docs/platform-plan.md` Этап 5) — да/нет.
- **ИИ**: затрагивает ли фича `LlmClient` / `PromptResolver` (см. `docs/platform-plan.md` Этап 4) — да/нет.

---

## Hard rules

- **НЕ писать и НЕ менять production-код.** Только документ.
- **НЕ создавать файлы в `.mavis/tasks/`** — это шаг `create-implementation-plan`.
- **НЕ дублировать research.** Research фиксирует факты, design — решения и обоснование.
- **МОЖНО читать любые файлы репо**, читать упомянутые файлы **полностью** (без `limit`/`offset`).
- **Каждое нетривиальное утверждение** подкреплять ссылкой `path/to/file.php:line` (из research или из прочитанных файлов).
- Для решений по маршрутам/контроллерам **всегда указывать** API-поверхность и версию (`public` | `admin` × `v1` | `v2` | ...).
- **Следовать `AGENTS.md`:** enum'ы для статусов/ролей, FormRequest для валидации, Policy/Gate для авторизации, тонкие контроллеры, `DB::transaction` для мутаций в 2+ таблицы, eager loading.

---

## Process

### 1. Initial Response

Ответить:

```
Готов оформить дизайн фичи для it-learns. Укажите:
- цель / ожидаемое поведение;
- ограничения (сроки, совместимость, breaking changes);
- путь к research-документу в .mavis/research/ (если есть);
- какие сущности/слои затрагивает фича (пользовательская часть / админка / ИИ / изоляция среды / подписки);
- к какой API-поверхности и версии относится (public|admin × v1|v2).
```

### 2. Gather Context

После получения входа:

1. Если есть research — прочитать **полностью** (без `offset`/`limit`).
2. Прочитать упомянутые пользователем исходные файлы **полностью**.
3. Если research отсутствует или неполон — прочитать минимальный набор файлов, чтобы понять текущее поведение и точки расширения. Все ссылки `file:line`.
4. Свериться с `AGENTS.md` (15 правил), `docs/concept.md` (домен), `docs/platform-plan.md` (этап, к которому относится фича).
5. Если фича может затронуть `v1` и `v2` — явно зафиксировать: фича только для `v1`, или сразу для обеих, или `v2-only`.

### 3. Уточнить scope (если нужно)

**Один** короткий раунд уточняющих вопросов — только если запрос неоднозначен по:
- in-scope vs out-of-scope;
- backward compatibility (особенно вокруг `v1` → `v2`);
- user-visible acceptance criteria.

### 4. Произвести Design

1. **Options:** при наличии 2+ осмысленных альтернатив — таблица с pros/cons. Для тривиального расширения существующего паттерна — один выбранный подход с обоснованием «почему это вписывается в кодовую базу».
2. **Decision:** выбранный подход явно, в стиле mini-ADR. Явные non-goals.
3. **Boundaries:** какие слои/модули затрагиваются; что **не** меняется; публичные контракты (route, event, DB column, state machine, премиум-гейт).
4. **Data & control flow:** input → processing → output на нужном уровне абстракции (без pseudo-code дампа).
5. **Auth & middleware:** какой guard, какие middleware (`auth`, `role:admin`, `EnsurePremium` если премиум, `throttle` если критичный эндпоинт).
6. **Премиум и ИИ (если затрагивается):** какие маршруты под `EnsurePremium`; какие промпты дёргаются (`Global` / `Course`-specific, через `PromptResolver`). **Выбор LLM-провайдера** — single-tenant, через `.env` (`AI_PROVIDER` / `AI_API_KEY` / `AI_MODEL` / `AI_BASE_URL`) — **не** входит в scope фичи, это инфраструктурная конфигурация (см. `concept.md §9.2.5`). Если фича касается провайдера (например, добавляет новую реализацию `LlmClient`) — это отдельная фича с отдельным `affects_ai=yes`.
7. **Изоляция среды (если затрагивается):** какой драйвер `PracticeEnvironmentManager`, где таймаут/лимиты, как уничтожается среда.
8. **Risks & mitigations:** миграции (forward-only), dual-write, rollout, feature flags, идемпотентность webhook'ов, откат премиум-доступа, безопасность практической среды, **превышение LLM-лимитов** (fail loud, `HTTP 429`), **аудит admin-операций** (обязательная запись в `admin_audit_logs` через `AdminAuditLogger`).
9. **Verification (design-level):** что должно быть истинным, когда фича готова (типы тестов, ручные проверки) — **не** пошаговая реализация.
10. **Handoff к планированию:** bullet-список того, что `create-implementation-plan` должен превратить в таски (сгруппированные concerns, предлагаемый порядок — **без** создания task-файлов).

---

## Output Document Structure

Сохранить как: `.mavis/design/YYYY-MM-DD-<kebab-topic>.md`

Структура (frontmatter опционально, но рекомендуется):

```markdown
---
status: draft
related_research: .mavis/research/YYYY-MM-DD-topic.md  # если есть
api_surface: public|admin|ai|practice
api_version: v1  # current; или v2, если явно out of scope
affects_premium: yes|no
affects_ai: yes|no
affects_practice_env: yes|no
---

# Design: [Short topic title]

## Цель

[User-visible или integration outcome в 2–5 предложениях]

## Контекст из кодовой базы

- Ключевые факты с цитатами: `file.php:line` — что есть сегодня
- Паттерны, которые нужно переиспользовать или следовать
- Касается ли фича v1/v2, премиум-гейта, ИИ, изоляции среды

## Рассмотренные варианты

| Option | Pros | Cons |
|--------|------|------|
| A | … | … |
| B | … | … |

*(Опустить таблицу, если есть только один разумный подход; тогда короткий параграф «Почему этот подход».)*

## Решение

[Выбранный подход, 1–3 параграфа. Явные non-goals.]

## Архитектура

- **Компоненты / слои:** …
- **Data flow:** …
- **Контракты:** route / event / schema / state-machine — …
- **Auth & middleware:** какой guard, какой middleware (`auth`, `role:admin`, `EnsurePremium`, `throttle:...`)

## Премиум, ИИ, изоляция среды (если применимо)

- **Премиум:** какие маршруты/действия под `EnsurePremium`, как проверяется активная подписка
- **ИИ:** какие промпты (Global / Course-specific) дёргаются, как собирается финальный system-prompt, **лимиты токенов (глобальный + per-user, ручная корректировка админом)** и логирование. **Выбор провайдера** — out of scope (через `.env`, single-tenant), если только фича не добавляет новую реализацию `LlmClient`
- **Изоляция среды:** какой драйвер `PracticeEnvironmentManager`, таймаут, лимиты, гарантия уничтожения
- **Audit-лог:** если фича меняет админ-операции — какие `AdminAuditAction` добавляются, что пишется в `meta`

## Риски и миттгации

- …

## Тестирование и валидация (design-level)

- **Unit — максимальное покрытие** (см. `AGENTS.md` правило №15): все Action/Service, скоупы, политики, enum'ы, форматирование, бизнес-правила, граничные случаи
- **Feature — только smoke** (см. `AGENTS.md` правило №15): happy-path + ключевые HTTP-ошибки для каждого затронутого эндпоинта
- Что должно быть истинным, когда фича готова (ассерты без пошаговой реализации)

## Handoff notes для implementation plan

- [Concern 1 — например: enum + миграция + cast]
- [Concern 2 — например: Action/Service + FormRequest + Controller]
- [Concern 3 — например: тесты + middleware + feature flag]
- [Concern 4 — например: админка / публичная часть / ИИ-интеграция / изоляция среды]
```

### 5. Final Message to User

После сохранения файла вывести:

- Полный путь до сохранённого design-документа.
- Один параграф с саммари решения.
- Напоминание: следующий шаг — `create-implementation-plan` с этим design + research.

---

## Quality Rules

1. **Citations:** неочевидные утверждения о текущем коде — с `file:line`.
2. **No task files:** не создавать `.mavis/tasks/` здесь, только design-markdown.
3. **No production edits:** никаких правок в `app/`, `routes/`, `database/`, `resources/`, `config/`.
4. **Separation from research:** не переписывать research-нарратив, ссылаться на него и добавлять **решения** поверх.
5. **Kebab-case filename** для `<kebab-topic>`, выровненный с будущим task-folder slug, если он уже известен.
6. **API surface + version** — non-negotiable в frontmatter и в решении.
7. **`affects_premium` / `affects_ai` / `affects_practice_env`** — non-negotiable в frontmatter, если фича хоть как-то касается этих подсистем.
8. **Согласованность с `AGENTS.md`:** если design нарушает какое-либо из 15 правил — это либо non-goal с обоснованием, либо ошибка design'а.

---

## Good vs Bad

BAD: «Надо отрефакторить сервисный слой» (расплывчато, нет решения, нет цитат).

GOOD: «Добавить `purpose` в платёж. Новая миграция `add_purpose_to_payments` (по аналогии с существующей миграцией подписок). Запись через `PaymentService::markPurpose()` (`app/Services/Payments/...`). Изменение контроллера только для `v1` (`app/Http/Controllers/Api/v1/PaymentController.php`); `v2` пока не трогаем. Админ-эндпоинт — под `middleware(['auth', 'role:admin'])`. Unit-тесты покрывают все ветки `PaymentService::markPurpose()` + enum + валидацию FormRequest. Feature-smoke: POST /api/v1/payments/{id}/purpose → 200; не-админ → 403.»

BAD: 200 строк pseudo-реализации в design-доке.

GOOD: Чёткие границы, описание потока на уровне диаграммы, handoff-буллеты для планировщика.
