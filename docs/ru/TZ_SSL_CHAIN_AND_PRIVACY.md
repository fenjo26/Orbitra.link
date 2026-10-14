# ТЗ: `incomplete_chain` на новых доменах + непрочные настройки приватности

Источник: репорт тестировщика (два независимых бага). Ветка: `fix/ssl-chain-and-privacy-settings`.

---

## Баг 1. Новые домены навсегда застревают в `failed / incomplete_chain`

### Что видит пользователь

* Домен добавлен, A-запись указывает на IP сервера, прошло 2 часа — сертификата нет.
* В колонке SSL: «В файле сертификата нет промежуточного — сайт открывается в Firefox, но не в Chrome. Перевыпустите сертификат.» (`domains.sslIncompleteChain`, `frontend/src/locales/*.js:896`).
* Кнопка «Обновить сертификат» → тост `Certificate issued but chain is incomplete`. Сколько ни жми — результат тот же.

### Разбор

Ключевой факт: сообщение `incomplete_chain` выставляется **только после того, как certbot отработал успешно**
(`core/ssl_manager.php:595-612`, `api.php:8977-8998`). То есть Let's Encrypt сертификат выдал. Ломается проверка, а не выпуск.

**1.1. Проверка цепочки не отличает «файл нечитаемый» от «в файле один сертификат».**

`core/ssl_manager.php:185-192`:

```php
function orbitraCertificateChainComplete(string $certFile): array
{
    if (!is_file($certFile)) {
        return ['ok' => false, 'count' => 0];
    }
    $count = substr_count((string) @file_get_contents($certFile), '-----BEGIN CERTIFICATE-----');
    return ['ok' => $count >= 2, 'count' => $count];
}
```

`count = 0` физически невозможно для настоящего PEM-файла. Ноль означает ровно одно: **PHP не смог прочитать файл** — `is_file()` вернул `false` (нет прав на traverse каталога, `open_basedir`, битый симлинк) либо `@file_get_contents()` вернул `false` (нет прав на чтение). Оба случая молча схлопываются в тот же вердикт `incomplete_chain`, что и реальная обрезанная цепочка.

Почему PHP не может читать: certbot запускается через `sudo` (`core/nginx_config.php:47-54`), то есть пишет от root в `/etc/letsencrypt`, а вся дальнейшая работа с файлами идёт от `www-data` напрямую, без sudo. Каталоги `/etc/letsencrypt/live` и `/etc/letsencrypt/archive` root-only на многих сборках; плюс панели хостинга (ISPmanager, FASTPANEL, VestaCP) режут PHP-FPM пул через `open_basedir`. Асимметрия «пишем через sudo, читаем напрямую» — корень проблемы.

Побочный эффект той же асимметрии, который надо проверить у тестировщика отдельно: `core/nginx_config.php:305` выбирает LE-блок по тому же `file_exists()`. Если PHP файл не видит, домен получает **self-signed** блок в nginx — в браузере это `NET::ERR_CERT_AUTHORITY_INVALID`, хотя валидный LE-сертификат на диске лежит.

**1.2. «Обновить сертификат» — гарантированный no-op.**

`api.php:8967-8975` чистит старую линию перед перевыпуском:

```php
orbitraShell('rm -rf ' . escapeshellarg(ORBITRA_LETSENCRYPT_DIR . "/live/$domainName") . ' 2>&1');
orbitraShell('rm -rf ' . escapeshellarg(ORBITRA_LETSENCRYPT_DIR . "/archive/$domainName") . ' 2>&1');
orbitraShell('rm -f ' . escapeshellarg(ORBITRA_LETSENCRYPT_DIR . "/renewal/$domainName.conf") . ' 2>&1');
```

`rm` идёт **без sudo**, от `www-data`, по root-каталогу → `Permission denied`. Возвращаемое значение выбрасывается, ошибка нигде не всплывает. Добавить `sudo` тоже нельзя: `install.sh:75-80` разрешает www-data ровно пять команд — `nginx -t`, `systemctl reload nginx`, два конкретных `cp` и `/usr/bin/certbot`. `sudo rm` в sudoers отсутствует.

