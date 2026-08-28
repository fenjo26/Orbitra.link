#!/usr/bin/env bash
# Orbitra — диагностика выпуска Let's Encrypt.
#
# Запускать НА СЕРВЕРЕ ТРЕКЕРА от root:
#     sudo bash ssl_diagnose.sh ваш-домен.com
#
# Скрипт ничего не меняет: только читает файлы, права, базу и делает
# один сетевой запрос к самому домену. Вывод целиком отправить разработчику.
#
# Смысл: панель пишет "incomplete_chain" в двух совершенно разных случаях —
# когда цепочка правда обрезана, и когда PHP просто не может прочитать файл.
# Отличить их можно только сравнив, что видит root и что видит веб-юзер.

set -u
D="${1:-}"
if [ -z "$D" ]; then
  echo "Использование: sudo bash ssl_diagnose.sh ваш-домен.com"
  exit 1
fi

hr() { printf '\n===== %s =====\n' "$1"; }
have() { command -v "$1" >/dev/null 2>&1; }

echo "Orbitra SSL diagnose · домен: $D · $(date '+%Y-%m-%d %H:%M:%S %Z')"
echo "Запущен от: $(id -un)"
[ "$(id -u)" -ne 0 ] && echo "!! ВНИМАНИЕ: не root. Часть проверок будет неполной — перезапустите через sudo."

# ---------------------------------------------------------------- корень и юзер
ROOT=""
for c in /var/www/orbitra /var/www/html/orbitra /var/www/html /srv/orbitra /home/orbitra; do
  [ -f "$c/core/ssl_manager.php" ] && ROOT="$c" && break
done
if [ -z "$ROOT" ]; then
  ROOT="$(dirname "$(dirname "$(find /var/www /srv /home /opt -maxdepth 5 -type f -path '*/core/ssl_manager.php' 2>/dev/null | head -1)")")"
fi
WEBUSER="$(ps -eo user=,comm= 2>/dev/null | awk '$2 ~ /^(php-fpm|php_fpm)/ && $1 != "root" {print $1}' | sort -u | head -1)"
[ -z "$WEBUSER" ] && WEBUSER="$(ps -eo user=,comm= 2>/dev/null | awk '$2 ~ /^nginx/ && $1 != "root" {print $1}' | sort -u | head -1)"
[ -z "$WEBUSER" ] && WEBUSER="www-data"

hr "1. ОКРУЖЕНИЕ"
echo "Orbitra root : ${ROOT:-НЕ НАЙДЕН}"
echo "Веб-юзер     : $WEBUSER"
have php     && echo "PHP CLI      : $(php -r 'echo PHP_VERSION;')" || echo "PHP CLI      : нет"
have certbot && echo "certbot      : $(certbot --version 2>&1 | head -1)" || echo "certbot      : НЕ УСТАНОВЛЕН"
have nginx   && echo "nginx        : $(nginx -v 2>&1)" || echo "nginx        : нет"
echo "certbot path : $(command -v certbot 2>/dev/null || echo '-')"
echo "--- sudoers для веб-юзера ---"
cat /etc/sudoers.d/orbitra-ssl 2>/dev/null || echo "(файл /etc/sudoers.d/orbitra-ssl отсутствует)"
echo "--- может ли веб-юзер запускать certbot без пароля ---"
sudo -n -u "$WEBUSER" sudo -n certbot --version </dev/null 2>&1 | head -3

hr "2. ФАЙЛЫ СЕРТИФИКАТА — ЧТО ВИДИТ ROOT"
ls -ld /etc/letsencrypt /etc/letsencrypt/live /etc/letsencrypt/archive 2>&1
echo "--- каталог домена ---"
ls -la "/etc/letsencrypt/live/$D/" 2>&1
echo "--- цепочка symlink ---"
have namei && namei -om "/etc/letsencrypt/live/$D/fullchain.pem" 2>&1 || readlink -f "/etc/letsencrypt/live/$D/fullchain.pem" 2>&1
echo "--- сколько сертификатов в файлах (root) ---"
for f in fullchain.pem cert.pem chain.pem; do
  p="/etc/letsencrypt/live/$D/$f"
  if [ -f "$p" ]; then
    echo "$f : $(grep -c 'BEGIN CERTIFICATE' "$p" 2>/dev/null) шт., $(stat -Lc '%s байт, права %a, владелец %U:%G' "$p" 2>/dev/null)"
  else
    echo "$f : НЕТ ФАЙЛА"
  fi
