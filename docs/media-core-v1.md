# Media Core v1 — спецификация (медиа-ядро + пикер + галерея)

Владелец: сессия «Content Gallery». PWA-сессия — ПОТРЕБИТЕЛЬ пикера (иконка/скрины
с контрактом 512/192), она не создаёт своё хранилище (`uploads/pwa/` запрещён —
расщепление архитектуры). Статус синхронизации: память
`orbitra-content-gallery-assessment`.

## 1. Хранение

- Файлы: `uploads/media/<ab>/<hash12>.<ext>` — `<ab>` = первые 2 символа хэша
  (шардирование каталогов), `<hash12>` = 12 hex случайной соли + sha256-префикс
  содержимого. Имя всегда генерирует сервер — пользовательских путей нет.
- Публичный URL: `/uploads/media/<ab>/<hash12>.<ext>` (абсолютный путь от корня
  сайта; работает и из панели, и из публичных страниц — LeadForge/PWA).
- Раздача: реальный каталог → Apache отдаёт статикой напрямую (тот же механизм,
  что у `offers/<id>/`), dev `php -S` — через file_exists-guard в `router.php`.
  Анонимный доступ обязателен: картинки видны на публичных лендингах.
- Безопасность вместо PHP-роута раздачи (отклонение от первоначального контракта,
  осознанное): жёсткий whitelist расширений **webp, jpg, jpeg, png, gif** (SVG
  запрещён — активный контент), серверные имена, `getimagesize()` как жёсткий
  гейт (не-изображение отклоняется — сильнее строковых сканов), `.htaccess` в
  `uploads/` отключающий script execution + immutable-кэш. Лимит файла — 10 МБ.
- `.gitignore`: `/uploads/*` + `!/uploads/.gitkeep` — как `landings/`/`offers/`.

## 2. БД (миграция 44 + base DDL для свежих установок)

```sql
CREATE TABLE IF NOT EXISTS media_folders (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    name          TEXT NOT NULL,                -- ≤ 50 символов
    owner_user_id INTEGER,                      -- кто создал (аудит)
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP
);
CREATE TABLE IF NOT EXISTS media_assets (
    id            INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_user_id INTEGER,                      -- кто загрузил
    folder_id     INTEGER,                      -- NULL = корень
    orig_name     TEXT NOT NULL DEFAULT '',
    stored_name   TEXT NOT NULL,                -- <ab>/<hash12>.<ext>
    sha256        TEXT NOT NULL DEFAULT '',     -- задел под дедуп v1.5
    mime          TEXT NOT NULL DEFAULT '',
    size          INTEGER NOT NULL DEFAULT 0,
    width         INTEGER,
    height        INTEGER,
    is_active     INTEGER NOT NULL DEFAULT 1,   -- мягкое удаление
    created_at    DATETIME DEFAULT CURRENT_TIMESTAMP,
    deleted_at    DATETIME
);
CREATE INDEX IF NOT EXISTS idx_media_assets_folder ON media_assets(folder_id, is_active);
CREATE INDEX IF NOT EXISTS idx_media_assets_owner  ON media_assets(owner_user_id);
CREATE INDEX IF NOT EXISTS idx_media_assets_sha256 ON media_assets(sha256);
```

Миграция идемпотентна (CREATE IF NOT EXISTS в блоке `if ($schemaVersion < 44)`),
fresh installs получают те же таблицы из base DDL. `$LATEST_SCHEMA_VERSION = 44`
(43 занята PWA-сессией — landings.config_json + pwa_* биконы кликов).
ПРАВИЛО ГОНКИ: перед правкой config.php — `git status config.php`; кто первый
коммитит — тот и владеет номером; второй берёт следующий и перечитывает
`$LATEST_SCHEMA_VERSION` (43 забрала PWA-сессия, медиа-ядро взяло 44).

## 3. Модель доступа

- Новый ресурс `media` в `orbitraResourceAccessMap()` + вкладка в
  `TAB_PERMISSION_KEYS` + строка в модалке UsersPage + шаблоны ролей
  (media_buyer/video_editor — full, developer — read).
- Библиотека ОБЩАЯ (это командная история: дизайнер грузит — байеры берут):
  `read`/`full` видят все файлы; фильтр по пользователю — только админам.
- Мутации ФАЙЛОВ (move/delete/restore) — только владелец или админ
  (WHERE owner = me, частичный успех честно репортится: `updated`/`denied`).
  Папки — общие, управляются любым `full` (удаление папки НЕ destructive:
  файлы остаются, уходят в корень).

## 4. API (api.php, гейтится автоматически через resource map)