Дальше запускается `certbot certonly ... --keep-until-expiring` (`core/nginx_config.php:52`). Линия на диске цела, срок не вышел → certbot печатает `Certificate not yet due for renewal`, `orbitraCertbotSucceeded()` считает это успехом, проверка читает **тот же самый непрочитанный файл** → снова `incomplete_chain`. Цикл замкнулся: кнопка не может ничего исправить в принципе.

**1.3. Фоновый воркер добивает домен бэкоффом.**

`orbitraProcessSslQueue()` инкрементит `ssl_attempts` на каждом ложном `incomplete_chain` (`core/ssl_manager.php:606-611`), а `orbitraSslRetryDelay()` растит паузу до 12 часов. Домен, у которого всё в порядке, уезжает в полусуточный бэкофф.

**1.4. Сообщение об ошибке не локализовано.** `api.php:8992` — захардкоженная английская строка в тосте, в обход i18n.

### Диагностика на сервере тестировщика (сделать до фикса, приложить вывод)

```bash
D=<проблемный_домен>
sudo ls -la /etc/letsencrypt/live/$D/
sudo grep -c 'BEGIN CERTIFICATE' /etc/letsencrypt/live/$D/fullchain.pem   # ожидаем 2
namei -om /etc/letsencrypt/live/$D/fullchain.pem
sudo -u www-data cat /etc/letsencrypt/live/$D/fullchain.pem >/dev/null; echo "www-data read rc=$?"
php -i | grep -i open_basedir
sudo certbot certificates
```

* `grep -c` = 2 и `www-data read rc != 0` → подтверждена версия 1.1 (права/`open_basedir`).
* `grep -c` = 1 → цепочка действительно обрезана, чинить надо выпуск (см. п. 5 задач).

### Что сделать

1. **Читать `/etc/letsencrypt` привилегированно.** Ввести `orbitraReadCertFile(string $path): ?string`: сначала обычный `@file_get_contents()`, при `false`/`null` — фоллбек `sudo certbot certificates` либо `sudo cat` через явное правило sudoers. Заменить прямые чтения в `orbitraCertificateChainComplete()`. Отличать `null` (не прочитали) от строки (прочитали).
2. **Развести вердикты.** `orbitraCertificateChainComplete()` возвращает третье состояние: `count === 0` → код `chain_unreadable`, а не `incomplete_chain`. Домен при `chain_unreadable` **не помечается `failed`** и **не инкрементит `ssl_attempts`** — статус `installed` (сертификат выпущен и nginx его отдаёт), плюс предупреждение админу «панель не может прочитать файл сертификата, проверьте права на /etc/letsencrypt». Новый i18n-ключ `domains.sslChainUnreadable` во всех 7 локалях, `npm run check:i18n` должен быть зелёным.
3. **Починить `file_exists()` в генераторе nginx.** `core/nginx_config.php:61,305` и `core/ssl_manager.php:530` — использовать ту же привилегированную проверку наличия, чтобы валидный LE-домен не съезжал на self-signed из-за прав.
4. **Перевыпуск должен реально перевыпускать.** В `api.php:8967-8975` убрать три `rm` и заменить на `sudo certbot delete --cert-name <domain> -n` (уже покрыто существующим правилом sudoers на `/usr/bin/certbot`). Проверять код возврата и текст: неудачное удаление обязано вернуть ошибку в UI, а не проглатываться. На пути перевыпуска в `orbitraCertbotCertonlyCommand()` использовать `--force-renewal` вместо `--keep-until-expiring` (в фоновой очереди `--keep-until-expiring` оставить).
5. **Гарантировать полную цепочку на выпуске.** Добавить `--preferred-chain "ISRG Root X1"` в `orbitraCertbotCertonlyCommand()` и, если диагностика покажет реальный `count === 1`, использовать `chain.pem` для сборки fullchain вручную.
6. **Сбросить бэкофф.** Миграция/CLI: для доменов с `ssl_status='failed'` и `ssl_error` кода `incomplete_chain` обнулить `ssl_attempts`, `ssl_last_attempt`, `ssl_error` и перевести в `pending`, чтобы воркер перепроверил их сразу, а не через 12 часов.
7. **Локализовать `api.php:8992`** — вернуть код (`incomplete_chain` / `chain_unreadable`), фронт рисует по ключу.

