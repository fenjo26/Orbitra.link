# Миграция из Keitaro

Orbitra переносит из Keitaro настройки — партнёрки, офферы, домены, кампании с токенами Click API, потоки с фильтрами, постбеки кампаний, лендинги и источники. Статистика кликов не переносится: это новый старт.

## Что переносится

| Keitaro | Orbitra | Что сохраняется |
|---|---|---|
| Партнёрские сети | Партнерки | Postback URL, параметры оффера |
| Офферы | Офферы | URL, выплата, гео |
| Домены | Домены | Парковка, HTTPS-only |
| Группы | Группы кампаний | Название |
| Кампании | Кампании | Алиас, **токен Click API**, состояние, источник |
| Потоки | Потоки | Тип, позиция, вес, схема, действие, фильтры (в том числе `sub_id_N` и фильтры по параметру), режимы «разрешить / запретить», связи с офферами и лендингами |
| Постбеки кампаний | S2S Postbacks | URL, метод, статусы |
| Лендинги | Лендинги | Названия и URL |
| Источники | Источники | Параметры |

Не переносятся: клики и конверсии, пользователи, файлы локальных лендингов (только записи о них). Фильтры, которые нельзя перенести, перечисляются в отчёте импорта.

Шаблоны источников и партнёрок Keitaro уже встроены в Orbitra — переносить их не нужно ([Источники и партнёрки](sources-and-networks.md)).

## 1. Дамп на сервере Keitaro

Подключитесь к серверу Keitaro по SSH под root и выполните команду целиком. Она берёт доступ к базе из `/etc/keitaro/env/inventory.env`, работает и с клиентом MariaDB на хосте, и с MariaDB внутри Docker-контейнера Keitaro, выгружает только таблицы настроек (без логов кликов) и откажется работать, если таблицы не найдены — чтобы случайно не выгрузить всю базу.

```bash
bash <<'ORBITRA_DUMP'
set -eo pipefail

# Sourced before set -u: the env file is Keitaro's, not ours.
source /etc/keitaro/env/inventory.env
set -u

# Password goes through the environment, never through argv or a file left on disk.
export MYSQL_PWD="$MARIADB_KEITARO_PASSWORD"
DB="$MARIADB_KEITARO_DATABASE"

# MariaDB client on the host, or inside the Keitaro MariaDB container.
if CLI=$(command -v mariadb || command -v mysql) && DUMP=$(command -v mariadb-dump || command -v mysqldump); then
  sql()  { "$CLI"  -u"$MARIADB_KEITARO_USER" -h127.0.0.1 -P3306 --protocol=tcp "$@" </dev/null; }
  dump() { "$DUMP" -u"$MARIADB_KEITARO_USER" -h127.0.0.1 -P3306 --protocol=tcp "$@" </dev/null; }
else
  CT=$(docker ps --format '{{.Names}}' 2>/dev/null | grep -i -m1 -E 'mariadb|mysql' || true)
  if [ -z "$CT" ]; then
    echo "ERROR: no MariaDB client on this server and no MariaDB container found" >&2
    exit 1
  fi
  echo "Using MariaDB inside container: $CT"
  sql()  { docker exec -e MYSQL_PWD "$CT" sh -c 'exec $(command -v mariadb || command -v mysql) "$@"' sh -u"$MARIADB_KEITARO_USER" "$@" </dev/null; }
  dump() { docker exec -e MYSQL_PWD "$CT" sh -c 'exec $(command -v mariadb-dump || command -v mysqldump) "$@"' sh -u"$MARIADB_KEITARO_USER" "$@" </dev/null; }
fi

# Settings tables only: campaigns, offers, domains, streams... - no click logs.
TABLES=$(sql -N "$DB" -e "
SELECT table_name FROM information_schema.tables
WHERE table_schema = DATABASE()
  AND table_name IN (
    'keitaro_affiliate_networks', 'keitaro_groups', 'keitaro_offers',
    'keitaro_domains', 'keitaro_campaigns', 'keitaro_campaign_postbacks',
    'keitaro_landings', 'keitaro_streams', 'keitaro_stream_filters',
    'keitaro_stream_offer_associations', 'keitaro_stream_landing_associations',
    'keitaro_traffic_sources', 'keitaro_ref_sources')
ORDER BY table_name" | tr '\n' ' ')

# Without a table list mysqldump would dump the WHOLE database, click logs included.
if [ -z "$(echo $TABLES)" ]; then
  echo "ERROR: no Keitaro tables found in database $DB" >&2
  exit 1
fi

OUT=/root/keitaro_orbitra_full.sql.gz
echo "Dumping tables: $TABLES"
dump --single-transaction --quick --skip-lock-tables "$DB" $TABLES | gzip -1 > "$OUT"

ls -lah "$OUT"
echo "DONE: $OUT"
ORBITRA_DUMP
```

