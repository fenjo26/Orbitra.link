# Документация Orbitra

Orbitra — self-hosted трекер трафика и конверсий: кампании и потоки, клоакинг, лендинги и PWA, постбеки, импорт расходов, отчёты. Работает на одном недорогом VPS (PHP + SQLite + Nginx) и совместим с Keitaro по Click API, токенам кампаний и шаблонам.

Документация актуальна для **v1.9.0**. Что поменялось в каждой версии — [CHANGELOG](../CHANGELOG.md).

```bash
wget -O orbitra-install.sh https://raw.githubusercontent.com/fenjo26/Orbitra.link/main/install.sh && bash orbitra-install.sh
```

## Гайды для новичков (по шагам, со скриншотами)

Впервые в Orbitra? Начните с **[Гайд для новичков: с чего начать](guide-start-here.md)**.

- [Первая кампания по шагам](guide-first-campaign.md) — домен, партнёрка, оффер, источник, кампания, тестовая ссылка, постбек
- [Подключение домена](guide-domains.md) · [Офферы и партнёрки](guide-offers-networks.md)
- [Постбеки: что написать менеджеру партнёрки](guide-postbacks.md)
- [Потоки: как распределяется трафик](guide-streams.md) · [Фильтры потоков](guide-filters.md)
- [Клоакинг для новичков](guide-cloaking.md) · [Лендинги](guide-landings.md)
- [PWA-приложения](guide-pwa.md) · [Пуши пользователям PWA](guide-push.md)
- [Галерея контента](guide-gallery.md) · [Креативы и их тестирование](guide-creatives.md)
- [Шпаргалка по метрикам](guide-metrics.md) · [Словарь сокращений](glossary.md)
- [LeadForge: лендинги с формой заказа](guide-leadforge.md) · [CRM и защита от шейва](guide-crm.md)
- [Гео-базы](guide-geo-databases.md) · [ИИ-ассистент (MCP)](guide-mcp.md)

## Начало работы

- [Быстрый старт: первая кампания](getting-started.md)
- [Установка и обновление](deployment.md)
- [Миграция из Keitaro](keitaro-migration.md)
- [Обзор возможностей](features.md)

## Трафик

- [Кампании и потоки](campaigns.md) — фильтры, схемы, ротация, тестовые ссылки
- [Клоакинг](cloaking.md) · [How the cloaker works](cloak-how-it-works.md) (EN)
- [Проверка браузера](browser-check.md) — боты, виртуалки и эмуляторы по противоречиям в браузере
- [Landing pages](landing-pages.md) (EN) — типы лендингов, `{offer}`, макросы, JS-адаптер, LeadForge
- [Tracking Client (PHP)](tracking-client-php.md) (EN) — `kclient.php`
- [PWA-лендинги и Web Push](pwa-push.md) · [PWA Funnel](pwa-funnel.md) (EN)
- [Галерея контента](content-gallery.md)
- [Записи сессий и карта кликов](session-recordings.md) — записи реальных визитов, карта кликов и скролла, лимиты хранения

## Конверсии и деньги

- [Постбеки и конверсии](postbacks.md) — входящие постбеки, статусы, S2S в источник
- [Расходы](costs.md) — Facebook, TikTok, Google Ads, агрегатор доходов
- [Facebook / Meta](facebook.md) (EN) — импорт расходов и Conversions API
- [Dolphin / Fbtool](dolphin-fbtool.md) — приём расходов через Keitaro Admin API
- [Источники трафика и партнёрские сети](sources-and-networks.md)
- [Affiliate network offer parameters](affiliate-network-parameters.md) (EN)

## Аналитика

- [Отчёты, логи и метрики](reports.md)
- [Записи сессий и карта кликов](session-recordings.md)

## Инфраструктура

- [Домены и SSL](domains.md)
- [Cloudflare](cloudflare.md) · [Cloudflare Full Strict SSL](CLOUDFLARE_FULL_STRICT_SSL.md) (EN)

## Управление и интеграции

- [Администрирование](administration.md) — пользователи, права, настройки, гео-базы, обновление
- [Telegram-бот](telegram.md)
- [MCP: ИИ-ассистент в трекере](mcp.md)
- [REST API](api.md) — авторизация и каталог всех действий

## Для разработчиков

- [Архитектура](architecture.md) — устройство, кроны, база, разработка, выпуск версии
- Технические задания и разборы: [нагрузка и SQLite](TZ_LOAD_PERFORMANCE.md), [SSL-цепочки и приватность](TZ_SSL_CHAIN_AND_PRIVACY.md), [Web Push](TZ_PWA_PUSH.md), [логи, гео, потоки](TZ_LOGS_GEO_STREAMS.md), [Media Core v1](media-core-v1.md), [LeadForge subid validation](leadforge-subid-validation.md) (EN)
- [Архив устаревших документов](archive/README.md)

## Поддержка

Вопросы и баги — [GitHub Issues](https://github.com/fenjo26/Orbitra.link/issues). В панели: **⚙ → Поддержка и отзывы**.