### Критерии приёмки

* На сервере с root-only `/etc/letsencrypt` новый домен с корректной A-записью получает `installed`, а не `failed`.
* `sudo chmod 700 /etc/letsencrypt/live` не приводит к `incomplete_chain`; в UI появляется предупреждение о правах, статус остаётся `installed`.
* Домен с реально обрезанным `fullchain.pem` (один блок `BEGIN CERTIFICATE`) по-прежнему помечается `incomplete_chain`.
* «Обновить сертификат» на домене с валидным сертификатом действительно перевыпускает: `certbot certificates` показывает новую дату.
* Регресс `tests/nginx_config_regression_test.php` зелёный; `npm run check:i18n` зелёный.

---

## Баг 2. Настройки приватности не сохраняются

### Что видит пользователь

Настройки → Приватность → включить «Защита от сканирования», выбрать тип редиректа и URL → «Сохранить» → «Настройки успешно сохранены». Уйти в другой раздел и вернуться — всё в исходном состоянии.

### Разбор

**2.1. Ключи не входят в белый список эндпоинта.**

`frontend/src/components/PrivacySettings.jsx:38-48` шлёт `privacy_enabled`, `privacy_action`, `privacy_redirect_url` в `POST /api.php?action=global_settings`.

`api.php:11227-11232` — обработчик POST итерирует по **жёсткому списку разрешённых ключей**:

```php
foreach (['postback_key', 'currency', 'maxmind_license_key', 'maxmind_account_id', 'ip2location_token',
          'allow_php_landings', 'php_landing_timeout', 'admin_path',
          'stats_enabled', 'stats_retention_days', 'archive_retention_days',
          'admin_ip_access', 'ignore_prefetch', 'bot_isp_list', 'server_ip_override'] as $key) {
    if (!isset($settings[$key])) { continue; }
```

Ни одного `privacy_*` в списке нет → все три значения тихо отбрасываются, в `settings` ничего не пишется. При этом на выходе безусловно `{"status":"success"}` (`api.php:11284`) — отсюда ложный тост «Настройки успешно сохранены».

`api.php:11167` — GET-ветка тем же списком ограничивает `SELECT ... WHERE key IN (...)`, `privacy_*` там тоже нет. Даже если бы значения записались, форма не получила бы их обратно и всё равно показала дефолты из `useState`.

Итого два независимых обрыва: не пишется и не читается.

**2.2. Функциональность не реализована вообще.**

Поиск по всему репозиторию: `privacy_enabled`, `privacy_action`, `privacy_redirect_url` встречаются только в `PrivacySettings.jsx` и один раз в `config.php:828` (сид дефолта `privacy_enabled = '1'`). Ни `index.php`, ни `click.php` эти настройки не читают. То есть даже с починенным сохранением защита от сканирования ничего делать не будет — это мёртвый UI.

### Что сделать

1. **Персистентность (обязательно).** Добавить `privacy_enabled`, `privacy_action`, `privacy_redirect_url` в оба списка: `SELECT ... IN (...)` на `api.php:11167` и `foreach` на `api.php:11227`. Валидация при записи:
   * `privacy_enabled` — нормализовать к `'0'`/`'1'`, как уже сделано для `stats_enabled` (`api.php:11393`);
   * `privacy_action` — белый список `['redirect', '404', 'blank']`, иначе `'redirect'`;
   * `privacy_redirect_url` — `trim`, при `privacy_action === 'redirect'` требовать валидный `http(s)` URL, иначе вернуть `{"status":"error"}`. Пустой URL при выбранном редиректе сохранять нельзя.
   * Бэкфилл дефолтов в GET-ветке (`privacy_enabled='0'`, `privacy_action='redirect'`, `privacy_redirect_url=''`) — по образцу `api.php:11175-11190`.
