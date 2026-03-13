# Orbitra v0.9.3.1 Tracker

![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)
![PHP Version](https://img.shields.io/badge/PHP-8.0+-777BB4?logo=php)
![React](https://img.shields.io/badge/React-19-61DAFB?logo=react)
![Vite](https://img.shields.io/badge/Vite-7-646CFF?logo=vite)
![SQLite](https://img.shields.io/badge/SQLite-3-003B57?logo=sqlite)
![Status](https://img.shields.io/badge/Status-Production_Ready-brightgreen)

Orbitra — современная система управления трафиком и отслеживания конверсий. Упрощённый и более быстрый аналог Keitaro Tracker с сохранением полной совместимости по API и функциональности.

## 🚀 Быстрая Установка (Ubuntu 20.04 / 22.04 / 24.04)

Для автоматической установки на чистый Linux сервер выполните команду:

```bash
wget -qO- https://raw.githubusercontent.com/fenjo26/Orbitra.link/main/install.sh | bash
```

Установщик автоматически:
- Скачает исходный код с GitHub
- Установит Nginx, PHP 8.3+, SQLite3
- Загрузит собранный фронтенд
- Настроит SSL сертификат Let's Encrypt для вашего домена

## ✨ Ключевые особенности

### 1. **Полная совместимость с Keitaro**
- **Click API с токенами** — полная совместимость с существующими интеграционными скриптами
- **Импорт из Keitaro** — миграция кампаний, офферов, доменов, потоков с сохранением токенов
- **API совместимость** — работа с существующими постбеками и webhook'ами

### 2. **Современная архитектура**
- **Backend**: PHP 8.3+ без тяжёлых фреймворков (чистый код)
- **Database**: SQLite 3 (один файл, автоматическое создание схемы)
- **Frontend**: React 19 + Vite 7 + Tailwind CSS 4
- **UI/UX**: Современный дизайн с тёмной/светлой темой

### 3. **Управление кампаниями**
- **6 моделей вознаграждения**: CPC, CPuC, CPM, CPA, CPS, RevShare
- **30+ параметров**: keyword, sub_id_1...30, cost, creative_id и др.
- **Сложная логика потоков**: Intercept → Regular → Fallback с весами и позициями
- **Расширенная фильтрация**: GEO, Device, OS, Browser, ISP, IP, Language, Referer
- **A/B тестирование**: встроенная поддержка сплит-тестов с ротацией по весам

### 4. **Интеграции**
- **S2S Postbacks** — Server-to-Server постбеки от партнерских сетей
- **Шаблоны партнерских сетей**: Leadbit, M4Leads, Dr.Cash, Everad, AdCombo и другие
- **Шаблоны источников**: Facebook, Google, TikTok, VK Ads, Яндекс
- **Click API** — токены для работы с интеграционными скриптами
- **Telegram Bot** — мониторинг и уведомления в реальном времени

### 5. **Аналитика и отчёты**
- **Dashboard** — агрегированная статистика по кликам, конверсиям, доходу
- **Trends** — детальная аналитика с графиками по 8 метрикам
- **Campaign Reports** — отчёты по кампаниям с группировкой по любым параметрам
- **Conversion Log** — детальный лог конверсий с фильтрами
- **Traffic Simulation** — симуляция кликов для тестирования потоков

### 6. **Мультиязычность**
- **Полная i18n поддержка**: Русский (RU) и Английский (EN)
- **1260+ ключей перевода** — все элементы интерфейса локализованы
- **Переключение языка** — в настройках профиля без перезагрузки страницы

### 7. **Telegram Bot**
- **10+ команд**: `/stats`, `/campaigns`, `/top`, `/conversions` и другие
- **Уведомления**: мгновенные уведомления о конверсиях
- **Ежедневная сводка**: автоматический отчёт по кампаниям
- **Мультиязычность**: бот поддерживает RU и EN

### 8. **Управление доменами**
- **DNS проверка** — автоматическая проверка A записи
- **HTTPS-only** — принудительный редирект на HTTPS
- **Защита от ботов** — перехват `/robots.txt` и `X-Robots-Tag`
- **Parking mode** — парковка доменов с защитой

### 9. **Миграция из Keitaro**
- **Полная миграция данных**: кампании, офферы, домены, потоки, партнерки, источники, лендинги
- **Сохранение токенов** — токены Click API переносятся для совместимости
- **Инструкция в UI** — пошаговая инструкция по созданию бекапа Keitaro
- **Preview mode** — предпросмотр перед реальным импортом

## 📁 Структура проекта

```
Orbitra/
├── api.php                    # REST API (60+ эндпоинтов)
├── index.php                  # Главный трекер (обработка кликов)
├── postback.php               # Обработчик постбеков
├── click.php                  # Click API
├── config.php                 # Конфигурация БД и миграции
├── database.sql               # Документация схемы БД
├── version.php                # Версия системы
├── router.php                 # PHP built-in server router
├── install.sh                 # Автоустановщик
├── .htaccess                  # Apache rewrite правила
│
├── core/                      # Модули системы
│   ├── keitaro_import.php     # Импорт из Keitaro
│   ├── click_api.php          # Click API реализация
│   ├── backorder.php          # Мониторинг доменов
│   └── SxGeo.php              # Geo IP база
│
├── frontend/                  # React + Vite фронтенд
│   ├── src/
│   │   ├── App.jsx           # Главный компонент с роутингом
│   │   ├── main.jsx          # Entry point
│   │   ├── components/       # 46 React компонентов
│   │   │   ├── Dashboard/    # Компоненты дашборда
│   │   │   ├── CampaignEditor.jsx    # Редактор кампаний (120KB)
│   │   │   ├── IntegrationsPage.jsx # Интеграции
│   │   │   ├── MigrationsPage.jsx   # Миграции и импорт
│   │   │   ├── ConversionsLog.jsx   # Лог конверсий
│   │   │   ├── CampaignReports.jsx  # Отчёты по кампаниям
│   │   │   └── ...               # Другие компоненты
│   │   ├── contexts/
│   │   │   └── LanguageContext.jsx  # i18n контекст
│   │   └── locales/
│   │       ├── en.js          # Английский (~1100 ключей)
│   │       └── ru.js          # Русский (~1260 ключей)
│   ├── package.json
│   ├── vite.config.js
│   └── index.html
│
├── docs/                      # Документация
│   ├── index.md              # Обзор документации
│   ├── architecture.md       # Архитектура и технологии
│   ├── features.md          # Описание функций
│   ├── api.md               # REST API документация
│   └── deployment.md        # Инструкции по деплою
│
├── landings/                  # Загруженные лендинги
└── vendor/                    # Composer зависимости
```

## 🚀 Быстрый старт для разработчиков

### Локальная разработка

```bash
# Клонирование репозитория
git clone https://github.com/fenjo26/Orbitra.link.git
cd Orbitra

# Установка PHP зависимостей
composer install --no-dev

# Установка Frontend зависимостей
cd frontend
npm install
npm run dev  # Запуск dev сервера (http://localhost:5173)
```

### Запуск Backend

```bash
# В корне проекта
php -S localhost:8080 router.php
```

### Продакшн сборка Frontend

```bash
cd frontend
npm run build
```

## 🔐 Данные для входа по умолчанию

- **URL**: `http://localhost:8080`
- **Логин**: `admin`
- **Пароль**: `admin`

> ⚠️ **Обязательно измените пароль после первого входа!**

## 📚 Документация

Полная документация доступна в папке [docs/](docs/):

- **[Обзор](docs/index.md)** — навигация по документации
- **[Архитектура](docs/architecture.md)** — технологический стек и структура БД
- **[Функции](docs/features.md)** — подробное описание возможностей
- **[API](docs/api.md)** — документация REST API
- **[Деплой](docs/deployment.md)** — инструкции по установке и настройке

## 🔌 Основные API Эндпоинты

### Миграция из Keitaro
- `POST ?action=keitaro_import_sql` — импорт дампа Keitaro

### Кампании
- `GET ?action=campaigns` — список кампаний
- `GET ?action=get_campaign&id=X` — данные кампании
- `POST ?action=save_campaign` — сохранение кампании
- `POST ?action=delete_campaign` — удаление кампании
- `GET ?action=campaign_report` — отчёт по кампании

### Аналитика
- `GET ?action=metrics` — агрегированная статистика
- `GET ?action=chart` — данные для графиков
- `GET ?action=trends` — детальная аналитика
- `GET ?action=conversions` — лог конверсий

### Интеграции
- `GET ?action=affiliate_networks` — партнерские сети
- `GET ?action=traffic_sources` — источники трафика
- `GET ?action=telegram_settings` — настройки Telegram бота

> 📖 **Полный список API**: см. [docs/api.md](docs/api.md)

## 🎯 Основные функции

### CampaignEditor
Полноэкранный редактор кампаний с вкладками:
- **Основные**: название, алиас, домен, источник
- **Финансы**: 6 моделей оплаты (CPC, CPuC, CPM, CPA, CPS, RevShare)
- **Параметры**: 30+ параметров (sub_id_1...30, keyword, cost и др.)
- **Интеграции**: готовые скрипты для Facebook, Google, TikTok, VK, Яндекс
- **S2S Postbacks**: настройка постбеков от партнерских сетей
- **Заметки**: текстовые заметки к кампании
- **Действия**: отчёты, лог конверсий, симуляция трафика

### Telegram Bot
**10 команд для мониторинга:**
- `/stats [period]` — статистика (today, 1d, 7d, 30d, yesterday)
- `/campaigns` — список кампаний с метриками
- `/campaign ID` — детальная статистика
- `/top` — ТОП-5 кампаний по доходу
- `/conversions` — последние 10 конверсий
- `/notify on|off` — уведомления о конверсиях
- `/daily on|off` — ежедневная сводка
- `/lang ru|en` — язык бота

### Симуляция трафика
Тестирование потоков и фильтров:
- **IP** — настройка IP адреса клика
- **User Agent** — настройка User-Agent
- **Страна** — выбор страны (US, RU, DE, GB, FR и др.)
- **Устройство** — desktop, mobile, tablet
- **Язык** — язык браузера (en, ru, de, fr, es, pt, zh)

## 📊 Модели вознаграждения

| Модель | Описание |
|--------|----------|
| **CPC** | Оплата за клик |
| **CPuC** | Оплата за уникальный клик |
| **CPM** | Оплата за 1000 показов |
| **CPA** | Оплата за действие (lead) |
| **CPS** | Оплата за продажу (sale) |
| **RevShare** | Процент от дохода |

## 🔄 Импорт из Keitaro

### Подготовка дампа Keitaro

На сервере Keitaro выполните:

```bash
# Подключитесь к Keitaro серверу
ssh root@YOUR_KEITARO_SERVER_IP

# Создайте дамп
bash -lc '
source /etc/keitaro/env/inventory.env

# Конфиг для подключения к БД
cat > /root/keitaro-mariadb.cnf <<EOF
[client]
user=$MARIADB_KEITARO_USER
password=$MARIADB_KEITARO_PASSWORD
host=127.0.0.1
port=3306
protocol=tcp
EOF
chmod 600 /root/keitaro-mariadb.cnf

# Создание дампа таблиц
TABLES="keitaro_affiliate_networks keitaro_groups keitaro_offers keitaro_domains keitaro_campaigns keitaro_campaign_postbacks keitaro_landings keitaro_streams keitaro_stream_filters keitaro_stream_offer_associations keitaro_stream_landing_associations keitaro_traffic_sources keitaro_ref_sources"

mysqldump --defaults-extra-file=/root/keitaro-mariadb.cnf \
  --single-transaction --quick --skip-lock-tables \
  "$MARIADB_KEITARO_DATABASE" $TABLES \
  | gzip > /root/keitaro_orbitra_full.sql.gz

ls -lah /root/keitaro_orbitra_full.sql.gz
'

# Скачайте файл
scp root@YOUR_KEITARO_SERVER_IP:/root/keitaro_orbitra_full.sql.gz .
```

### Импорт в Orbitra

1. Откройте **Миграции** в меню админки
2. Следуйте инструкции в блоке "Как создать бекап Keitaro"
3. Загрузите файл `keitaro_orbitra_full.sql.gz`
4. Выберите что импортировать (кампании, офферы, домены и т.д.)
5. Нажмите "Показать предпросмотр" для проверки
6. Нажмите "Импортировать в Orbitra" для реального импорта

## 🎨 Сustomization

### Темы оформления
Orbitra поддерживает автоматическую смену темы (светлая/тёмная) на основе настроек системы пользователя.

### Брендинг
Настройте логотип, цвета и название в **Настройки → Брендинг**.

### Язык интерфейса
Переключение языка в **Профиль → Настройки** (Русский/English).

## 🛠 Технологии

| Категория | Технология |
|-----------|------------|
| **Backend** | PHP 8.3+ |
| **Database** | SQLite 3 |
| **Frontend** | React 19.2.0 |
| **Build Tool** | Vite 7.3.1 |
| **UI Framework** | Tailwind CSS 4.2.0 |
| **Icons** | Lucide React 0.575.0 |
| **HTTP Client** | Axios 1.13.5 |
| **Charts** | Chart.js 4.5.1 |
| **Date Utils** | date-fns 3.6.0 |
| **PHP Deps** | Composer |

## 📝 Что нового в v0.9.3.1

### Добавлено
- ✨ Keitaro Migration UI с пошаговой инструкцией
- ✨ Click API токены для кампаний (совместимость с Keitaro)
- ✨ Кнопка копирования команды бекапа
- ✨ Campaign Reports с группировкой по параметрам
- ✨ Traffic Simulation с настройкой параметров клика
- ✨ Сохранение токенов при импорте из Keitaro
- ✨ Исправлена терминология (affiliate networks)
- ✨ Полная локализация модальных окон

### Исправлено
- 🐛 Исправлен `loadConversionLogs is not defined`
- 🐛 Исправлено позиционирование модальных окон (navbar не перекрывается)
- 🐛 Исправлены стили CampaignReports для единого дизайна

## 🤝 Участие в разработке

Мы приветствуем контрибьюции! Пожалуйста:

1. Forkните репозиторий
2. Создайте ветку для вашей фичи (`git checkout -b feature/AmazingFeature`)
3. Commitьте изменения (`git commit -m 'Add some AmazingFeature'`)
4. Pushните в ветку (`git push origin feature/AmazingFeature`)
5. Откройте Pull Request

## 📄 Лицензия

Этот проект лицензирован под MIT License - см. файл [LICENSE](LICENSE) для деталей.

## 📞 Поддержка

- **GitHub Issues**: https://github.com/fenjo26/Orbitra.link/issues
- **Документация**: [docs/](docs/)
- **Email**: support@orbitra.link

---

**Orbitra** — современный трекер для арбитражников и вебмастеров.

**Tags**: `tracker`, `affiliate-marketing`, `keitaro-alternative`, `php-tracker`, `react-admin`, `cpa-network`, `traffic-management`, `split-testing`, `conversion-tracking`
