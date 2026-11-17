# CPA.RIP load-test kit (Orbitra / K6)

Методика CPA.RIP: лестница ступеней 20→480 rps (по 30 с разгон + 3 мин удержание),
latency по всем запросам окна (таймаут 5 с), сверка каждого `event_id` с базой
трекера, повтор лучшей ступени ×3 на чистой базе, recovery (20 rps / 2 мин) после
перегрузки, профили: unique / repeat (пул 10 000) / repeat-tight (пул 50).
См. статью CPA.RIP для полного описания метрик (а) «event_id найден ровно один раз»
и (б) «cid редиректа существует в clicks».

## Стенд

- **Генератор**: любой VPS ≥ 2 vCPU / 4 ГБ (для 640–800 rps — 4 vCPU), тот же ДЦ, что
  и трекер. Ubuntu 24.04. Установить: k6 v1.8.3, python3, sqlite3, jq, curl.
  Тюнинг: `net.ipv4.tcp_tw_reuse=1`, `net.ipv4.ip_local_port_range=1024 65535`,
  `ulimit -n ≥ 65535` (persist в /etc/sysctl.d/).
- **Трекер (SUT)**: Orbitra, nginx + php-fpm. Кампания с alias, redirect на оффер
  (302 + `?cid=<uuid>`), XFF trusted, уникальность «IP, 24h», спул отложенной записи.
  SSH-пользователь только для теста: sudo на `sqlite3 -readonly <db>` + скрипт RESET
  (чистит clicks и кэши), чтение /proc. Отдельный ключ ed25519 без пароля.

## Установка кита

```bash
cp -r cpa-rip /opt/loadtest && cd /root/../opt/loadtest   # где угодно
cp params.env.example params.env && $EDITOR params.env    # заполнить значения
python3 scripts/gen_pools.py                              # сгенерировать пулы repeat
bash scripts/pre_run.sh t1                                # RESET + пауза + loadavg-гейт + smoke
```

## Прогон ступени / лестницы

```bash
bash scripts/run_stage.sh <run> unique 20 20     # <run> <profile> <rate> <start_rate>
# repeat: тот же вызов с PROFILE=repeat (пул 10 000 в data/repeat10k.json)
# лестница: повышать rate, start_rate = предыдущая ступень; остановка при ошибках ответа
bash scripts/recovery.sh <run>                   # после перегрузки (PROFILE из окружения)
python3 scripts/build_results.py                 # сводка results/summary.json
```

Каждая ступень: `logs/<run>_<profile>_s<rate>/` — k6 stdout/json, `stage_stats.json`
(sent, ошибки в окне/вне окна, p50/p95/p99/max), `recon.json` (missing/dupes/cid_missing,
метрики (а)/(б)), `cpu_local_stats.json` + `cpu_tracker_stats.json` (CPU/loadavg по окну),
`tracker_logs.txt` (хвосты error-логов), `resp_log.jsonl.gz` (лог каждого запроса),
`wal_size.csv` (размер WAL-файла трекера раз в 5 с; сэмпл запускается отдельно:
`bash scripts/sample_wal.sh <dir> 280 &`).

Строгий режим CPA.RIP: `click_debounce_seconds=0` в настройках трекера (каждый запрос —
своя строка; после смены перезапустить php-fpm — значение кэшируется в воркерах),
метрика (а) становится основной. Восстановить 2 после тестов.

## Стоп-критерий генератора

Фактический темп ниже заданного > 1% или CPU генератора > 90% → ступень
«генератор не держит»; трекеру провал не засчитывается, повтор не требуется.

Всё содержимое `params.env` секретно (ключи/адреса) — в репозиторий не коммитить.