2. **Не врать в ответе.** `global_settings` POST обязан возвращать ошибку, если пришёл ключ, которого нет в белом списке, — сейчас неизвестный ключ молча игнорируется с `status: success`. Это тот же класс бага, который спрячет следующую такую настройку. Вариант: собирать `ignored[]` и возвращать `{"status":"error","message":"unknown_settings","data":{"ignored":[...]}}`.
3. **Реализовать саму защиту.** В `index.php`, в ветке, где кампания/поток не разрешились и код уходит в 404: если `privacy_enabled === '1'` — применить `privacy_action`:
   * `redirect` → `302` на `privacy_redirect_url`;
   * `404` → текущее поведение;
   * `blank` → `200` с пустым телом.
   Настройки читать из кэша настроек, а не запросом на каждый клик. Обязательно **не задевать** служебные маршруты: `/.well-known/acme-challenge/` (иначе сломается выпуск сертификатов — см. баг 1), `/lander/`, ассеты лендингов, postback, `admin.php`.
4. **Если реализация п.3 не в этом релизе** — скрыть раздел «Приватность» из настроек, чтобы не отдавать пользователю переключатель, который ничего не делает.

### Критерии приёмки

* Включить защиту, выбрать `Redirect 302` + URL, сохранить, перейти в другой раздел, вернуться — значения на месте. Перезагрузка страницы — значения на месте. `SELECT * FROM settings WHERE key LIKE 'privacy_%'` показывает записи.
* Выбран `redirect` с пустым URL → сохранение возвращает ошибку, тост «успешно» не показывается.
* Прямой заход на домен без параметров кампании ведёт себя по выбранному действию; `curl http://<домен>/.well-known/acme-challenge/test` по-прежнему обслуживается nginx и не редиректится.
* Отправка неизвестного ключа в `global_settings` возвращает ошибку, а не `success`.

---

## Баг 3. Режим «Custom» в форме домена недоступен — поля никогда не показываются

### Что видит пользователь

При добавлении домена реально работают только два варианта — Cloudflare и Let's Encrypt. Кнопка «Custom» нажимается, подсвечивается, но ничего не появляется: вручную подставить свой сертификат некуда.

### Разбор

`frontend/src/components/Domains.jsx:1393-1397` — кнопка «Custom» ставит `ssl_source: 'custom'` и **сбрасывает** `cloudflare_proxy: false`.

`frontend/src/components/Domains.jsx:1418` — блок с полями сертификата показывается по условию:

```jsx
{(formData.cloudflare_proxy || formData.custom_ssl_cert || formData.custom_ssl_key) && (
```

`ssl_source` в условии нет. На новом домене `custom_ssl_cert` и `custom_ssl_key` пустые (`Domains.jsx:83-85`), `cloudflare_proxy` кнопка только что выключила → условие ложно, поля не рендерятся. Замкнутый круг: поля видны, только если уже заполнены. Гейт вдобавок инвертирован относительно селектора — он открывается ровно в том режиме (`cloudflare_proxy`), который кнопка «Custom» выключает.

Даже если поля показать, ими нельзя пользоваться для Let's Encrypt:

* Поля принимают **пути к файлам на сервере**, а не содержимое PEM (`Domains.jsx:1428-1450` — `Certificate Path` / `Private Key Path`, плейсхолдеры `/etc/orbitra/ssl/...`). Вставить текст сертификата некуда.
* Валидация в `api.php:8526-8532` делает `file_exists()` **от www-data**. Для любого пути внутри `/etc/letsencrypt` это вернёт false (см. Баг 1.1) → «Certificate file not found» на существующем файле.
* `core/nginx_config.php:298` использует тот же `file_exists()` при сборке конфига — путь, который не видит PHP, молча деградирует в self-signed.