done
echo "--- кто выдал и до какого числа ---"
have openssl && openssl x509 -in "/etc/letsencrypt/live/$D/cert.pem" -noout -subject -issuer -dates 2>&1 | head -5
echo "--- что знает сам certbot ---"
if have certbot; then
  certbot certificates 2>&1 | sed -n "/$D/,+8p" | head -12
  certbot certificates 2>&1 | grep -q "$D" || echo "(certbot не знает про этот домен — линии сертификата нет)"
else
  echo "(certbot не установлен)"
fi

hr "3. КЛЮЧЕВАЯ ПРОВЕРКА: ЧТО ВИДИТ ВЕБ-ЮЗЕР ($WEBUSER)"
echo "--- доступ к файлу обычными средствами ---"
sudo -n -u "$WEBUSER" test -x /etc/letsencrypt/live      && echo "traverse /etc/letsencrypt/live      : ДА" || echo "traverse /etc/letsencrypt/live      : НЕТ"
sudo -n -u "$WEBUSER" test -x "/etc/letsencrypt/live/$D" && echo "traverse /etc/letsencrypt/live/$D   : ДА" || echo "traverse /etc/letsencrypt/live/$D   : НЕТ"
sudo -n -u "$WEBUSER" test -r "/etc/letsencrypt/live/$D/fullchain.pem" && echo "чтение fullchain.pem                : ДА" || echo "чтение fullchain.pem                : НЕТ  <<< ЭТО И ЕСТЬ ПРИЧИНА"
sudo -n -u "$WEBUSER" cat "/etc/letsencrypt/live/$D/fullchain.pem" >/dev/null 2>&1 && echo "cat fullchain.pem                   : ДА" || echo "cat fullchain.pem                   : НЕТ"

hr "4. ЧТО ВИДИТ PHP ВЕБ-ЮЗЕРА (тот же код, что и панель)"
sudo -n -u "$WEBUSER" test -r "$ROOT/core/ssl_manager.php" \
  && echo "core/ssl_manager.php читается веб-юзером : ДА" \
  || { echo "core/ssl_manager.php читается веб-юзером : НЕТ (права на каталог трекера!)"; ls -ld "$ROOT" "$ROOT/core" 2>&1; }
cat > /tmp/orbitra_ssl_probe.php <<'PHPPROBE'
<?php
$domain = $argv[1] ?? '';
$root   = $argv[2] ?? '';
$cert   = "/etc/letsencrypt/live/$domain/fullchain.pem";

echo "open_basedir (этот PHP)      : " . (ini_get('open_basedir') ?: '(не задан)') . "\n";
echo "shell_exec доступен          : " . (function_exists('shell_exec') ? 'да' : 'НЕТ') . "\n";
echo "disable_functions            : " . (ini_get('disable_functions') ?: '(пусто)') . "\n";
echo "--- прямые файловые проверки ---\n";
echo "file_exists()                : " . var_export(file_exists($cert), true) . "\n";
echo "is_file()                    : " . var_export(is_file($cert), true) . "\n";
echo "is_readable()                : " . var_export(is_readable($cert), true) . "\n";
$raw = @file_get_contents($cert);
echo "file_get_contents()          : " . ($raw === false ? 'FALSE (не прочитал)' : strlen($raw) . ' байт') . "\n";
echo "блоков BEGIN CERTIFICATE     : " . ($raw === false ? '0 (файл не прочитан!)' : substr_count($raw, '-----BEGIN CERTIFICATE-----')) . "\n";

if ($root !== '' && is_file("$root/core/ssl_manager.php")) {
    require_once "$root/core/ssl_manager.php";
    echo "--- функции самого Orbitra ---\n";
    $chain = orbitraCertificateChainComplete($cert);
    echo "orbitraCertificateChainComplete(): ok=" . var_export($chain['ok'], true) . " count=" . $chain['count'] . "\n";
    if ($chain['count'] === 0) {
        echo ">>> count=0 означает НЕ 'цепочка обрезана', а 'PHP не смог прочитать файл'.\n";
        echo ">>> Панель показывает для обоих случаев один и тот же incomplete_chain.\n";
    }
    $env = orbitraSslEnvironment();
    echo "orbitraSslEnvironment()      : can_issue=" . var_export($env['can_issue'], true)
       . " shell=" . var_export($env['shell'], true)
       . " certbot=" . var_export($env['certbot'], true)
       . " sudo_certbot=" . var_export($env['sudo_certbot'] ?? null, true)
       . " nginx_config=" . var_export($env['nginx_config'], true)
       . " acme_writable=" . var_export($env['acme_writable'], true) . "\n";
    echo "problems                     : " . (empty($env['problems']) ? '(нет)' : implode(', ', $env['problems'])) . "\n";
    echo "ACME webroot                 : " . ORBITRA_ACME_WEBROOT
       . " exists=" . var_export(is_dir(ORBITRA_ACME_WEBROOT), true)
       . " writable=" . var_export(is_writable(ORBITRA_ACME_WEBROOT), true) . "\n";
    if ($domain !== '') {
        $dns = orbitraDnsPreflightCheck($domain);
        echo "orbitraDnsPreflightCheck()   : valid=" . var_export($dns['valid'], true)
           . " code=" . ($dns['error_code'] ?? '-') . " " . ($dns['error_message'] ?? '') . "\n";
        echo "  детали: " . json_encode($dns['details'] ?? [], JSON_UNESCAPED_UNICODE) . "\n";
    }
} else {
    echo "(core/ssl_manager.php не найден по пути '$root' — функции Orbitra не проверены)\n";
}

