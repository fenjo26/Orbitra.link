# Лендинги открываются без CSS и картинок — Инструкция по исправлению

## Проблема
Лендинги по адресу `/lander/<slug>/` загружаются как «голый» HTML — без стилей, шрифтов и изображений.

## Исправления в коде

### 1. ✅ Исправлен конфликт с дублирующим `<base>` тегом
**Файл:** `index.php` (строки ~754-760)

Раньше: код добавлял свой `<base>` тег даже если в HTML уже был свой.
Теперь: любой существующий `<base>` удаляется перед добавлением нового.

```php
// Удаляем существующий <base> перед добавлением своего
$html = preg_replace('/<base\s+[^>]*>/i', '', $html);
```

### 2. ✅ Добавлен режим отладки для статики лендингов
**Файл:** `index.php` (строки ~3-13)

Теперь можно включить диагностические заголовки для отладки проблем с загрузкой статики:

```php
// Способ 1: через переменную окружения
putenv('ORBITRA_LANDING_DEBUG=1');

// Способ 2: через GET-параметр в URL
// /lander/my-landing/?orbitra_debug_assets=1
```

При включении браузер будет возвращать заголовки:
- `X-Orbitra-Asset-Debug: 1` — режим отладки активен
- `X-Orbitra-Asset-File: /path/to/file.css` — полный путь к файлу
- `X-Orbitra-Asset-Internal: /_internal_assets/landings/1/style.css` — путь для nginx
- `X-Orbitra-Asset-Size: 12345` — размер файла
- `X-Orbitra-Asset-LandingId: 1` — ID лендинга

### 3. ✅ Добавлен fallback-режим при проблемах с nginx
**Файл:** `index.php` (строки ~620-640)

Если nginx не синхронизирован или работает на нестандартном порту без блока `/_internal_assets/`, можно включить fallback:

```php
// Включить через переменную окружения
putenv('ORBITRA_ASSET_FALLBACK=1');
```

В этом режиме PHP будет отдавать файлы напрямую (медленнее, но надёжнее).

### 4. ✅ Создан диагностический скрипт
**Файл:** `cli/check_landings.php`

Автоматически проверяет:
- Конфигурацию nginx (наличие `/_internal_assets/`)
- Права доступа к папке `landings/`
- Сокет PHP-FPM
- SSL сертификаты

---

## Инструкция по проверке на сервере

### Шаг 1. Запустите диагностический скрипт

```bash
sudo php /var/www/orbitra/cli/check_landings.php
```

Скрипт покажет:
- ✅ Что работает корректно
- ❌ Что требует исправления
- 💡 Команды для исправления

### Шаг 2. Исправьте права доступа (если нужно)

```bash
# Установите правильного владельца для папки лендингов
sudo chown -R www-data:www-data /var/www/orbitra/landings

# Установите правильные права
sudo chmod -R 755 /var/www/orbitra/landings
```

### Шаг 3. Синхронизируйте конфигурацию nginx

```bash
sudo php /var/www/orbitra/cli/nginx_sync.php
```

Эта команда:
- Пересоздаст `/etc/nginx/sites-available/orbitra`
- Добавит блок `location /_internal_assets/`
- Создаст самоподписанный сертификат для доступа по IP
- Перезагрузит nginx

### Шаг 4. Проверьте конфигурацию nginx

```bash
# Тест конфигурации
sudo nginx -t

# Если ошибки есть, исправьте их. Если OK — перезагрузите:
sudo systemctl reload nginx
```

### Шаг 5. Проверьте, что блок _internal_assets присутствует

```bash
grep -A 10 "_internal_assets" /etc/nginx/sites-enabled/orbitra
```

Должно быть видно:
```nginx
location /_internal_assets/ {
    internal;
    alias /var/www/orbitra/;
    location ~* \.(ico|png|jpg|jpeg|gif|bmp|webp|avif|svg|css|js|mjs|json|map|webmanifest|woff|woff2|ttf|otf|eot|mp4|webm|m4v|ogv|mp3|ogg|wav|m4a|txt|pdf)$ {
        expires 1h;
        add_header Cache-Control "public, immutable";
    }
    deny all;
}
```

### Шаг 6. Если вы на нестандартном порту (например, 8750)

Если трекер работает на порту 8750, убедитесь, что для этого порта тоже есть конфиг с `/_internal_assets/`:

```bash
# Проверьте все listen директивы
grep "listen" /etc/nginx/sites-enabled/orbitra

# Если порт 8750 используется отдельным конфигом, убедитесь,
# что в нём тоже есть блок location /_internal_assets/
```

Если порт 8750 настроен отдельно и не наследует общий конфиг — либо добавьте туда `/_internal_assets/` блок, либо настройте наследование.

---

## Отладка в браузере

### Способ 1. Включить глобальный режим отладки

Отредактируйте `index.php`, найдите строку:

```php
$orbitraLandingDebug = (filter_var(getenv('ORBITRA_LANDING_DEBUG') ?: 'false', FILTER_VALIDATE_BOOLEAN) ||
```

Замените на:

```php
$orbitraLandingDebug = true;  // Временно для отладки
```

### Способ 2. Добавить GET-параметр к URL лендинга

```
http://your-server:8750/lander/my-landing/?orbitra_debug_assets=1
```

### Просмотр заголовков в браузере

1. Откройте DevTools (F12)
2. Перейдите на вкладку **Network**
3. Перезагрузите страницу лендинга
4. Кликните на любой CSS/JS файл, который не загрузился
5. Посмотрите раздел **Response Headers**

Что искать:
- `X-Orbitra-Asset-Debug: 1` — режим отладки включён
- `X-Orbitra-Asset-File: ...` — показывает, какой файл пытается отдать PHP
- `X-Orbitra-Asset-Fallback: ...` — если включён fallback-режим

---

## Распространённые проблемы и решения

| Проблема | Решение |
|----------|---------|
| Все файлы возвращают 404 | Запустите `nginx_sync.php`, проверьте права на папку landings |
| CSS файлы загружаются, но пустые (0 байт) | Nginx не синхронизирован — запустите `nginx_sync.php` |
| Mixed Content ошибки (HTTP/HTTPS) | Используйте HTTPS для доступа к лендингу |
| Пути к файлам сломаны после обновления | Очистите кеш браузера (Ctrl+Shift+R) |
| Работает на одном порту, но не на другом | Проверьте конфиг nginx для проблемного порта |

---

## Краткий чек-лист для быстрого исправления

```bash
# 1. Диагностика
sudo php /var/www/orbitra/cli/check_landings.php

# 2. Исправление прав (если нужно)
sudo chown -R www-data:www-data /var/www/orbitra/landings
sudo chmod -R 755 /var/www/orbitra/landings

# 3. Синхронизация nginx
sudo php /var/www/orbitra/cli/nginx_sync.php

# 4. Перезагрузка nginx
sudo systemctl reload nginx

# 5. Проверка
curl -I http://localhost/lander/<slug>/style.css
```

---

## После выполнения всех шагов

1. Очистите кеш браузера (Ctrl+Shift+R)
2. Откройте лендинг
3. Проверьте в DevTools → Network, что CSS файлы загружаются с кодом 200

Если проблема остаётся:
1. Включите режим отладки (`?orbitra_debug_assets=1`)
2. Присыльте заголовки `X-Orbitra-Asset-*` из DevTools
3. Проверьте логи: `tail -f /var/www/orbitra/var/logs/php_errors.log`