Итого ручного способа поставить сертификат сегодня нет вообще.

### Что сделать (минимальный фикс)

1. `Domains.jsx:1418` — заменить условие на `formData.ssl_source === 'custom' || formData.cloudflare_proxy || formData.custom_ssl_cert || formData.custom_ssl_key`.
2. Заголовок и подсказку блока сделать зависимыми от режима: для `custom` — «Свой сертификат», для `cloudflare_origin` — текущий текст про Full Strict.
3. Валидацию `file_exists()` в `api.php:8526-8532` перевести на привилегированную проверку из Бага 1, задача 1 — иначе путь в `/etc/letsencrypt` не примется.

---

## Фича. Ручная установка сертификата + видимая диагностика

Запрос: кнопка ручного добавления сертификата и чтобы ошибка была видна сразу.

### Ф1. Вставка PEM вместо пути к файлу

В режиме `ssl_source = 'custom'` дать два `textarea`: **Сертификат (PEM, вместе с промежуточным)** и **Приватный ключ (PEM)**. Путь к файлу оставить как альтернативный ввод для тех, у кого файлы уже на сервере (переключатель «вставить текст / указать путь»).

Серверная часть, новый эндпоинт `POST /api.php?action=install_custom_ssl` (`{id, cert_pem, key_pem}`):

1. Валидировать до записи, каждую проверку — с отдельным кодом ошибки:
   * `cert_pem` содержит ≥ 1 блок `BEGIN CERTIFICATE`; если ровно 1 — вернуть предупреждение `chain_incomplete_pasted` («нет промежуточного, Chrome не примет»), но дать сохранить осознанно;
   * `key_pem` парсится (`openssl_pkey_get_private`);
   * ключ соответствует сертификату (сравнить `openssl_pkey_get_public($cert)` и публичную часть ключа) — код `key_mismatch`;
   * `notAfter` в будущем — код `cert_expired`, `notBefore` в прошлом;
   * CN/SAN покрывает имя домена (учесть wildcard) — код `cert_domain_mismatch`, только предупреждение, не блок.
2. Писать в `/etc/orbitra/ssl/<domain>.crt` и `<domain>.key`, режимы `0644` / `0640`, владелец root, группа — та, под которой читает nginx. Каталог `/etc/orbitra/ssl` уже используется для self-signed (`core/nginx_config.php:30-31`), запись — через привилегированный хелпер, как в Баге 1 задача 1 (обычный `file_put_contents` от www-data туда не запишет).
3. Сохранить пути в `custom_ssl_cert` / `custom_ssl_key`, `ssl_source='custom'`, `ssl_status='installed'`.
4. `orbitraSyncNginx()` → `sudo nginx -t` → `sudo systemctl reload nginx`. **Если `nginx -t` не прошёл — откатить файлы и конфиг и вернуть stderr nginx в ответе.** Домены не должны падать из-за чужого PEM.
5. Проверить результат живьём через уже существующий `orbitraVerifyOriginSsl()` (`core/ssl_manager.php:657`) и показать вердикт в UI.

### Ф2. Ручной запуск выпуска Let's Encrypt с видимым логом

Кнопка перевыпуска уже есть (`Domains.jsx:673`, `api.php:8869`), но она непригодна для диагностики:

* Ошибки certbot обрезаются до последних 500 символов и кладутся в `ssl_error` (`core/ssl_manager.php:617-619`, `api.php:9012-9014`), а UI показывает только короткую фразу.
* Синхронный путь при сохранении домена вообще теряет вывод: `installSslForDomain()` (`api.php:1966`) уходит в фон как `orbitraShell($cmd . ' > /dev/null 2>&1 &')` — весь stdout/stderr в `/dev/null`. Если certbot упал, пользователь не узнает почему никогда.

Что сделать:

