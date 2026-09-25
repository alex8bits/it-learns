# Развёртывание it-learns

> Статус: черновик. Документ фиксирует требования к production-среде для
> полного функционала практики. Конкретная процедура деплоя
> (compose / k8s / native / Forge) зависит от выбранного способа и
> документируется отдельно.

## Архитектура практики

Студенты выполняют SQL-задания в **изолированных disposable контейнерах**
Stage 10 (`app/Services/Practice/Docker/DockerPracticeEnvironment.php`).
Каждая попытка — отдельный контейнер `mysql:8` (или `postgres:16`)
с сетью `none`, ограничениями по памяти/CPU/PIDs (см.
`config/practice.php`), который удаляется в `finally` Action-класса.

Альтернативный драйвер `LocalSqlitePracticeEnvironment` (`local-sqlite`)
существует, но **не подходит для курса MySQL**: все 31 задание имеют
`runtime: mysql` (`docs/mysql-lesson-rule.md` §3), и SQLite-движок
отказывается их исполнять (`app/Services/Practice/LocalSqlitePracticeEnvironment.php:80`).
Включение `PRACTICE_DRIVER=docker` в `.env` обязательно для
функционирования практики.

## Системные требования

| Требование | Минимум | Рекомендация | Зачем |
|---|---|---|---|
| ОС | Linux с ядром ≥ 4.x (Ubuntu 20.04+, Debian 11+) | Ubuntu 22.04 LTS | Docker daemon не на всех BSD/macOS эквивалентах |
| Docker Engine | 24.0+ | 28.x | поддержка Compose v2 и security fixes |
| Docker Compose | v2.20+ | v2.35+ | актуальный синтаксис `compose.yaml` |
| Свободное место на диске | ≥ 5 ГБ | ≥ 10 ГБ | образ `mysql:8` ≈ 600 МБ + writable-слои контейнеров |
| RAM на хосте | ≥ 4 ГБ | ≥ 8 ГБ | каждый контейнер практики лимитирован 512 МБ (настраивается), плюс сам php-fpm и основная БД |
| CPU | ≥ 2 vCPU | ≥ 4 vCPU | при 0.5 CPU на контейнер практики и конкурентных попытках |
| Сетевой доступ | исходящий HTTPS к `registry-1.docker.io` и `auth.docker.io` | без ограничений | `docker pull mysql:8` / `postgres:16` при первом запуске |
| Unix-сокет | `/var/run/docker.sock` доступен на чтение/запись процессу php | uid процесса php входит в группу `docker`, или `chmod 0666 /var/run/docker.sock` | `CliDockerClient` (Laravel Process facade → `/usr/bin/docker`) обращается к демону по этому пути |

## Переменные окружения (production)

В production-`.env` (см. `config/practice.php` и
`dockerfiles/php.Dockerfile`):

| Ключ | Значение | Зачем |
|---|---|---|
| `PRACTICE_DRIVER` | `docker` | включает `DockerPracticeEnvironment`; дефолт `local-sqlite` |
| `PRACTICE_DOCKER_BINARY` | `/usr/bin/docker` | путь к CLI; образ `php` уже включает CLI |
| `PRACTICE_DOCKER_TIMEOUT_SECONDS` | `20` | жёсткий kill `docker exec` после N секунд |
| `PRACTICE_DOCKER_PROVISION_TIMEOUT_SECONDS` | `60` | окно на boot mysql:8 (10–30 с) + запас |
| `PRACTICE_DOCKER_MEMORY_MB` | `512` | `--memory` на контейнер |
| `PRACTICE_DOCKER_CPUS` | `0.5` | `--cpus` на контейнер |
| `PRACTICE_DOCKER_PIDS_LIMIT` | `128` | `--pids-limit` |
| `PRACTICE_DOCKER_MAX_RESULT_BYTES` | `1048576` | cap на размер сериализованного результата |
| `PRACTICE_DOCKER_MAX_STATEMENTS` | `20` | cap на многостатементные решения |
| `PRACTICE_DOCKER_PRUNE_TTL_MINUTES` | `30` | TTL для hanging-контейнеров (pruner) |
| `PRACTICE_MAX_CONCURRENT_ENVIRONMENTS` | см. ниже | опционально: глобальный семафор на одновременные попытки |
| `PRACTICE_STORAGE_PATH` | `storage/framework/practice` | для SQLite-фоллбэка; для docker-драйвера не используется, но если переключиться обратно — путь должен существовать и быть writable |

