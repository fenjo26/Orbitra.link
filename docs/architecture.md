# Архитектура и Технологии

Проект Orbitra построен на современной, но легковесной связке технологий, что позволяет ему работать быстро даже на слабых серверах (1-2 vCPU, 1-2 GB RAM).

## Технологический стек

### Backend
- **PHP 8.3+** — чистый код без тяжёлых фреймворков (Laravel, Symfony и т.д.)
- **SQLite 3** — лёгкая база данных в одном файле
- **Composer** — управление PHP зависимостями

### Frontend
- **React 19.2.0** — последний React с hooks и функциональными компонентами
- **Vite 7.3.1** — современный build tool для быстрой разработки
- **Tailwind CSS 4.2.0** — utility-first CSS фреймворк
- **Lucide React 0.575.0** — современная библиотека иконок
- **Axios 1.13.5** — HTTP клиент для API запросов
- **Chart.js 4.5.1** — библиотека для графиков
- **date-fns 3.6.0** — работа с датами и временем

### DevOps & Инфраструктура
- **Docker** — контейнеризация приложения
- **Docker Compose** — оркестрация контейнеров
- **Nginx** — обратный прокси и статические файлы
- **Let's Encrypt** — бесплатные SSL сертификаты
- **PHP built-in server** — для локальной разработки

### Система контроля версий
- **Git** — система контроля версий
- **GitHub** — хостинг репозитория и CI/CD

## Архитектура приложения

```
┌─────────────────────────────────────────────────────────────┐
│                         Browser                              │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  React 19 SPA (Vite 7)                              │  │
│  │  - Dashboard, Campaigns, Offers, Analytics          │  │
│  │  - 46 React Components                              │  │
│  │  - LanguageContext (i18n: RU/EN)                   │  │
│  │  - Axios HTTP Client                                │  │
│  └───────────────────┬──────────────────────────────────┘  │
│                      │ Axios (REST API)                     │
└──────────────────────┼──────────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────────┐
│                      Nginx                                 │
│  - Reverse Proxy                                          │
│  - Static Files (dist/)                                   │
│  - SSL Termination (Let's Encrypt)                        │
└──────────────────────┬──────────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────────┐
│                      PHP 8.3                                │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  api.php (REST API)                                 │  │
│  │  - 60+ endpoints                                    │  │
│  │  - Authentication & Sessions                        │  │
│  │  - CSRF Protection                                   │  │
│  └──────────────────────────────────────────────────────┘  │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  index.php (Main Tracker)                           │  │
│  │  - Click processing                                 │  │
│  │  - Stream selection                                 │  │
│  │  - Redirect to offers                               │  │
│  └──────────────────────────────────────────────────────┘  │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  postback.php (Postback Handler)                    │  │
│  │  - S2S postbacks from networks                      │  │
│  │  - Conversion tracking                              │  │
│  └──────────────────────────────────────────────────────┘  │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  telegram_bot.php (Telegram Bot)                    │  │
│  │  - Webhook handler                                  │  │
│  │  - 10+ commands                                    │  │
│  │  - Notifications                                    │  │
│  └──────────────────────────────────────────────────────┘  │
└──────────────────────┬──────────────────────────────────────┘
                       │
┌──────────────────────▼──────────────────────────────────────┐
│                    SQLite 3 Database                       │
│  ┌──────────────────────────────────────────────────────┐  │
│  │  Tables:                                            │  │
│  │  - campaigns, streams, offers, landings            │  │
│  │  - clicks, conversions                             │  │
│  │  - traffic_sources, affiliate_networks             │  │
│  │  - domains, users, settings                        │  │
│  │  - aggregator_connections, revenue_records          │  │
│  │  - ... 25+ tables total                            │  │
│  └──────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────┘
```

## База Данных (SQLite)

### Файл БД
- **Расположение**: `/orbitra_db.sqlite` (в корне проекта)
- **Авто-создание**: при первом запросе через `config.php`
- **Миграции**: система версионирования схем через PRAGMA `user_version`

### Основные таблицы

#### Кампании и потоки
- **`campaigns`** — рекламные кампании
  - `id`, `name`, `alias`, `token` (Click API)
  - `domain_id`, `group_id`, `source_id`
  - `cost_model`, `cost_value` (CPC, CPA, CPS, RevShare)
  - `uniqueness_method`, `uniqueness_hours`
  - `rotation_type` (weight/position)
  - `parameters_json` (30+ параметров)