1. **Не терять вывод.** Фоновый запуск писать в `var/logs/ssl_installer.log` (`>> log 2>&1 &`), а не в `/dev/null`.
2. **Показывать сырой вывод.** В модалке домена — раскрывающийся блок «Журнал выпуска» с последними строками certbot (уже лежат в `ssl_error`) плюс новый эндпоинт `ssl_log?id=` с хвостом `var/logs/ssl_installer.log`, отфильтрованным по имени домена.
3. **Показывать прогресс, а не тишину.** Кнопка «Выпустить сейчас» → состояние `installing` в строке домена с индикатором, поллинг статуса раз в 3-5 сек, финал — успех или конкретная ошибка. Сейчас между нажатием и результатом пользователь смотрит в неизменившуюся таблицу.
4. **Предполётная проверка в форме добавления.** При вводе имени домена и выборе Let's Encrypt сразу дёргать `orbitraDnsPreflightCheck()` / существующий `check_domain_dns` и показывать под полем: «A-запись → 1.2.3.4, IP сервера → 1.2.3.4 ✓» либо «домен указывает на 5.6.7.8, сертификат не выпустится». Это снимает большую часть обращений.
5. **Показывать состояние окружения.** `orbitraSslEnvironment()` (`core/ssl_manager.php:97`) уже возвращает `problems[]` — `no_certbot`, `no_sudo_certbot`, `acme_not_writable`. Вывести это баннером на странице «Домены», когда `can_issue = false`, а не только внутри ответа на перевыпуск.
6. **Все коды ошибок — в i18n.** Сейчас `api.php:8916-8956, 8992, 9018` отдают английские строки напрямую в тост. Перевести на коды + `frontend/src/locales/*.js` (7 локалей), `npm run check:i18n` зелёный.

### Критерии приёмки

* Кнопка «Custom» в форме добавления показывает поля сертификата сразу.
* Вставленный PEM с несовпадающим ключом отклоняется с внятным сообщением, а не сохраняется.
* Вставленный корректный PEM → домен отдаёт HTTPS этим сертификатом после reload, `openssl s_client -servername <домен> -connect <домен>:443` показывает вставленную цепочку.
* Битый PEM не может уронить nginx: после неудачного `nginx -t` конфиг откатывается, остальные домены работают.
* При выпуске Let's Encrypt пользователь видит либо прогресс, либо конкретную причину отказа (DNS, rate limit, нет certbot) без похода в SSH.
* На сервере без certbot страница «Домены» показывает баннер с инструкцией, а не молча копит `failed`.

---

## Баг 4. LeadForge: список «CPA Affiliate Network» — 8-12 сетей против 50+ во вкладке Affiliate Networks

### Что видит пользователь

Во вкладке LeadForge выпадающий список «CPA Affiliate Network» содержит около десятка партнёрок, тогда как во вкладке «Affiliate Networks» их 50+. Непонятно, почему свои партнёрки нельзя выбрать при сборке лендинга.

### Разбор

**4.1. Это два разных объекта, и простое «прокинуть список» сломает отправку лидов.**

* Вкладка **Affiliate Networks** — таблица `affiliate_networks` (`database.sql:19-30`): `name`, `template`, `offer_params`, `postback_url`. Это **входящее** направление: как партнёрка присылает конверсии в трекер. Пользовательский список набран из Keitaro-пака `data/keitaro_affiliate_networks.json` — там 395 шаблонов, каждый несёт только `offer_params_template` и `postback_url_template`. Кода отправки лида в них нет ни у одного.
* Список в LeadForge — константа `CPA_NETWORKS` (`frontend/src/components/LeadForgePage.jsx:13-26`) и её серверный двойник `LeadForge::networks()` (`core/LeadForge.php:27-46`). Это **исходящее** направление: под каждую сеть в `order.php` бандла генерируется свой адаптер — конкретный endpoint и конкретная форма payload (`core/LeadForge.php:1344-1548`). Ста́вить в этот список партнёрку, для которой адаптера нет, значит собрать лендинг, который никуда не отправляет лид.