$db = "$root/orbitra_db.sqlite";
echo "--- база: $db ---\n";
if (!is_readable($db)) {
    echo "база недоступна для чтения этим пользователем\n";
} else {
    try {
        $pdo = new PDO("sqlite:$db");
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $rows = $pdo->query("SELECT id, name, ssl_status, ssl_source, cloudflare_proxy, ssl_attempts, ssl_last_attempt, ssl_error FROM domains ORDER BY id DESC LIMIT 15")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $r) {
            printf("#%-4s %-32s status=%-12s source=%-16s cf=%s attempts=%s last=%s\n",
                $r['id'], substr((string)$r['name'],0,32), (string)$r['ssl_status'],
                (string)$r['ssl_source'], (string)$r['cloudflare_proxy'],
                (string)$r['ssl_attempts'], (string)$r['ssl_last_attempt']);
            if (!empty($r['ssl_error'])) {
                echo "      ssl_error: " . str_replace("\n", " | ", substr((string)$r['ssl_error'], 0, 400)) . "\n";
            }
        }
    } catch (Throwable $e) {
        echo "ошибка чтения базы: " . $e->getMessage() . "\n";
    }
}
PHPPROBE
chmod 644 /tmp/orbitra_ssl_probe.php
sudo -n -u "$WEBUSER" php /tmp/orbitra_ssl_probe.php "$D" "$ROOT" 2>&1

hr "5. open_basedir В КОНФИГЕ PHP-FPM (CLI и FPM читают разные ini)"
OB="$(grep -rns "open_basedir" /etc/php/*/fpm/ /etc/php-fpm.d/ /etc/php/*/fpm/pool.d/ /etc/opt/remi/php*/php-fpm.d/ 2>/dev/null | grep -vE '^[^:]+:[0-9]+:\s*;' | head -10)"
[ -n "$OB" ] && echo "$OB" || echo "(open_basedir в конфигах FPM не задан)"

hr "6. NGINX: КАКОЙ СЕРТИФИКАТ ОТДАЁТСЯ ДЛЯ $D"
if have nginx; then
  nginx -T 2>/dev/null | awk -v d="$D" '
    /server[ \t]*\{/ {buf=""; inblk=1}
    inblk {buf = buf $0 "\n"}
    $0 ~ ("server_name[^;]*" d) {hit=1}
    /^\s*\}/ && inblk {if (hit) {print buf; hit=0} inblk=0; buf=""}
  ' | grep -E "server_name|listen|ssl_certificate" | head -20
  echo "--- nginx -t ---"
  nginx -t 2>&1 | tail -3
else
  grep -n -A12 "server_name .*$D" /etc/nginx/sites-available/orbitra 2>/dev/null | grep -E "server_name|listen|ssl_certificate" | head -20
fi
grep -q "$D" /etc/nginx/sites-available/orbitra 2>/dev/null \
  || echo "(домена $D нет в /etc/nginx/sites-available/orbitra — конфиг не пересобирался под него)"

hr "7. ЖИВАЯ ПРОВЕРКА: ЧТО САЙТ РЕАЛЬНО ОТДАЁТ БРАУЗЕРУ"
if have openssl; then
  CHAIN=$(echo | timeout 15 openssl s_client -servername "$D" -connect "$D:443" -showcerts 2>/dev/null | grep -c 'BEGIN CERTIFICATE')
  echo "сертификатов в отданной цепочке: ${CHAIN:-0}  (норма: 2; 1 = Chrome ругается, 0 = не подключились)"
  echo | timeout 15 openssl s_client -servername "$D" -connect "$D:443" 2>/dev/null | grep -E "subject=|issuer=|Verify return code" | head -5
