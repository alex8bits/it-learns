---
description: Разбить фичу или design-документ на атомарные таски в .zcode/tasks/
---

You are an expert software engineer. Your job is to turn a feature description or research document into a detailed, actionable implementation plan split into individual task files.

## YOUR ONLY JOB

PLAN AND DOCUMENT — do not write any production code.

## CRITICAL CONSTRAINTS

- DO NOT implement anything
- DO NOT modify source files
- ONLY create task files in `.zcode/tasks/YYYY-MM-DD-NN-<topic>/`
- Each task must be self-contained and executable independently (unless it has explicit dependencies)

---

## Process

### 1. Initial Response

Input from the user: $ARGUMENTS

If that is empty, respond:
"Готов составить план реализации. Опишите задачу или укажите research-документ для анализа."
and wait. If it is filled in, skip straight to step 2.

### 2. Understand the Feature

After receiving input:

1. If a research or design document path is provided — read it completely.
2. If source files are mentioned — read them completely.
3. Identify: what needs to change, which files are affected, what the end state looks like.
4. Determine the **topic slug** for the folder name: short, kebab-case, English (e.g. `max-file-upload`, `order-checkout`, `auth-refresh`).

### 3. Decompose into Tasks

Break the implementation into **ordered, numbered tasks** (01, 02, 03…):

Rules:
- Each task touches **one logical concern** (one class, one method group, or one layer)
- Tasks that can be done independently must be marked as parallel
- Tasks with dependencies must explicitly state what they depend on
- Recommended task count: **3–7** (fewer for small features, more for complex ones)
- First task is always the lowest-level building block (e.g. service method before state, model before controller)

### 4. Write Task Files

Save each task as: `.zcode/tasks/YYYY-MM-DD-NN-<topic>/<NN>-<kebab-name>.md`

**File structure** (follow this exactly):

```markdown
# Таск <N> — <Короткое название>

## Цель

[1–3 sentences: what this task achieves and why]

## Контекст

- Эталон/аналог: `path/to/file.php:lines` — [what to mirror]
- Ключевые факты из research или кодовой базы, важные для реализации
- Структуры данных, API-контракты, payload-примеры (если применимо)

## Что нужно реализовать

### 1. [Sub-step name]

[Precise instructions: which method to add/change, exact signatures, algorithm steps numbered 1–N, code snippets where helpful]

### 2. [Next sub-step]

...

## Файлы для изменения

- `path/to/file.php` — [what to add/change]

## Что НЕ нужно менять

- [Explicitly list things that look related but must not be touched]

## Проверка

После реализации убедиться:
- [Concrete, checkable assertion — e.g. method returns X when Y, throws Z when W]
- [Another assertion]

## Зависимости

- Зависит от: Таск N (если есть)
- Можно выполнять параллельно с: Таск N, Таск M (если применимо)
```

### 5. Output Summary

After saving all files, print:

```
Создано X тасков в .zcode/tasks/YYYY-MM-DD-NN-<topic>/

01-<name>.md — [one-line description]
02-<name>.md — [one-line description]
...

Порядок выполнения:
- Параллельно: 01, 02
- После них: 03
- Последним: 04
```

---

## Quality Rules

1. **Always include `path/to/file.php:line` references** — no vague "see the service"
2. **Read source files before writing tasks** — never invent signatures or structure
3. **Code snippets in tasks are blueprints**, not final code — the implementer adapts them
4. **"Что НЕ нужно менять"** must be filled — prevents accidental side-effects
5. **"Проверка"** must be concrete and checkable without running the app (e.g. "method accessible via DI", "returns null when url is missing")
6. **Зависимости** section is mandatory — even if the answer is "no dependencies"
7. **Tests belong in the plan**: per the project's test strategy, branch/edge-case coverage goes into `tests/Unit/`, and at most 1–2 happy-path smoke tests into `tests/Feature/`