Поэтому «сделать чтобы вкладка Affiliate Networks передавала значения» — правильная идея, но только через явный режим Custom, а не подстановкой имён в тот же селектор.

**4.2. Три списка уже разъехались между собой.**

| Сеть | `CPA_NETWORKS` (фронт) | `LeadForge::networks()` (бэк) | `case` в `order.php` |
|---|---|---|---|
| drcash, webvork, kma, terraleads, leadbit, lemonad, everad | да | да | да |
| luckyonline | **нет** | да | да (`lucky` / `luckyonline`) |
| ezaff | **нет** | да | да |
| offercify | **нет** | да | **нет** |
| adcombo | да | да | **нет** |
| m1 (M1-Shop) | да | да | **нет** |
| monsterleads | да | да | **нет** |
| trafficlight (Traffic Light) | да | **нет** | **нет** |

**4.3. Главное: четыре сети из списка молча теряют лиды.**

`adcombo`, `m1`, `monsterleads`, `trafficlight` выбираются в UI, но `case` для них нет — они попадают в `default:` (`core/LeadForge.php:1536-1548`):

```php
default:
    if (!empty($LF['offer_id']) && filter_var($LF['offer_id'], FILTER_VALIDATE_URL)) {
        // form passthrough на URL из offer_id
    } else {
        $res = ['http_code' => 200, 'body' => '{"status":"ok"}'];
    }
    break;
```

`buildBundle()` при этом не проверяет сеть по белому списку — `array_key_exists($card['network'], self::networks())` на строке 401 относится только к ветке автодетекта режима `auto`. Поэтому `trafficlight`, которого вообще нет в `LeadForge::networks()`, спокойно доезжает до сборки и получает тот же `default:`.

Плейсхолдеры в UI для этих сетей просят **числовой** Offer ID («Offer ID (e.g. 29314)», «Product ID (e.g. 642)», `LeadForgePage.jsx:22-24`). Числовой ID не проходит `FILTER_VALIDATE_URL`, значит выполняется вторая ветка: **никакого HTTP-запроса не происходит, а движку возвращается фальшивый 200 OK**. Лид считается успешно отправленным, лендинг показывает thank-you, в партнёрке лида нет. Это не косметика списка, это тихая потеря трафика — и чинить нужно в первую очередь именно это.

### Что сделать

1. **`default:` не имеет права возвращать успех (блокирующее).** Нет адаптера и `offer_id` не URL — значит отправить лид некуда: писать ошибку через `lf_log_event()`, возвращать честный неуспех, и **обязательно** сохранять лид в CRM-хранилище (`orbitraCrmRecordLead()`, уже вызывается из `index.php`), чтобы данные не пропали, пока интеграцию чинят. Фальшивый `{"status":"ok"}` убрать.
2. **Один источник правды для списка сетей.** Удалить константу `CPA_NETWORKS` из фронта; отдавать список из `LeadForge::networks()` новым `GET /api.php?action=leadforge_networks`. Каждая запись несёт `id`, `label`, `placeholder`, `default_currency`, `default_payout` и флаг **`has_adapter`** (есть ли `case` в `order.php`). Сети без адаптера в селекторе либо не показывать, либо показывать с явной пометкой и требовать URL endpoint’а.
3. **Дописать недостающие адаптеры**: `adcombo`, `m1`, `monsterleads`. По `trafficlight` и `offercify` решить отдельно — либо адаптер, либо убрать из обоих списков (сейчас `trafficlight` есть только на фронте, `offercify` только на бэке).
4. **Собственно запрос пользователя: вторая группа в селекторе.** В `<select>` добавить `<optgroup>` «Мои партнёрки», заполненную из `affiliate_networks` (`state='active' AND is_archived=0`) — тем же эндпоинтом, что уже питает вкладку (`api.php:6559`). Выбор такой записи:
   * переключает бандл в адаптер `custom`;
   * префилит endpoint и параметры из `offer_params` строки;
   * если имя строки совпадает с одной из сигнатур `LeadForge::networks()[*]['sigs']` (механизм автодетекта уже есть, `core/LeadForge.php:30-45`) — предложить встроенный адаптер вместо `custom`, показав это пользователю. **Молча подменять адаптер по совпадению имени нельзя.**
   * `custom` без валидного URL endpoint’а не даёт собрать бандл — проверка на сборке (`buildBundle`), а не в момент отправки лида.
