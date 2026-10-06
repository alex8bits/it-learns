---
name: remote-server
description: Access the it-learns project's remote server over SSH (`ssh sweb`, project at /srv/www/it-learns). Use whenever the user mentions the remote server, production, deployment, server logs or status, «на сервере», «прод», sweb, or asks to check/inspect anything that lives outside the local dev machine. Read-only by default — server-side changes only on the user's explicit request.
---

Work with the **it-learns** project's remote server. The server hosts the deployed copy of this same Laravel application.

## Connection facts (verified)

- SSH alias: `sweb` (configured in the user's SSH config — never invent host/user/port, always go through the alias).
- Login user is **root** (uid=0) — no sudo needed; be correspondingly careful.
- Project root on the server: `/srv/www/it-learns`.
- Local shell is Git Bash on Windows; plain `ssh`/`scp` work.
- Git works normally on the server (verified: `git log` runs without ownership errors; deployed HEAD can be read directly).

## How to run remote commands

Always use one-shot, non-interactive invocations — the agent cannot drive an interactive shell:

```bash
ssh -o ConnectTimeout=10 sweb "tail -n 100 /srv/www/it-learns/storage/logs/laravel.log"
```

- Quote the whole remote command; prefer absolute paths or explicit `cd … && …`.
- Bound the output (`head`, `tail -n 100`, `--oneline`) — never dump whole files or logs into the conversation.
- If a command hangs or asks for a password interactively, stop and report; do not retry blindly.

## Read-only by default — the core rule

Unless the user **explicitly** asked to change something on the server in this conversation («обнови на сервере», «перезапусти», «поправь конфиг», «залей», "restart", "deploy"), operate in inspect-only mode.

Allowed without asking: `ls`, `cat`, `head`/`tail`, `grep`, `stat`, `docker compose ps`/`logs`, `systemctl status`, `df -h`, `php artisan about`, reading files under `/srv/www/it-learns`, and other commands that only read state.

Not allowed without an explicit request — because they mutate the server or its services: any file write/edit/delete (`rm`, `mv`, `>`, `tee`, editing configs), `git pull`/`checkout`/`reset` and any `git config` write, `composer`/`npm install`, `php artisan migrate`/`cache:clear`/`config:clear`, container restarts, `systemctl restart`, `chown`/`chmod`, package installs.

If the task appears to require a write but the user did not explicitly authorize it, stop and report what you found plus what would need to change — do not "helpfully" proceed.

When a change IS explicitly requested:

1. Say which exact commands will run on the server before running them.
2. Prefer reversible steps; before restarting/deleting, check that the evidence actually supports that specific action.
3. After the change, verify the result with a read-only probe and report the outcome.

## Git on the server

Git commands run normally in `/srv/www/it-learns` (no dubious-ownership issue). To check what is deployed:

```bash
ssh sweb "cd /srv/www/it-learns && git log --oneline -1 && git status --short | head"
```

## Secrets

`/srv/www/it-learns/.env` holds `APP_KEY`, DB credentials and mail secrets. Read it only when the task requires it, and never copy secret values into the chat, files, or command examples — refer to them by key name.

## Ready-made read-only probes

```bash
# Laravel log tail
ssh sweb "tail -n 100 /srv/www/it-learns/storage/logs/laravel.log"

# Containers / services (use whichever the server actually runs)
ssh sweb "cd /srv/www/it-learns && docker compose ps"
ssh sweb "systemctl status nginx --no-pager"

# Runtime info and disk
ssh sweb "cd /srv/www/it-learns && php artisan about"
ssh sweb "df -h /srv"
```

If the server's actual layout differs from these assumptions (no docker compose, different log path), adapt by inspecting first — `ls`, `stat` — and keep the read-only rule while you learn the terrain.