Если `PRACTICE_MAX_CONCURRENT_ENVIRONMENTS` не задан, лимит
отключён (см. `config/practice.php:43-47`); для прод-нагрузки
рекомендуется задавать — например, `10` для VPS с 4 vCPU и 8 ГБ RAM.

## Docker daemon: требования к доступу

PHP-сервис (php-fpm, artisan-команды) должен мочь вызывать
`docker run` / `docker exec` / `docker rm`. На Linux это делается
пробросом unix-сокета в сервис:

```yaml
services:
    php:
        volumes:
            - /var/run/docker.sock:/var/run/docker.sock
```

После `docker compose up -d php` проверка:

```bash
docker compose exec php docker ps
```

Должен вернуть список контейнеров без ошибки «Cannot connect to the
Docker daemon». Если прав нет — проверить membership процесса php в
группе `docker` или права на сокет (`stat -c '%a %U:%G' /var/run/docker.sock`).

На Windows + Docker Desktop (dev) daemon не слушает unix-сокет;
php-контейнер должен использовать либо `DOCKER_HOST=tcp://host.docker.internal:2375`
с включённой в Docker Desktop опцией «Expose daemon on tcp://localhost:2375
without TLS», либо альтернативный транспорт. На проде (Linux) это не нужно.

## Образы, которые будут загружены

`mysql:8` и `postgres:16` подтягиваются автоматически при первой
попытке задания соответствующего рантайма. Это официальные образы
из Docker Hub. Размер `mysql:8` ≈ 600 МБ, `postgres:16` ≈ 350 МБ.
После pull они кэшируются локально (`docker images`).

Предзагрузка (опционально, ускоряет первый запуск):

```bash
docker pull mysql:8
docker pull postgres:16
```

## Sanity check после деплоя

```bash
# 1. Драйвер резолвится
docker compose exec php php artisan tinker --execute='echo get_class(app(App\Services\Practice\PracticeEnvironmentManager::class));'
# Ожидаемо: App\Services\Practice\Docker\DockerPracticeEnvironment

# 2. dockerd доступен из php
docker compose exec php docker ps
# Ожидаемо: пустой список или контейнеры php/db/nginx — без ошибки

# 3. Образ mysql:8 доступен
docker compose exec php docker images mysql:8
# Ожидаемо: строка с IMAGE=mysql:8
```

## Известные ограничения текущего dev-окружения

Эти **не блокируют прод**, но объясняют расхождения между dev и prod:

1. На dev-машине (Windows + Docker Desktop) `DOCKER_HOST=tcp://host.docker.internal:2375` требует ручного включения в Docker Desktop → Settings → General. На проде (Linux) достаточно проброса unix-сокета.
2. На dev-машине нет образа `mysql:8` в локальном кэше Docker; первый запуск практики скачает его из Docker Hub. На проде это будет работать так же, но **первый пользователь** заплатит за скачивание.
3. Конфигурация `DOCKER_HOST` в `docker-compose.yml` локальной dev-версии указывает на TCP 2375; на проде эта переменная не нужна (CLI сам найдёт unix-сокет).

## Что не покрыто этим документом

- Процедура деплоя (compose vs k8s vs native) — выбирается индивидуально.
- SSL/TLS на dockerd (для прод обычно требуется; на текущем dev-окружении не настроен).
- Резервное копирование volumes и БД.
- Мониторинг и алертинг (Prometheus/Grafana, log shipping).
- Auto-scaling php-сервиса.

Всё это — за пределами задачи dockerдрайвера практики и
документируется отдельно.
