---
name: remote-server
description: Access the seizure project's remote server over SSH (`ssh sweb`, project at /srv/www/seizure). Use whenever the user mentions the remote server, production, deployment, server logs or status, «на сервере», «прод», sweb, or asks to check/inspect anything that lives outside the local dev machine. Read-only by default — server-side changes only on the user's explicit request.
---

Work with the **seizure** project's remote server. The server hosts the deployed copy of this same Laravel application.

## Connection facts (verified)

- SSH alias: `sweb` (configured in the user's SSH config — never invent host/user/port, always go through the alias).
- Login user is **root** (uid=0) — no sudo needed; be correspondingly careful.
- Project root on the server: `/srv/www/seizure`.
- Local shell is Git Bash on Windows; plain `ssh`/`scp` work.
- Remote git is old (Ubuntu 20.04 backport of 2.25.1 with the CVE-2022-24765 ownership check) — see "Git on the server" below.

## How to run remote commands

Always use one-shot, non-interactive invocations — the agent cannot drive an interactive shell:

```bash
ssh -o ConnectTimeout=10 sweb "tail -n 100 /srv/www/seizure/storage/logs/laravel.log"
```

- Quote the whole remote command; prefer absolute paths or explicit `cd … && …`.
- Bound the output (`head`, `tail -n 100`, `--oneline`) — never dump whole files or logs into the conversation.
- If a command hangs or asks for a password interactively, stop and report; do not retry blindly.

## Read-only by default — the core rule

Unless the user **explicitly** asked to change something on the server in this conversation («обнови на сервере», «перезапусти», «поправь конфиг», «залей», "restart", "deploy"), operate in inspect-only mode.

Allowed without asking: `ls`, `cat`, `head`/`tail`, `grep`, `stat`, `docker compose ps`/`logs`, `systemctl status`, `df -h`, `php artisan about`, reading files under `/srv/www/seizure`, and other commands that only read state.

Not allowed without an explicit request — because they mutate the server or its services: any file write/edit/delete (`rm`, `mv`, `>`, `tee`, editing configs), `git pull`/`checkout`/`reset` and any `git config` write, `composer`/`npm install`, `php artisan migrate`/`cache:clear`/`config:clear`, container restarts, `systemctl restart`, `chown`/`chmod`, package installs.

If the task appears to require a write but the user did not explicitly authorize it, stop and report what you found plus what would need to change — do not "helpfully" proceed.

When a change IS explicitly requested:

1. Say which exact commands will run on the server before running them.
2. Prefer reversible steps; before restarting/deleting, check that the evidence actually supports that specific action.
3. After the change, verify the result with a read-only probe and report the outcome.

## Git on the server — known limitation

`git` commands in `/srv/www/seizure` fail with "detected dubious ownership": the worktree is owned by `www-data` while `.git` is owned by `root`, and this git build reads `safe.directory` **only** from global/system config — the usual zero-write workarounds (`-c safe.directory=…`, `GIT_CONFIG_*` env, running as `www-data`) were tested and do NOT work.

Until the user approves the fix, get version info without git:

```bash
ssh sweb "cat /srv/www/seizure/.git/HEAD && cat /srv/www/seizure/.git/refs/heads/master"
ssh sweb "grep -A1 'branch \"master\"' /srv/www/seizure/.git/config"
```

The approved fix (run only when the user explicitly asks) is one line in root's global config:
`ssh sweb "git config --global --add safe.directory /srv/www/seizure"`.
A cleaner but bigger change is unifying ownership (`chown -R www-data: /srv/www/seizure/.git`) — needs separate approval.

## Secrets

`/srv/www/seizure/.env` holds `APP_KEY`, DB credentials and mail secrets. Read it only when the task requires it, and never copy secret values into the chat, files, or command examples — refer to them by key name.

## Ready-made read-only probes

```bash
# Laravel log tail
ssh sweb "tail -n 100 /srv/www/seizure/storage/logs/laravel.log"

# Containers / services (use whichever the server actually runs)
ssh sweb "cd /srv/www/seizure && docker compose ps"
ssh sweb "systemctl status nginx --no-pager"

# Runtime info and disk
ssh sweb "cd /srv/www/seizure && php artisan about"
ssh sweb "df -h /srv"
```

If the server's actual layout differs from these assumptions (no docker compose, different log path), adapt by inspecting first — `ls`, `stat` — and keep the read-only rule while you learn the terrain.