5. **Ничего не потерять с обеих сторон.** Схему `affiliate_networks` не трогать: на неё ссылаются `offers.affiliate_network_id` и пайплайн постбэков (`api.php:2849, 5803`). LeadForge только **читает** эту таблицу, своих полей туда не пишет; выбранный адаптер и endpoint хранятся в конфиге бандла, как сейчас. Вкладка Affiliate Networks продолжает работать без изменений.

### Критерии приёмки

* Выбор AdCombo / M1-Shop / MonsterLeads с числовым Offer ID **не** даёт thank-you с фальшивым успехом: либо лид реально уходит (после задачи 3), либо форма показывает ошибку и лид лежит в CRM-хранилище.
* Список сетей в UI и `case`’ы в `order.php` совпадают один в один; сеть без адаптера невозможно выбрать в режиме, который её не поддерживает.
* В селекторе видна группа «Мои партнёрки» со всеми активными записями вкладки Affiliate Networks; выбор любой из них собирает рабочий бандл через `custom` с подставленным endpoint’ом.
* Вкладка Affiliate Networks после изменений работает как раньше: постбэки принимаются, привязка офферов к сетям не сломана.
* `npm run check:i18n` зелёный — названия групп и новые подсказки во всех 7 локалях.

---

## Порядок работ

1. Собрать диагностику по п. «Диагностика на сервере тестировщика» и приложить к тикету.
2. Баг 4 задача 1 — блокирующее: LeadForge молча теряет лиды, это дороже всего остального в документе.
3. Баг 1, задачи 1–4 и 6 — блокирующий приоритет, домены не работают.
4. Баг 3, задачи 1–3 — правка в одну строку условия, даёт пользователю обходной путь прямо сейчас.
5. Баг 2, задачи 1–2 — быстрый фикс, попадает в тот же релиз.
6. Баг 4, задачи 2–4 — список сетей и группа «Мои партнёрки».
7. Фича Ф1 и Ф2 задачи 1–4 — следующим шагом, после того как чтение `/etc/letsencrypt` из Бага 1 задача 1 уже есть.
8. Баг 1 задача 5, Баг 2 задача 3, Баг 4 задача 3, Ф2 задачи 5–6 — по результатам диагностики / следующим релизом.
9. Бамп версии и CHANGELOG по обычной процедуре.

## Затронутые файлы

* `core/ssl_manager.php` — `orbitraCertificateChainComplete()`, `orbitraProcessSslQueue()`
* `core/nginx_config.php` — `orbitraCertbotCertonlyCommand()`, `orbitraBuildNginxConfig()`
* `core/shell.php` — новый привилегированный хелпер чтения
* `api.php` — `reissue_ssl` (8869-9029), `global_settings` (11165-11288)
* `index.php` — ветка 404 при неразрешённой кампании
* `api.php` — `save_domain` (8515-8560), новый `install_custom_ssl`, новый `ssl_log`
* `install.sh` — sudoers, если добавляется правило на чтение
* `frontend/src/components/Domains.jsx`, `frontend/src/components/PrivacySettings.jsx`
* `frontend/src/locales/*.js` — 7 локалей
* `core/LeadForge.php` — `networks()`, `orderPhp()` switch и его `default:`, `buildBundle()`
* `frontend/src/components/LeadForgePage.jsx` — `CPA_NETWORKS` (удалить), селектор сети
* `migrations/` — сброс бэкоффа у ложно-упавших доменов