| Кейс | Метод | Вход | Выход |
|---|---|---|---|
| `media_list` | GET | `folder_id?`, `type=image`, `status=active\|inactive`, `user_id?` (админ), `q?`, `page` (50/стр) | `{items:[{id,orig_name,url,mime,size,width,height,folder_id,owner_user_id,owner_username,created_at}], total, page, pages, users?}` (users — админам) |
| `media_upload` | POST multipart | `files[]`, `folder_id?` | `{items:[...], failed:[{name,reason}]}` |
| `media_op` | POST JSON | `{op:'move'\|'delete'\|'restore', ids:[], folder_id?}` (move: `folder_id` null=корень) | `{updated, denied}` |
| `media_folders` | GET | — | `{items:[{id,name,asset_count}]}` (активные) |
| `media_folder_op` | POST JSON | `{op:'create'\|'rename'\|'delete', id?, name?}` | `{status, id?}` |

`media_upload`/`media_op`/`media_folder_op` → `write`; `media_list`/`media_folders` → `read`.
Ошибки — коды-ключи (`message` = `media.err_*`), не русские строки (урок i18n).

## 5. Контракт пикера (общий компонент `common/MediaPicker.jsx`) — ДЛЯ PWA-СЕССИИ

```jsx
<MediaPicker
    open={boolean}            // рендерится как modal-overlay (z:2000)
    onClose={() => {}}
    onSelect={({ id, url, width, height, mime }) => {}}  // ЕДИНСТВЕННЫЙ контракт результата
    accept=".webp,.jpg,.jpeg,.png,.gif"   // дефолт — картинки
    multiple={false}
    sizeContract={{ width: 512, height: 512, crop: true, label: '512×512' }}  // опционально
/>
```

- Выбор существующего ИЛИ загрузка на месте (drag&drop + клик, мультизагрузка).
- С `sizeContract` каждый ассет получает индикатор:
  🟢 точное совпадение WxH · 🟡 можно обрезать (хотя бы одна сторона ≥ требуемой) ·
  🔴 меньше контракта (выбор блокируется, причина — тултипом).
- С `crop: true` после загрузки и по кнопке «Обрезать» открывается кроп-оверлей
  (рамка drag/resize на canvas, «Применить и загрузить» / «Пропустить»).
  Результат кропа = НОВЫЙ ассет (оригинал сохраняется), onSelect получает его
  размеры и URL.
- PWA хранит `id` (в `landings.config_json`) и вшивает `url` в генерируемую
  статику. «URL-мир» — как LeadForge.

## 6. Потребители v1 (галерея)

- **OfferEditor / LandingEditor**: кнопка «Заменить из галереи» рядом с существующей
  «Replace image» → пикер → `fetch(url)` → blob → существующий `upload_offer_file` /
  `upload_landing_file` (`path=<текущий файл>`; расширение ассета должно совпадать
  с заменяемым — то же правило, что у обычной замены). ZIP-мир остаётся
  самодостаточным: в дерево оффера попадает КОПИЯ.
- **«Вставить изображение»** в тулбаре код-редактора (виден на .html/.htm):
  пикер → `codeEditorRef.insertText('<img src="{url}" alt="{orig_name}">')`.

## 7. Страница галереи (`GalleryPage.jsx`, вкладка `media`)

Первый слот сетки — загрузка (click + drag&drop, мультизагрузка, спиннер на время
аплоада). Ряд папок (создать/переименовать/удалить, клик = фильтр). Тулбар:
статус (Активные/Неактивные), тип (Все/Картинки), юзер (админ), поиск.
Выделение кликом (до 50): снять / Переместить / Удалить|Восстановить.
Пагинация 50/стр. Тема: только `var(--color-*)`, `page-*`/`form-*`/`btn` классы.

## 8. i18n

Все ключи в неймспейсе `media.*` (+ `nav.mediaGallery`) — сразу во все 7 локалей
(en, ru, uk, es, fr, de, zh). fr: NBSP перед `:`/`?` по стилю файла. Никаких
`t('media.x', 'русский фолбек')` — фолбеки не утекают (урок check:i18n).

## 9. Тесты (`tests/media_core_test.php`)

Извлечение функций из api.php + in-memory SQLite (паттерн active_campaign_guard_test):
whitelist расширений, getimagesize-гейт, генерация имён (нет пользовательских
путей), soft delete/restore, удаление папки → файлы в корень, owner-гейт мутаций
(чужой файл нельзя), media_list фильтры. HTTP-поверхность не тестируется в v1
(кейсы тонкие над функциями).

## 10. Не входит в v1

Дедуп по sha256 (колонка есть, логики нет), «где используется» (реестр ссылок),
виде (ffmpeg/exec недоступен на shared hosting), PWA-иконка и LeadForge
(потребители PWA-сессии / v1.5), теги, вложенные папки, интеграция с Archive-страницей.