fi
have curl && { echo "--- curl ---"; curl -sS -o /dev/null -w 'http=%{http_code} ssl_verify=%{ssl_verify_result}\n' --max-time 15 "https://$D/" 2>&1 | head -2; }

hr "8. DNS"
have dig && echo "A-запись $D : $(dig +short A "$D" | tr '\n' ' ')" || echo "A-запись $D : $(getent hosts "$D" | awk '{print $1}' | tr '\n' ' ')"
echo "IP сервера   : $(curl -s --max-time 8 https://api.ipify.org 2>/dev/null || hostname -I | awk '{print $1}')"

hr "9. ЛОГИ"
echo "--- последние строки letsencrypt.log ---"
tail -n 40 /var/log/letsencrypt/letsencrypt.log 2>/dev/null | grep -viE 'debug|Requested|urn:ietf' | tail -20
echo "--- ssl_installer.log трекера ---"
tail -n 20 "$ROOT/var/logs/ssl_installer.log" 2>/dev/null || echo "(нет файла — воркер ни разу не писал)"
echo "--- крон веб-юзера ---"
sudo -n -u "$WEBUSER" crontab -l 2>/dev/null | grep -i orbitra || echo "(записи orbitra-ssl в кроне $WEBUSER нет)"

hr "10. ВЕРДИКТ (считается автоматически)"
ROOT_COUNT=0
[ -f "/etc/letsencrypt/live/$D/fullchain.pem" ] && ROOT_COUNT=$(grep -c 'BEGIN CERTIFICATE' "/etc/letsencrypt/live/$D/fullchain.pem" 2>/dev/null || echo 0)
PHP_COUNT=$(sudo -n -u "$WEBUSER" php -r '
$c = @file_get_contents("/etc/letsencrypt/live/" . $argv[1] . "/fullchain.pem");
echo $c === false ? 0 : substr_count($c, "-----BEGIN CERTIFICATE-----");
' "$D" 2>/dev/null || echo 0)

echo "сертификатов в fullchain.pem: root видит $ROOT_COUNT, PHP веб-юзера видит $PHP_COUNT"
echo
if [ "$ROOT_COUNT" -ge 2 ] && [ "$PHP_COUNT" -eq 0 ]; then
  cat <<'V1'
>>> ПОДТВЕРЖДЕНО: сертификат ВЫПУЩЕН И ЦЕЛ, цепочка полная.
>>> Панель врёт, потому что PHP веб-сервера физически не может прочитать
>>> /etc/letsencrypt — каталог root-only (или PHP-FPM ограничен open_basedir).
>>> orbitraCertificateChainComplete() получает count=0 и трактует это как
>>> "цепочка обрезана", хотя правильный вывод — "файл недоступен".
>>>
>>> Дополнительно: тот же file_exists() решает, какой блок писать в nginx
>>> (core/nginx_config.php:305), поэтому домен мог уехать на self-signed —
>>> смотрите раздел 6 и 7.
>>>
>>> Это ровно Баг 1 из docs/TZ_SSL_CHAIN_AND_PRIVACY.md, ветка 1.1.
V1
elif [ "$ROOT_COUNT" -eq 1 ]; then
  cat <<'V2'
>>> Цепочка ДЕЙСТВИТЕЛЬНО обрезана: в fullchain.pem один сертификат вместо двух.
>>> Это не баг чтения — чинить надо выпуск (Баг 1, задача 5 в ТЗ):
>>> перевыпуск с --preferred-chain, либо сборка fullchain из cert.pem + chain.pem.
V2
elif [ "$ROOT_COUNT" -eq 0 ]; then
  cat <<'V3'
>>> Сертификата на диске НЕТ вообще — certbot до выпуска не дошёл.
>>> Смотрите раздел 1 (certbot/sudoers), раздел 8 (DNS) и раздел 9 (логи):
>>> причина будет там, а не в проверке цепочки.
V3
elif [ "$ROOT_COUNT" -ge 2 ] && [ "$PHP_COUNT" -ge 2 ]; then
  cat <<'V4'
>>> Файл цел И читается PHP. Значит текущая ошибка НЕ из проверки цепочки —
>>> ищите причину в разделах 6 (nginx), 7 (что реально отдаётся) и 9 (логи),
>>> и пришлите вывод целиком: гипотеза с правами не подтвердилась.
V4
fi

rm -f /tmp/orbitra_ssl_probe.php
printf '\n===== КОНЕЦ. Отправьте весь вывод целиком. =====\n'
