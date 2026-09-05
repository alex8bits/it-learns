# Command: Implement Feature (it-learns)

This is the **entry point** for executing a planned feature in the **it-learns**
project (Laravel 13 / PHP 8.3 — онлайн-платформа обучения IT-навыкам). The
full orchestration flow (research → design → plan → parallel implementation
waves with code-review) lives in `AGENTS.md` под **"Процесс реализации фичи"**.

This file is intentionally short — it tells the agent what materials to collect
and then defers to `AGENTS.md`.

---

## Required inputs (collect in this order)

1. **Цель реализации** — one paragraph: what user-visible behavior or integration outcome is required.
2. **Research** — path to `.mavis/research/<date>-<topic>.md` (or a short summary if not written yet).
3. **Design** — path to `.mavis/design/<date>-<topic>.md` (or a short description of the chosen approach).
4. **Tasks** — path to `.mavis/tasks/<date>-<topic>/` folder (or list of tasks).

Always also confirm:

- **API surface** — `public` | `admin` | `ai` | `practice` (one or many).
- **API version** — `v1` (current) | `v2` (if explicitly out of scope).
- **Премиум-гейтинг** — нужен ли middleware `EnsurePremium` на части маршрутов фичи.
- **ИИ** — затрагивает ли фича `LlmClient` / `PromptResolver` (какие промпты дёргаются).
- **Изоляция среды** — затрагивает ли фича `PracticeEnvironmentManager` (какой драйвер, какие таймауты/лимиты).
- **Покрытие тестами** — напомнить, что по `AGENTS.md` правилу №15: **Unit — максимальное покрытие, Feature — только smoke**.

---

## What to do

After all inputs are collected:

1. **Прочитать каждый упомянутый файл полностью** (без `limit`/`offset`).
2. **Открыть `AGENTS.md` → "Процесс реализации фичи"** и следовать wave-флоу оттуда.
3. Передать реализацию в `worker` subagent (определён в `AGENTS.md`) по одному таску за раз.
4. Прогнать `code-reviewer` (определён в `AGENTS.md`) после **каждого** `worker`, включая параллельные волны.
5. Цикл фиксов — пока каждый таск не достигнет `PASS`, ровно как описывает процесс в `AGENTS.md`.
6. Прогнать самые узкие релевантные тесты через `php artisan test --filter=...` (без Docker, см. `AGENTS.md`).

---

## Hard rules

- **Один таск = один worker-prompt.** Никогда не батчить несколько тасков в один worker.
- Параллельные волны требуют **явно объявленной независимости** в design/tasks-доке **и** непересекающихся file scopes. При сомнениях — последовательно.
- `code-reviewer` запускается после **каждого** worker, включая параллельные волны.
- Если одна и та же блокирующая проблема возникает дважды для любого таска — остановить оркестрацию и сообщить пользователю.
- **Соблюдать конвенции it-learns** (из `AGENTS.md`):
  - контроллеры тонкие, делегируют в `app/Actions/...` или `app/Services/...`;
  - валидация — только в `app/Http/Requests/...` (FormRequest);
  - ответы — через `app/Http/Resources/...`;
  - роли/статусы — через `app/Enums/...`;
  - авторизация — только через `Gate`/`Policy`;
  - мутации в 2+ таблицы — в `DB::transaction`;
  - премиум-маршруты — под `EnsurePremium`;
  - ИИ-вызовы — через `LlmClient`, промпты — через `PromptResolver`;
  - практика — через `PracticeEnvironmentManager`, среда уничтожается в `finally`;
  - миграции только forward, без правок уже применённых.

---

## Final response format (Russian)

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

Omit the "Параллельные волны" section if the run was fully sequential.