- **`streams`** — потоки кампаний
  - `id`, `campaign_id`, `offer_id`, `name`
  - `weight`, `is_active`, `type` (intercept/regular/fallback)
  - `position`, `filters_json`, `schema_type`
  - `action_payload` (URL, landing_id, etc.)

#### Контент
- **`offers`** — офферы партнёрок
  - `id`, `name`, `url`, `payout_type`, `payout_value`
  - `geo`, `group_id`, `affiliate_network_id`

- **`landings`** — локальные лендинги
  - `id`, `name`, `folder_name`, `url`
  - `index_file`, `archive_id`

#### Статистика
- **`clicks`** — лог кликов
  - `id` (UUID), `campaign_id`, `offer_id`, `stream_id`
  - `ip`, `user_agent`, `referer`
  - `country`, `device_type`, `os`, `browser`, `language`
  - `sub_id_1`...`sub_id_5`, `keyword`, `cost`, `created_at`

- **`conversions`** — конверсии
  - `id`, `click_id`, `tid`, `status`, `payout`, `currency`
  - `sub_id_1`...`sub_id_5`, `created_at`, `updated_at`

#### Интеграции
- **`affiliate_networks`** — партнерские сети
  - `id`, `name`, `template`, `postback_url`
  - `offer_params`, `notes`

- **`traffic_sources`** — источники трафика
  - `id`, `name`, `template`, `postback_url`
  - `parameters_json`, `postback_statuses`

- **`campaign_postbacks`** — S2S постбеки кампаний
  - `id`, `campaign_id`, `url`, `method`
  - `status_filters_json`

#### Системные
- **`users`** — пользователи
  - `id`, `username`, `password` (bcrypt)
  - `role`, `permissions_json`
  - `language`, `timezone`, `api_key`

- **`domains`** — домены
  - `id`, `name`, `index_campaign_id`, `catch_404`
  - `https_only`, `is_noindex`

- **`settings`** — ключ-значение настройки
  - `key`, `value`, `updated_at`

#### Агрегатор
- **`aggregator_connections`** — подключения к API агрегаторов
  - `id`, `name`, `network_id`, `api_key`, `api_url`
  - `is_active`, `last_sync_at`

- **`revenue_records`** — записи о доходах
  - `id`, `connection_id`, `date`, `revenue`, `conversions`

### Связи между таблицами

```
campaigns (1) ───< (N) streams ───> (1) offers
    │
    ├──< (1) domains
    ├──< (1) groups
    └──< (1) traffic_sources

clicks (N) ───> (1) campaigns
    │
    └──> (1) conversions
```

## Frontend Архитектура

### Структура компонентов

```
src/
├── App.jsx                    # Главный компонент с роутингом
├── main.jsx                   # Entry point
├── index.css                  # Глобальные стили + CSS переменные
├── contexts/                  # React Context
│   └── LanguageContext.jsx    # i18n: RU/EN, useLanguage() hook
├── locales/                   # Переводы
│   ├── en.js                 # English (~1100 keys)
│   └── ru.js                 # Русский (~1260 keys)
└── components/                # 46 React компонентов
    ├── Layout/
    │   ├── Navbar.jsx         # Навигация
    │   └── Sidebar.jsx        # Боковая панель
    ├── Dashboard/
    │   ├── DashboardHeader.jsx
    │   ├── StatCards.jsx
    │   ├── MainChart.jsx
    │   └── DataTables.jsx
    ├── Campaigns/
    │   ├── Campaigns.jsx
    │   ├── CampaignEditor.jsx      # 120KB, главный редактор
    │   └── CampaignReports.jsx
    ├── Offers/
    │   ├── Offers.jsx
    │   └── OfferEditor.jsx
    ├── Settings/
    │   ├── Settings.jsx
    │   ├── ProfileSettings.jsx
    │   └── SystemSettings.jsx
    └── ... (40+ компонентов)
```

### Ключевые компоненты

#### CampaignEditor.jsx (120KB)
Главный редактор кампаний с 6 вкладками:
- **Основные**: название, алиас, домен, источник
- **Финансы**: 6 моделей оплаты (CPC, CPuC, CPM, CPA, CPS, RevShare)
- **Параметры**: 30+ параметров (sub_id_1...30, keyword, cost и др.)
- **Интеграции**: готовые скрипты для FB, Google, TikTok, VK, Яндекс
- **S2S Postbacks**: настройка постбеков
- **Заметки**: текстовые заметки

#### IntegrationsPage.jsx (89KB)
Управление интеграциями:
- Click API токены кампаний
- Telegram Bot настройки
- S2S Postbacks
- Шаблоны партнерских сетей
- Шаблоны источников трафика