В конце появится `DONE: /root/keitaro_orbitra_full.sql.gz`. Файл обычно 1–5 МБ.

## 2. Скачайте файл

На странице **⚙ → Миграции** введите IP сервера Keitaro — панель подставит его в готовые команды. Выполните команду на своём компьютере, не на сервере:

```bash
# macOS / Linux
scp root@IP_KEITARO:/root/keitaro_orbitra_full.sql.gz ~/Downloads/
```

```powershell
# Windows (PowerShell)
scp root@IP_KEITARO:/root/keitaro_orbitra_full.sql.gz $env:USERPROFILE\Downloads\
```

## 3. Импорт в Orbitra

**⚙ → Миграции → Импорт из Keitaro (SQL dump)**:

1. Выберите файл `.sql.gz` или `.sql`. Дамп не выполняется как SQL — он только разбирается.
2. Отметьте, что импортировать. **Сохранять ID кампаний** (Orbitra ID = Keitaro ID) работает только на чистой установке, где таблица кампаний пуста, включая архив.
3. **Показать предпросмотр** — пробный прогон без записи: сколько объектов каждого типа будет импортировано и какие фильтры не переносятся.
4. **Импортировать в Orbitra**.

Для повторного импорта на тестовом сервере есть блок **Очистка**: удаляет всю конфигурацию трекера (домены, офферы, партнёрки, кампании, потоки, постбеки, пиксели, источники, лендинги, группы), не трогая статистику и пользователей. Для подтверждения нужно ввести `DELETE`.

## 4. После импорта

- **Токены.** Откройте пару кампаний — токен Click API должен совпадать с Keitaro. Тогда интеграционные скрипты на сайтах (`click.php`, KClient) продолжат работать, нужно только поменять в них адрес трекера.
- **Домены.** Направьте A-записи доменов на новый сервер (или подключите Cloudflare / Namecheap / Dynadot — записи пропишутся сами) и дождитесь сертификатов ([Домены и SSL](domains.md)).
- **Постбеки партнёрок.** Postback URL в Orbitra другой: `https://ваш-домен/<postback_key>/postback?…`. Обновите его в кабинетах сетей ([Постбеки](postbacks.md)).
- **Гео-база.** Без неё фильтры стран не работают — проверьте **⚙ → Гео базы**.
- **Проверка маршрутизации.** Тестовая ссылка кампании с `&_dbg=1` покажет, какой поток выбирается и почему, без записи клика ([Кампании и потоки](campaigns.md#тестовая-ссылка)).

## Частые проблемы

| Симптом | Причина и решение |
|---|---|
| Команда дампа пишет `no Keitaro tables found` | Не та база в `inventory.env` или нестандартная установка Keitaro. Проверьте `MARIADB_KEITARO_DATABASE` |
| `no MariaDB client … and no MariaDB container found` | Нет ни клиента MariaDB, ни запущенного контейнера с MariaDB — запустите Keitaro или поставьте клиент |
| Импорт ничего не нашёл | Дамп пустой или не из той базы — посмотрите размер файла и строку `Dumping tables:` в выводе команды |
| Потоки не перенеслись | Не отмечен пункт «Импортировать потоки» или в дампе нет `keitaro_streams` и таблиц связей |
| Домен отвечает 404 | DNS ещё не указывает на новый сервер или домену не задана Index-кампания |
