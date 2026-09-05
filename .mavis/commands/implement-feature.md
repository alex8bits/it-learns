# Command: Implement Feature (it-learns)

This is the **entry point** for executing a planned feature in the **it-learns**
project (Laravel 13 / PHP 8.3 — онлайн-платформа обучения IT-навыкам). The
full orchestration flow (research → design → plan → parallel implementation
waves with code-review) lives in `AGENTS.md` под **"Процесс реализации фичи"**.

This file is intentionally short — it tells the agent what materials to collect
and then defers to `AGENTS.md`.

---

## ⚠️ Hard rule: orchestrator НЕ делает worker-работу сам

**Orchestrator (этот root-сеанс) только:**
- собирает входы (research/design/tasks),
- составляет план волн,
- **делегирует каждый таск в `worker`** через `task({ agent_name: "worker", ... })`,
- **делегирует ревью каждого таска в `code-reviewer`** через `task({ agent_name: "code-reviewer", ... })`,
- **принимает отчёт `code-reviewer` как gate** — если verdict != PASS → новый worker-цикл (фикс),
- интегрирует результат.

**Orchestrator НЕ:**
- не пишет код в `app/`, `database/`, `tests/`, `config/`, `routes/` сам (кроме `composer.json`, `phpunit.xml`, `pint.json`, `phpstan.neon`, `.env.example`, `README.md` — это **настройка пайплайна**, не бизнес-код; и даже их лучше делегировать в worker, если есть соответствующий таск),
- не запускает `php artisan test` / `vendor/bin/pint` / `vendor/bin/phpstan` сам, если их запуск — часть Definition of Done таска; это делает worker, а orchestrator только валидирует отчёт,
- не пропускает `code-reviewer` шаг, даже если «всё зелёное»,
- не батчит несколько тасков в один worker (см. Hard rules ниже),
- не делает ретроспективный code-review «за себя» — если процесс был нарушен, **честно говорит об этом пользователю** и предлагает ретроспективный прогон `code-reviewer` на diff (но это исключение, не правило).

**Исключение из правила (явно перечислить в финальном ответе):** оркестратор может сделать настройку пайплайна (composer scripts, phpunit.xml, pint.json, phpstan.neon, .env.example, README.md) сам **только если** соответствующий таск явно говорит «это tooling-таск, может быть выполнен orchestrator-ом без worker-delegation». Сейчас таких тасков нет — все таски Этапа 0 (включая tooling-таски 5, 6, 7) **должны** идти через worker.

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

## What to do (обязательная последовательность)

После того, как все входы собраны, для **каждого таска** в плане идти строго
по этому циклу. **Не пропускать шаги.** **Не менять порядок.**

```
┌────────────────────────────────────────────────────────────────────────┐
│                        ОДИН ТАСК = ОДИН ЦИКЛ                           │
│                                                                        │
│  1. worker  ──► diff (файлы + тесты)                                  │
│       │                                                                  │
│       ▼                                                                  │
│  2. code-reviewer  ──► verdict: PASS | NEEDS FIX                      │
│       │                                                                  │
│       ├── PASS  ──► готово, переходим к следующему таску              │
│       │                                                                  │
│       └── NEEDS FIX ──► новый worker-цикл с фиксом → code-reviewer    │
│                          (до 2 итераций; 3-я с тем же блокером →       │
│                           стоп, эскалация пользователю)                │
└────────────────────────────────────────────────────────────────────────┘
```

1. **Прочитать каждый упомянутый файл полностью** (без `limit`/`offset`).
2. **Открыть `AGENTS.md` → "Процесс реализации фичи"** и следовать wave-флоу оттуда.
3. **Составить wave-план**: какие таски параллельны (file-scope не пересекается), какие последовательны. Зафиксировать план.
4. **Для каждой волны:**
   - **Параллельно** (если file-scope независим): запустить `worker` для каждого таска **одновременно** через `task({ ..., run_in_background: true })`.
   - **Последовательно** (если есть зависимости): один за другим.
5. **После завершения каждого `worker` (или волны параллельных worker'ов):** запустить `code-reviewer` на их diff.
6. **Gate:** если verdict `code-reviewer` != PASS — новый worker-цикл (фикс). Повторять до PASS или 2 итераций (3-я с тем же блокером → стоп, эскалация).
7. **Только после PASS всех тасков в волне** — переходить к следующей волне.
8. **В конце Этапа/фичи:** прогнать `composer test` (или эквивалент) **сам** как финальный smoke. Если упало — НЕ баг, эскалировать пользователю: «worker/code-reviewer что-то пропустили».
9. Цикл фиксов — пока каждый таск не достигнет `PASS`, ровно как описывает процесс в `AGENTS.md`.
10. Прогнать самые узкие релевантные тесты через `php artisan test --filter=...` (требует поднятый `db-testing`, см. `AGENTS.md §3.1`).

---

## Hard rules

- **Один таск = один worker-prompt.** Никогда не батчить несколько тасков в один worker.
- Параллельные волны требуют **явно объявленной независимости** в design/tasks-доке **и** непересекающихся file scopes. При сомнениях — последовательно.
- `code-reviewer` запускается после **каждого** worker, включая параллельные волны. Это **gate**: без `verdict: PASS` таск не считается сданным.
- **Перед стартом следующего таска** orchestrator ОБЯЗАН иметь `verdict: PASS` от `code-reviewer` на предыдущем таске. Без PASS — стоп.
- Если одна и та же блокирующая проблема возникает **дважды** для любого таска (3-я итерация worker → code-reviewer с тем же блокером) — остановить оркестрацию и сообщить пользователю. Не угадывать, не «поправить своими руками».
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