#### MigrationsPage.jsx
Система миграций:
- Database migrations (версионирование схемы)
- Keitaro SQL импорт
- Инструкция по созданию бекапа
- Purge метаданных

### Состояние (State Management)

Отсутствие Redux — управление состоянием через:
- **React hooks**: `useState`, `useEffect`, `useContext`
- **Локальное состояние** в каждом компоненте
- **API вызовы** через Axios в `useEffect`

### Стилизация

- **CSS Variables** для темизации (светлая/тёмная тема)
- **Tailwind CSS 4.2.0** — utility-first классы
- **Кастомные компоненты** с инлайн стилями для динамических значений
- **Плавные анимации** переходов между страницами

## API Архитектура

### Единая точка входа
`api.php` — REST API для React фронтенда

### Аутентификация
- **Session-based** аутентификация
- **CSRF токены** для защиты
- **Rate limiting** для защиты от DDoS

### Категории API

1. **Auth & Users**: login, users, profile_settings
2. **Campaigns**: campaigns, save_campaign, delete_campaign
3. **Offers**: offers, save_offer, delete_offer
4. **Analytics**: metrics, chart, trends, conversions
5. **Settings**: settings, save_settings
6. **Integrations**: telegram_settings, aggregator_*
7. **System**: migrations, update, system_status

## Защита и Производительность

### Анти-дребезг (Debounce)
Встроенная защита от двойных кликов:
- Определение prefetch/prerender запросов
- Игнорирование дубликатов в течение короткого интервала
- Проверка User-Agent и Referer

### Защита от ботов
- **`bot_ips`** таблица — черный список IP
- **`bot_signatures`** таблица — сигнатуры User-Agent
- **Перехват `/robots.txt`** для паркованных доменов
- **Заголовок `X-Robots-Tag: noindex, nofollow`**

### Кеширование
- **Frontend**: Vite dev server с HMR
- **Backend**: SQLite с индексами
- **Static files**: Nginx с кешированием

## Производительность

### Оптимизация Backend
- **SQLite**: в памяти для часто запрашиваемых данных
- **Индексы**: на `campaign_id`, `click_id`, `created_at`
- **Пагинация**: для больших выборок (клики, конверсии)

### Оптимизация Frontend
- **Code splitting**: Vite автоматически разбивает код
- **Tree shaking**: удаление неиспользуемого кода
- **Lazy loading**: компонентов и роутов
- **Minification**: CSS и JS для продакшена

## Масштабируемость

### Минимальные требования
- **CPU**: 1 vCPU
- **RAM**: 1 GB
- **Disk**: 10 GB SSD
- **OS**: Ubuntu 20.04/22.04/24.04

### Рекомендуемые требования
- **CPU**: 2 vCPU
- **RAM**: 2-4 GB
- **Disk**: 20 GB SSD
- **OS**: Ubuntu 22.04 LTS

### Оценка производительности
- **Кликов в секунду**: 100-1000+ (зависит от сложности потоков)
- **Кампаний**: 1000+
- **Офферов**: 500+
- **Лендингов**: 200+

## Безопасность

### Аутентификация и Авторизация
- **bcrypt** для хеширования паролей
- **Session management** с CSRF защитой
- **Role-based access control** (admin, user, readonly)

### API Безопасность
- **Input validation** на всех эндпоинтах
- **SQL Injection protection** (prepared statements)
- **XSS protection** (эскейпинг вывода)
- **Rate limiting** для API запросов

### SSL/HTTPS
- **Let's Encrypt** — автоматические сертификаты
- **HSTS** — HTTP Strict Transport Security
- **Secure cookies** для сессий

## Мониторинг и Логи

### Системные логи
- **`system_logs`** — действия пользователей
- **`audit_logs`** — изменения критических данных
- **`s2s_postbacks_log`** — логи постбеков

### Telegram уведомления
- **Конверсии** — мгновенные уведомления
- **Ежедневная сводка** — автоматический отчёт
- **Алерты** — критические события системы

## Разработка

### Локальная разработка

```bash
# Terminal 1: Backend
php -S localhost:8080 router.php

# Terminal 2: Frontend
cd frontend
npm run dev  # HMR на localhost:5173
```

### Продакшн сборка

```bash
cd frontend
npm run build  # Собирает в dist/
```

### Отладка
- **Backend**: `error_log()` в PHP
- **Frontend**: React DevTools + Console
- **Database**: DB Browser for SQLite

---

*Архитектура обновлена для версии v0.9.3.1*
