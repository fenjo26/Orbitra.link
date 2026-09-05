<?php
/**
 * Telegram Bot Webhook Handler
 * Receives updates from Telegram and processes bot commands
 */
require_once __DIR__ . '/config.php';

// The bot quotes the running version in /start. config.php does not pull
// version.php in, so without this every welcome said the '0.9.2.9' fallback
// — which also made it impossible to tell from a chat whether a server was
// running this code at all.
if (!defined('ORBITRA_VERSION') && is_file(__DIR__ . '/version.php')) {
    require_once __DIR__ . '/version.php';
}

// Bot translations
function botText($lang, $key, $params = [])
{
    $version = defined('ORBITRA_VERSION') ? ORBITRA_VERSION : '0.9.2.9';
    $texts = [
        'ru' => [
            'welcome' => "🚀 *Добро пожаловать в Orbitra v{version} Bot!*\n\nЯ помогу отслеживать статистику ваших кампаний.\n\nДоступные команды:\n/stats — Статистика за сегодня\n/stats 7d — За последние 7 дней\n/campaigns — Активные кампании\n/campaign ID — Стата по кампании\n/top — ТОП-5 по доходу\n/conversions — Последние конверсии\n/notify on|off — Уведомления\n/daily on|off — Ежедневная сводка\n/lang en|ru|uk|es|zh|fr|de — Язык бота\n/help — Справка",
            'help' => "📖 *Доступные команды:*\n\n/stats — Статистика за сегодня\n/stats 1d|7d|30d — За период\n/stats yesterday — За вчера\n/campaigns — Список кампаний\n/campaign ID — Детали кампании\n/top — ТОП-5 кампаний\n/conversions — Последние 10 конверсий\n/notify on|off — Уведомления о конверсиях\n/daily on|off — Ежедневная сводка\n/lang en|ru|uk|es|zh|fr|de — Сменить язык",
            'stats_title' => "📊 *Статистика: {period}*",
            'clicks' => "Кликов",
            'unique_clicks' => "Уникальных",
            'conversions' => "Конверсий",
            'revenue' => "Доход",
            'cost' => "Расход",
            'profit' => "Профит",
            'roi' => "ROI",
            'cr' => "CR",
            'no_data' => "Нет данных за этот период.",
            'today' => "Сегодня",
            'yesterday' => "Вчера",
            'last_7d' => "7 дней",
            'last_30d' => "30 дней",
            'campaigns_title' => "📋 *Активные кампании:*",
            'no_campaigns' => "Нет активных кампаний.",
            'campaign_detail' => "📊 *Кампания: {name}*",
            'campaign_not_found' => "❌ Кампания не найдена.",
            'top_title' => "🏆 *ТОП-5 кампаний по доходу (сегодня):*",
            'no_top' => "Нет данных за сегодня.",
            'conversions_title' => "🔔 *Последние конверсии:*",
            'no_conversions' => "Нет конверсий.",
            'notify_on' => "✅ Уведомления о конверсиях *включены*.",
            'notify_off' => "🔕 Уведомления о конверсиях *отключены*.",
            'daily_on' => "✅ Ежедневная сводка *включена*.",
            'daily_off' => "🔕 Ежедневная сводка *отключена*.",
            'lang_set' => "✅ Язык установлен: *Русский*",
            'unknown' => "❓ Неизвестная команда. Используйте /help",
            'new_conversion' => "🔔 *Новая конверсия!*\n\n📊 Кампания: *{campaign}*\n📌 Статус: `{status}`\n💰 Сумма: *{payout} {currency}*\n🌍 Страна: {country}\n🕐 Время: {time}",
            'daily_summary' => "📊 *Ежедневная сводка — {date}*",
            'status' => "Статус",
            'payout' => "Выплата",
            'campaign' => "Кампания",
            'country' => "Страна",
            'sources_title' => "🌐 *Источники трафика:*",
            'sources_empty' => "📭 Нет источников с URL.\n\nДобавьте источники в панели Orbitra.",
            'sources_checking' => "🔄 Проверяю все источники...",
            'sources_summary' => "📊 Итого: {ok} OK, {errors} с ошибкой",
            'lang_prompt' => "🌐 Текущий язык: *{lang}*
Выберите язык:",
            'lang_invalid' => "❌ Неизвестный язык: {arg}.
Доступны: ru, en, uk, es, zh, fr, de",
            'notify_status_on' => "🔔 Уведомления о конверсиях сейчас *включены*.",
            'notify_status_off' => "🔔 Уведомления о конверсиях сейчас *отключены*.",
            'daily_status_on' => "📊 Ежедневная сводка сейчас *включена*.",
            'daily_status_off' => "📊 Ежедневная сводка сейчас *отключена*.",
            'btn_enable' => "✅ Включить",
            'btn_disable' => "🔕 Отключить",
            'daily_top' => "🏆 Топ кампаний:",
            'cmd_stats' => "📊 Статистика за сегодня / 7д / 30д",
            'cmd_campaigns' => "📋 Активные кампании",
            'cmd_campaign' => "🔍 Статистика кампании по ID",
            'cmd_top' => "🏆 ТОП-5 кампаний по доходу",
            'cmd_conversions' => "🔔 Последние конверсии",
            'cmd_notify' => "🔔 Уведомления о конверсиях on|off",
            'cmd_daily' => "📊 Ежедневная сводка on|off",
            'cmd_sources' => "🌐 Статус источников трафика",
            'cmd_lang' => "🌐 Сменить язык бота",
            'cmd_help' => "📖 Справка по командам",
            'kbd_hint' => "Выберите команду",
            'menu_stats' => "📊 Статистика",
            'menu_top' => "🏆 Топ",
            'menu_campaigns' => "📋 Кампании",
            'menu_conversions' => "🔔 Конверсии",
            'menu_notify' => "🛎 Уведомления",
            'menu_daily' => "📅 Сводка",
            'menu_lang' => "🌐 Язык",
            'menu_help' => "📖 Помощь",
        ],
        'en' => [
            'welcome' => "🚀 *Welcome to Orbitra v{version} Bot!*\n\nI'll help you track your campaign stats.\n\nAvailable commands:\n/stats — Today's statistics\n/stats 7d — Last 7 days\n/campaigns — Active campaigns\n/campaign ID — Campaign details\n/top — Top 5 by revenue\n/conversions — Recent conversions\n/notify on|off — Notifications\n/daily on|off — Daily summary\n/lang en|ru|uk|es|zh|fr|de — Bot language\n/help — Help",
            'help' => "📖 *Available commands:*\n\n/stats — Today's statistics\n/stats 1d|7d|30d — For a period\n/stats yesterday — Yesterday\n/campaigns — Campaign list\n/campaign ID — Campaign details\n/top — Top 5 campaigns\n/conversions — Last 10 conversions\n/notify on|off — Conversion notifications\n/daily on|off — Daily summary report\n/lang en|ru|uk|es|zh|fr|de — Change language",
            'stats_title' => "📊 *Statistics: {period}*",
            'clicks' => "Clicks",
            'unique_clicks' => "Unique",
            'conversions' => "Conversions",
            'revenue' => "Revenue",
            'cost' => "Cost",
            'profit' => "Profit",
            'roi' => "ROI",
            'cr' => "CR",
            'no_data' => "No data for this period.",
            'today' => "Today",
            'yesterday' => "Yesterday",
            'last_7d' => "7 days",
            'last_30d' => "30 days",
            'campaigns_title' => "📋 *Active campaigns:*",
            'no_campaigns' => "No active campaigns.",
            'campaign_detail' => "📊 *Campaign: {name}*",
            'campaign_not_found' => "❌ Campaign not found.",
            'top_title' => "🏆 *Top 5 campaigns by revenue (today):*",
            'no_top' => "No data for today.",
            'conversions_title' => "🔔 *Recent conversions:*",
            'no_conversions' => "No conversions.",
            'notify_on' => "✅ Conversion notifications *enabled*.",
            'notify_off' => "🔕 Conversion notifications *disabled*.",
            'daily_on' => "✅ Daily summary *enabled*.",
            'daily_off' => "🔕 Daily summary *disabled*.",
            'lang_set' => "✅ Language set: *English*",
            'unknown' => "❓ Unknown command. Use /help",
            'new_conversion' => "🔔 *New Conversion!*\n\n📊 Campaign: *{campaign}*\n📌 Status: `{status}`\n💰 Amount: *{payout} {currency}*\n🌍 Country: {country}\n🕐 Time: {time}",
            'daily_summary' => "📊 *Daily Summary — {date}*",
            'status' => "Status",
            'payout' => "Payout",
            'campaign' => "Campaign",
            'country' => "Country",
            'sources_title' => "🌐 *Traffic Sources:*",
            'sources_empty' => "📭 No sources with URL.\n\nAdd sources in Orbitra panel.",
            'sources_checking' => "🔄 Checking all sources...",
            'sources_summary' => "📊 Total: {ok} OK, {errors} with errors",
            'lang_prompt' => "🌐 Current language: *{lang}*
Pick a language:",
            'lang_invalid' => "❌ Unknown language: {arg}.
Available: ru, en, uk, es, zh, fr, de",
            'notify_status_on' => "🔔 Conversion notifications are currently *enabled*.",
            'notify_status_off' => "🔔 Conversion notifications are currently *disabled*.",
            'daily_status_on' => "📊 The daily summary is currently *enabled*.",
            'daily_status_off' => "📊 The daily summary is currently *disabled*.",
            'btn_enable' => "✅ Enable",
            'btn_disable' => "🔕 Disable",
            'daily_top' => "🏆 Top campaigns:",
            'cmd_stats' => "📊 Stats for today / 7d / 30d",
            'cmd_campaigns' => "📋 Active campaigns",
            'cmd_campaign' => "🔍 Campaign stats by ID",
            'cmd_top' => "🏆 Top 5 campaigns by revenue",
            'cmd_conversions' => "🔔 Recent conversions",
            'cmd_notify' => "🔔 Conversion notifications on|off",
            'cmd_daily' => "📊 Daily summary on|off",
            'cmd_sources' => "🌐 Traffic sources status",
            'cmd_lang' => "🌐 Change bot language",
            'cmd_help' => "📖 Command help",
            'kbd_hint' => "Pick a command",
            'menu_stats' => "📊 Stats",
            'menu_top' => "🏆 Top",
            'menu_campaigns' => "📋 Campaigns",
            'menu_conversions' => "🔔 Conversions",
            'menu_notify' => "🛎 Alerts",
            'menu_daily' => "📅 Daily",
            'menu_lang' => "🌐 Language",
            'menu_help' => "📖 Help",
        ],
        'uk' => [
            'welcome' => "🚀 *Ласкаво просимо до Orbitra v{version} Bot!*\n\nЯ допоможу відстежувати статистику ваших кампаній.\n\nДоступні команди:\n/stats — Статистика за сьогодні\n/stats 7d — За останні 7 днів\n/campaigns — Активні кампанії\n/campaign ID — Стата по кампанії\n/top — ТОП-5 за доходом\n/conversions — Останні конверсії\n/notify on|off — Сповіщення\n/daily on|off — Щоденне зведення\n/lang en|ru|uk|es|zh|fr|de — Мова бота\n/help — Довідка",
            'help' => "📖 *Доступні команди:*\n\n/stats — Статистика за сьогодні\n/stats 1d|7d|30d — За період\n/stats yesterday — За вчора\n/campaigns — Список кампаній\n/campaign ID — Деталі кампанії\n/top — ТОП-5 кампаній\n/conversions — Останні 10 конверсій\n/notify on|off — Сповіщення про конверсії\n/daily on|off — Щоденне зведення\n/lang en|ru|uk|es|zh|fr|de — Змінити мову",
            'stats_title' => "📊 *Статистика: {period}*",
            'clicks' => "Кліків",
            'unique_clicks' => "Унікальних",
            'conversions' => "Конверсій",
            'revenue' => "Дохід",
            'cost' => "Витрати",
            'profit' => "Профіт",
            'roi' => "ROI",
            'cr' => "CR",
            'no_data' => "Немає даних за цей період.",
            'today' => "Сьогодні",
            'yesterday' => "Вчора",
            'last_7d' => "7 днів",
            'last_30d' => "30 днів",
            'campaigns_title' => "📋 *Активні кампанії:*",
            'no_campaigns' => "Немає активних кампаній.",
            'campaign_detail' => "📊 *Кампанія: {name}*",
            'campaign_not_found' => "❌ Кампанію не знайдено.",
            'top_title' => "🏆 *ТОП-5 кампаній за доходом (сьогодні):*",
            'no_top' => "Немає даних за сьогодні.",
            'conversions_title' => "🔔 *Останні конверсії:*",
            'no_conversions' => "Немає конверсій.",
            'notify_on' => "✅ Сповіщення про конверсії *увімкнені*.",
            'notify_off' => "🔕 Сповіщення про конверсії *вимкнені*.",
            'daily_on' => "✅ Щоденне зведення *увімкнено*.",
            'daily_off' => "🔕 Щоденне зведення *вимкнено*.",
            'lang_set' => "✅ Мову встановлено: *Українська*",
            'unknown' => "❓ Невідома команда. Використовуйте /help",
            'new_conversion' => "🔔 *Нова конверсія!*\n\n📊 Кампанія: *{campaign}*\n📌 Статус: `{status}`\n💰 Сума: *{payout} {currency}*\n🌍 Країна: {country}\n🕐 Час: {time}",
            'daily_summary' => "📊 *Щоденне зведення — {date}*",
            'status' => "Статус",
            'payout' => "Виплата",
            'campaign' => "Кампанія",
            'country' => "Країна",
            'sources_title' => "🌐 *Джерела трафіку:*",
            'sources_empty' => "📭 Немає джерел з URL.\n\nДодайте джерела в панелі Orbitra.",
            'sources_checking' => "🔄 Перевіряю всі джерела...",
            'sources_summary' => "📊 Разом: {ok} OK, {errors} з помилкою",
            'lang_prompt' => "🌐 Поточна мова: *{lang}*
Виберіть мову:",
            'lang_invalid' => "❌ Невідома мова: {arg}.
Доступні: ru, en, uk, es, zh, fr, de",
            'notify_status_on' => "🔔 Сповіщення про конверсії зараз *увімкнені*.",
            'notify_status_off' => "🔔 Сповіщення про конверсії зараз *вимкнені*.",
            'daily_status_on' => "📊 Щоденне зведення зараз *увімкнено*.",
            'daily_status_off' => "📊 Щоденне зведення зараз *вимкнено*.",
            'btn_enable' => "✅ Увімкнути",
            'btn_disable' => "🔕 Вимкнути",
            'daily_top' => "🏆 Топ кампаній:",
            'cmd_stats' => "📊 Статистика за сьогодні / 7д / 30д",
            'cmd_campaigns' => "📋 Активні кампанії",
            'cmd_campaign' => "🔍 Статистика кампанії за ID",
            'cmd_top' => "🏆 ТОП-5 кампаній за доходом",
            'cmd_conversions' => "🔔 Останні конверсії",
            'cmd_notify' => "🔔 Сповіщення про конверсії on|off",
            'cmd_daily' => "📊 Щоденне зведення on|off",
            'cmd_sources' => "🌐 Статус джерел трафіку",
            'cmd_lang' => "🌐 Змінити мову бота",
            'cmd_help' => "📖 Довідка по командах",
            'kbd_hint' => "Виберіть команду",
            'menu_stats' => "📊 Статистика",
            'menu_top' => "🏆 Топ",
            'menu_campaigns' => "📋 Кампанії",
            'menu_conversions' => "🔔 Конверсії",
            'menu_notify' => "🛎 Повідомлення",
            'menu_daily' => "📅 Зведення",
            'menu_lang' => "🌐 Мова",
            'menu_help' => "📖 Довідка",
        ],
        'es' => [
            'welcome' => "🚀 *¡Bienvenido a Orbitra v{version} Bot!*\n\nTe ayudaré a seguir las estadísticas de tus campañas.\n\nComandos disponibles:\n/stats — Estadísticas de hoy\n/stats 7d — Últimos 7 días\n/campaigns — Campañas activas\n/campaign ID — Detalles de la campaña\n/top — Top 5 por ingresos\n/conversions — Conversiones recientes\n/notify on|off — Notificaciones\n/daily on|off — Resumen diario\n/lang en|ru|uk|es|zh|fr|de — Idioma del bot\n/help — Ayuda",
            'help' => "📖 *Comandos disponibles:*\n\n/stats — Estadísticas de hoy\n/stats 1d|7d|30d — Por período\n/stats yesterday — Ayer\n/campaigns — Lista de campañas\n/campaign ID — Detalles de la campaña\n/top — Top 5 campañas\n/conversions — Últimas 10 conversiones\n/notify on|off — Notificaciones de conversiones\n/daily on|off — Resumen diario\n/lang en|ru|uk|es|zh|fr|de — Cambiar idioma",
            'stats_title' => "📊 *Estadísticas: {period}*",
            'clicks' => "Clics",
            'unique_clicks' => "Únicos",
            'conversions' => "Conversiones",
            'revenue' => "Ingresos",
            'cost' => "Costo",
            'profit' => "Beneficio",
            'roi' => "ROI",
            'cr' => "CR",
            'no_data' => "No hay datos para este período.",
            'today' => "Hoy",
            'yesterday' => "Ayer",
            'last_7d' => "7 días",
            'last_30d' => "30 días",
            'campaigns_title' => "📋 *Campañas activas:*",
            'no_campaigns' => "No hay campañas activas.",
            'campaign_detail' => "📊 *Campaña: {name}*",
            'campaign_not_found' => "❌ Campaña no encontrada.",
            'top_title' => "🏆 *Top 5 campañas por ingresos (hoy):*",
            'no_top' => "No hay datos de hoy.",
            'conversions_title' => "🔔 *Conversiones recientes:*",
            'no_conversions' => "No hay conversiones.",
            'notify_on' => "✅ Notificaciones de conversiones *activadas*.",
            'notify_off' => "🔕 Notificaciones de conversiones *desactivadas*.",
            'daily_on' => "✅ Resumen diario *activado*.",
            'daily_off' => "🔕 Resumen diario *desactivado*.",
            'lang_set' => "✅ Idioma establecido: *Español*",
            'unknown' => "❓ Comando desconocido. Usa /help",
            'new_conversion' => "🔔 *¡Nueva conversión!*\n\n📊 Campaña: *{campaign}*\n📌 Estado: `{status}`\n💰 Importe: *{payout} {currency}*\n🌍 País: {country}\n🕐 Hora: {time}",
            'daily_summary' => "📊 *Resumen diario — {date}*",
            'status' => "Estado",
            'payout' => "Pago",
            'campaign' => "Campaña",
            'country' => "País",
            'sources_title' => "🌐 *Fuentes de tráfico:*",
            'sources_empty' => "📭 No hay fuentes con URL.\n\nAñade fuentes en el panel de Orbitra.",
            'sources_checking' => "🔄 Comprobando todas las fuentes...",
            'sources_summary' => "📊 Total: {ok} OK, {errors} con errores",
            'lang_prompt' => "🌐 Idioma actual: *{lang}*
Elige un idioma:",
            'lang_invalid' => "❌ Idioma desconocido: {arg}.
Disponibles: ru, en, uk, es, zh, fr, de",
            'notify_status_on' => "🔔 Las notificaciones de conversiones están *activadas*.",
            'notify_status_off' => "🔔 Las notificaciones de conversiones están *desactivadas*.",
            'daily_status_on' => "📊 El resumen diario está *activado*.",
            'daily_status_off' => "📊 El resumen diario está *desactivado*.",
            'btn_enable' => "✅ Activar",
            'btn_disable' => "🔕 Desactivar",
            'daily_top' => "🏆 Mejores campañas:",
            'cmd_stats' => "📊 Estadísticas de hoy / 7d / 30d",
            'cmd_campaigns' => "📋 Campañas activas",
            'cmd_campaign' => "🔍 Estadísticas de campaña por ID",
            'cmd_top' => "🏆 Top 5 campañas por ingresos",
            'cmd_conversions' => "🔔 Conversiones recientes",
            'cmd_notify' => "🔔 Notificaciones de conversiones on|off",
            'cmd_daily' => "📊 Resumen diario on|off",
            'cmd_sources' => "🌐 Estado de fuentes de tráfico",
            'cmd_lang' => "🌐 Cambiar idioma del bot",
            'cmd_help' => "📖 Ayuda de comandos",
            'kbd_hint' => "Elige un comando",
            'menu_stats' => "📊 Estadísticas",
            'menu_top' => "🏆 Top",
            'menu_campaigns' => "📋 Campañas",
            'menu_conversions' => "🔔 Conversiones",
            'menu_notify' => "🛎 Alertas",
            'menu_daily' => "📅 Resumen",
            'menu_lang' => "🌐 Idioma",
            'menu_help' => "📖 Ayuda",
        ],
        'zh' => [
            'welcome' => "🚀 *欢迎使用 Orbitra v{version} 机器人！*\n\n我将帮助您跟踪广告系列的统计数据。\n\n可用命令：\n/stats — 今日统计\n/stats 7d — 最近 7 天\n/campaigns — 活动中的广告系列\n/campaign ID — 广告系列详情\n/top — 收入前 5 名\n/conversions — 最近转化\n/notify on|off — 通知\n/daily on|off — 每日汇总\n/lang en|ru|uk|es|zh|fr|de — 机器人语言\n/help — 帮助",
            'help' => "📖 *可用命令：*\n\n/stats — 今日统计\n/stats 1d|7d|30d — 按周期\n/stats yesterday — 昨日\n/campaigns — 广告系列列表\n/campaign ID — 广告系列详情\n/top — 前 5 名广告系列\n/conversions — 最近 10 次转化\n/notify on|off — 转化通知\n/daily on|off — 每日汇总报告\n/lang en|ru|uk|es|zh|fr|de — 更改语言",
            'stats_title' => "📊 *统计：{period}*",
            'clicks' => "点击",
            'unique_clicks' => "独立",
            'conversions' => "转化",
            'revenue' => "收入",
            'cost' => "花费",
            'profit' => "利润",
            'roi' => "ROI",
            'cr' => "CR",
            'no_data' => "此期间无数据。",
            'today' => "今天",
            'yesterday' => "昨天",
            'last_7d' => "7 天",
            'last_30d' => "30 天",
            'campaigns_title' => "📋 *活动中的广告系列：*",
            'no_campaigns' => "没有活动中的广告系列。",
            'campaign_detail' => "📊 *广告系列：{name}*",
            'campaign_not_found' => "❌ 未找到广告系列。",
            'top_title' => "🏆 *按收入排名前 5 的广告系列（今日）：*",
            'no_top' => "今日无数据。",
            'conversions_title' => "🔔 *最近转化：*",
            'no_conversions' => "没有转化。",
            'notify_on' => "✅ 转化通知已*开启*。",
            'notify_off' => "🔕 转化通知已*关闭*。",
            'daily_on' => "✅ 每日汇总已*开启*。",
            'daily_off' => "🔕 每日汇总已*关闭*。",
            'lang_set' => "✅ 语言已设置：*中文*",
            'unknown' => "❓ 未知命令。请使用 /help",
            'new_conversion' => "🔔 *新转化！*\n\n📊 广告系列：*{campaign}*\n📌 状态：`{status}`\n💰 金额：*{payout} {currency}*\n🌍 国家：{country}\n🕐 时间：{time}",
            'daily_summary' => "📊 *每日汇总 — {date}*",
            'status' => "状态",
            'payout' => "支出",
            'campaign' => "广告系列",
            'country' => "国家",
            'sources_title' => "🌐 *流量来源：*",
            'sources_empty' => "📭 没有带 URL 的来源。\n\n请在 Orbitra 面板中添加来源。",
            'sources_checking' => "🔄 正在检查所有来源...",
            'sources_summary' => "📊 共计：{ok} 正常，{errors} 出错",
            'lang_prompt' => "🌐 当前语言：*{lang}*
请选择语言：",
            'lang_invalid' => "❌ 未知语言：{arg}。\n可用：ru, en, uk, es, zh, fr, de",
            'notify_status_on' => "🔔 转化通知当前已*开启*。",
            'notify_status_off' => "🔔 转化通知当前已*关闭*。",
            'daily_status_on' => "📊 每日汇总当前已*开启*。",
            'daily_status_off' => "📊 每日汇总当前已*关闭*。",
            'btn_enable' => "✅ 开启",
            'btn_disable' => "🔕 关闭",
            'daily_top' => "🏆 最佳广告系列：",
            'cmd_stats' => "📊 今日 / 7天 / 30天统计",
            'cmd_campaigns' => "📋 活动中的广告系列",
            'cmd_campaign' => "🔍 按 ID 查广告系列统计",
            'cmd_top' => "🏆 收入前 5 名广告系列",
            'cmd_conversions' => "🔔 最近转化",
            'cmd_notify' => "🔔 转化通知 on|off",
            'cmd_daily' => "📊 每日汇总 on|off",
            'cmd_sources' => "🌐 流量来源状态",
            'cmd_lang' => "🌐 更改机器人语言",
            'cmd_help' => "📖 命令帮助",
            'kbd_hint' => "选择命令",
            'menu_stats' => "📊 统计",
            'menu_top' => "🏆 前五",
            'menu_campaigns' => "📋 广告系列",
            'menu_conversions' => "🔔 转化",
            'menu_notify' => "🛎 通知",
            'menu_daily' => "📅 日报",
            'menu_lang' => "🌐 语言",
            'menu_help' => "📖 帮助",
        ],
        'fr' => [
            'welcome' => "🚀 *Bienvenue sur Orbitra v{version} Bot !*\n\nJe vais vous aider à suivre les statistiques de vos campagnes.\n\nCommandes disponibles :\n/stats — Statistiques du jour\n/stats 7d — 7 derniers jours\n/campaigns — Campagnes actives\n/campaign ID — Détails de la campagne\n/top — Top 5 par revenu\n/conversions — Conversions récentes\n/notify on|off — Notifications\n/daily on|off — Résumé quotidien\n/lang en|ru|uk|es|zh|fr|de — Langue du bot\n/help — Aide",
            'help' => "📖 *Commandes disponibles :*\n\n/stats — Statistiques du jour\n/stats 1d|7d|30d — Par période\n/stats yesterday — Hier\n/campaigns — Liste des campagnes\n/campaign ID — Détails de la campagne\n/top — Top 5 des campagnes\n/conversions — 10 dernières conversions\n/notify on|off — Notifications de conversions\n/daily on|off — Résumé quotidien\n/lang en|ru|uk|es|zh|fr|de — Changer de langue",
            'stats_title' => "📊 *Statistiques : {period}*",
            'clicks' => "Clics",
            'unique_clicks' => "Uniques",
            'conversions' => "Conversions",
            'revenue' => "Revenu",
            'cost' => "Coût",
            'profit' => "Profit",
            'roi' => "ROI",
            'cr' => "CR",
            'no_data' => "Aucune donnée pour cette période.",
            'today' => "Aujourd'hui",
            'yesterday' => "Hier",
            'last_7d' => "7 jours",
            'last_30d' => "30 jours",
            'campaigns_title' => "📋 *Campagnes actives :*",
            'no_campaigns' => "Aucune campagne active.",
            'campaign_detail' => "📊 *Campagne : {name}*",
            'campaign_not_found' => "❌ Campagne introuvable.",
            'top_title' => "🏆 *Top 5 des campagnes par revenu (aujourd'hui) :*",
            'no_top' => "Aucune donnée pour aujourd'hui.",
            'conversions_title' => "🔔 *Conversions récentes :*",
            'no_conversions' => "Aucune conversion.",
            'notify_on' => "✅ Notifications de conversions *activées*.",
            'notify_off' => "🔕 Notifications de conversions *désactivées*.",
            'daily_on' => "✅ Résumé quotidien *activé*.",
            'daily_off' => "🔕 Résumé quotidien *désactivé*.",
            'lang_set' => "✅ Langue définie : *Français*",
            'unknown' => "❓ Commande inconnue. Utilisez /help",
            'new_conversion' => "🔔 *Nouvelle conversion !*\n\n📊 Campagne : *{campaign}*\n📌 Statut : `{status}`\n💰 Montant : *{payout} {currency}*\n🌍 Pays : {country}\n🕐 Heure : {time}",
            'daily_summary' => "📊 *Résumé quotidien — {date}*",
            'status' => "Statut",
            'payout' => "Paiement",
            'campaign' => "Campagne",
            'country' => "Pays",
            'sources_title' => "🌐 *Sources de trafic :*",
            'sources_empty' => "📭 Aucune source avec URL.\n\nAjoutez des sources dans le panneau Orbitra.",
            'sources_checking' => "🔄 Vérification de toutes les sources...",
            'sources_summary' => "📊 Total : {ok} OK, {errors} en erreur",
            'lang_prompt' => "🌐 Langue actuelle : *{lang}*
Choisissez une langue :",
            'lang_invalid' => "❌ Langue inconnue : {arg}.\nDisponibles : ru, en, uk, es, zh, fr, de",
            'notify_status_on' => "🔔 Les notifications de conversions sont actuellement *activées*.",
            'notify_status_off' => "🔔 Les notifications de conversions sont actuellement *désactivées*.",
            'daily_status_on' => "📊 Le résumé quotidien est actuellement *activé*.",
            'daily_status_off' => "📊 Le résumé quotidien est actuellement *désactivé*.",
            'btn_enable' => "✅ Activer",
            'btn_disable' => "🔕 Désactiver",
            'daily_top' => "🏆 Meilleures campagnes :",
            'cmd_stats' => "📊 Stats du jour / 7j / 30j",
            'cmd_campaigns' => "📋 Campagnes actives",
            'cmd_campaign' => "🔍 Stats d'une campagne par ID",
            'cmd_top' => "🏆 Top 5 campagnes par revenu",
            'cmd_conversions' => "🔔 Conversions récentes",
            'cmd_notify' => "🔔 Notifications de conversions on|off",
            'cmd_daily' => "📊 Résumé quotidien on|off",
            'cmd_sources' => "🌐 État des sources de trafic",
            'cmd_lang' => "🌐 Changer la langue du bot",
            'cmd_help' => "📖 Aide des commandes",
            'kbd_hint' => "Choisissez une commande",
            'menu_stats' => "📊 Stats",
            'menu_top' => "🏆 Top",
            'menu_campaigns' => "📋 Campagnes",
            'menu_conversions' => "🔔 Conversions",
            'menu_notify' => "🛎 Alertes",
            'menu_daily' => "📅 Résumé",
            'menu_lang' => "🌐 Langue",
            'menu_help' => "📖 Aide",
        ],
        'de' => [
            'welcome' => "🚀 *Willkommen beim Orbitra v{version} Bot!*\n\nIch helfe dir, die Statistiken deiner Kampagnen zu verfolgen.\n\nVerfügbare Befehle:\n/stats — Statistik für heute\n/stats 7d — Letzte 7 Tage\n/campaigns — Aktive Kampagnen\n/campaign ID — Kampagnendetails\n/top — Top 5 nach Umsatz\n/conversions — Letzte Conversions\n/notify on|off — Benachrichtigungen\n/daily on|off — Tägliche Zusammenfassung\n/lang en|ru|uk|es|zh|fr|de — Bot-Sprache\n/help — Hilfe",
            'help' => "📖 *Verfügbare Befehle:*\n\n/stats — Statistik für heute\n/stats 1d|7d|30d — Für einen Zeitraum\n/stats yesterday — Gestern\n/campaigns — Kampagnenliste\n/campaign ID — Kampagnendetails\n/top — Top 5 Kampagnen\n/conversions — Letzte 10 Conversions\n/notify on|off — Conversion-Benachrichtigungen\n/daily on|off — Tägliche Zusammenfassung\n/lang en|ru|uk|es|zh|fr|de — Sprache ändern",
            'stats_title' => "📊 *Statistik: {period}*",
            'clicks' => "Klicks",
            'unique_clicks' => "Eindeutige",
            'conversions' => "Conversions",
            'revenue' => "Umsatz",
            'cost' => "Kosten",
            'profit' => "Gewinn",
            'roi' => "ROI",
            'cr' => "CR",
            'no_data' => "Keine Daten für diesen Zeitraum.",
            'today' => "Heute",
            'yesterday' => "Gestern",
            'last_7d' => "7 Tage",
            'last_30d' => "30 Tage",
            'campaigns_title' => "📋 *Aktive Kampagnen:*",
            'no_campaigns' => "Keine aktiven Kampagnen.",
            'campaign_detail' => "📊 *Kampagne: {name}*",
            'campaign_not_found' => "❌ Kampagne nicht gefunden.",
            'top_title' => "🏆 *Top 5 Kampagnen nach Umsatz (heute):*",
            'no_top' => "Keine Daten für heute.",
            'conversions_title' => "🔔 *Letzte Conversions:*",
            'no_conversions' => "Keine Conversions.",
            'notify_on' => "✅ Conversion-Benachrichtigungen *aktiviert*.",
            'notify_off' => "🔕 Conversion-Benachrichtigungen *deaktiviert*.",
            'daily_on' => "✅ Tägliche Zusammenfassung *aktiviert*.",
            'daily_off' => "🔕 Tägliche Zusammenfassung *deaktiviert*.",
            'lang_set' => "✅ Sprache eingestellt: *Deutsch*",
            'unknown' => "❓ Unbekannter Befehl. Verwende /help",
            'new_conversion' => "🔔 *Neue Conversion!*\n\n📊 Kampagne: *{campaign}*\n📌 Status: `{status}`\n💰 Betrag: *{payout} {currency}*\n🌍 Land: {country}\n🕐 Zeit: {time}",
            'daily_summary' => "📊 *Tägliche Zusammenfassung — {date}*",
            'status' => "Status",
            'payout' => "Auszahlung",
            'campaign' => "Kampagne",
            'country' => "Land",
            'sources_title' => "🌐 *Traffic-Quellen:*",
            'sources_empty' => "📭 Keine Quellen mit URL.\n\nFüge Quellen im Orbitra-Panel hinzu.",
            'sources_checking' => "🔄 Überprüfe alle Quellen...",
            'sources_summary' => "📊 Gesamt: {ok} OK, {errors} mit Fehler",
            'lang_prompt' => "🌐 Aktuelle Sprache: *{lang}*
Sprache wählen:",
            'lang_invalid' => "❌ Unbekannte Sprache: {arg}.\nVerfügbar: ru, en, uk, es, zh, fr, de",
            'notify_status_on' => "🔔 Conversion-Benachrichtigungen sind derzeit *aktiviert*.",
            'notify_status_off' => "🔔 Conversion-Benachrichtigungen sind derzeit *deaktiviert*.",
            'daily_status_on' => "📊 Die tägliche Zusammenfassung ist derzeit *aktiviert*.",
            'daily_status_off' => "📊 Die tägliche Zusammenfassung ist derzeit *deaktiviert*.",
            'btn_enable' => "✅ Aktivieren",
            'btn_disable' => "🔕 Deaktivieren",
            'daily_top' => "🏆 Top-Kampagnen:",
            'cmd_stats' => "📊 Statistik heute / 7T / 30T",
            'cmd_campaigns' => "📋 Aktive Kampagnen",
            'cmd_campaign' => "🔍 Kampagnenstatistik nach ID",
            'cmd_top' => "🏆 Top 5 Kampagnen nach Umsatz",
            'cmd_conversions' => "🔔 Letzte Conversions",
            'cmd_notify' => "🔔 Conversion-Benachrichtigungen on|off",
            'cmd_daily' => "📊 Tägliche Zusammenfassung on|off",
            'cmd_sources' => "🌐 Status der Traffic-Quellen",
            'cmd_lang' => "🌐 Bot-Sprache ändern",
            'cmd_help' => "📖 Befehlshilfe",
            'kbd_hint' => "Befehl wählen",
            'menu_stats' => "📊 Statistik",
            'menu_top' => "🏆 Top",
            'menu_campaigns' => "📋 Kampagnen",
            'menu_conversions' => "🔔 Conversions",
            'menu_notify' => "🛎 Benachrichtigungen",
            'menu_daily' => "📅 Zusammenfassung",
            'menu_lang' => "🌐 Sprache",
            'menu_help' => "📖 Hilfe",
        ]
    ];

    $text = $texts[$lang][$key] ?? $texts['en'][$key] ?? $key;
    $params['version'] = $version;
    foreach ($params as $k => $v) {
        $text = str_replace('{' . $k . '}', $v, $text);
    }
    return $text;
}

// Languages the bot speaks — same list as botText() below and the panel's
// locales. The names are the native endonyms: a language picker should read
// in the language it selects.
function orbitraTelegramLanguages(): array
{
    return [
        'ru' => 'Русский',
        'en' => 'English',
        'uk' => 'Українська',
        'es' => 'Español',
        'fr' => 'Français',
        'de' => 'Deutsch',
        'zh' => '中文',
    ];
}

// The command catalogue, in menu order. descriptions come from botText(), so
// the Telegram quick-command menu localizes with the bot; 'menu' is the
// human label the pinned keyboard shows for the same command (only the eight
// commands that make sense as one-tap buttons are pinned — /campaign needs an
// ID argument and /checksources is a rare action, both stay typed).
function orbitraTelegramCommands(): array
{
    return [
        ['command' => 'stats', 'key' => 'cmd_stats', 'menu' => 'menu_stats'],
        ['command' => 'campaigns', 'key' => 'cmd_campaigns', 'menu' => 'menu_campaigns'],
        ['command' => 'campaign', 'key' => 'cmd_campaign'],
        ['command' => 'top', 'key' => 'cmd_top', 'menu' => 'menu_top'],
        ['command' => 'conversions', 'key' => 'cmd_conversions', 'menu' => 'menu_conversions'],
        ['command' => 'notify', 'key' => 'cmd_notify', 'menu' => 'menu_notify'],
        ['command' => 'daily', 'key' => 'cmd_daily', 'menu' => 'menu_daily'],
        ['command' => 'sources', 'key' => 'cmd_sources'],
        ['command' => 'lang', 'key' => 'cmd_lang', 'menu' => 'menu_lang'],
        ['command' => 'help', 'key' => 'cmd_help', 'menu' => 'menu_help'],
    ];
}

/**
 * setMyCommands payloads for the default menu and every language Telegram
 * matches on. Pure data — the two callers (registerCommands here, the panel's
 * save_telegram_settings in api.php) each push it through their own API
 * helper, so no second copy of the descriptions can drift.
 */
function orbitraTelegramCommandPayloads(): array
{
    $payloads = [];
    foreach (array_merge(['default'], array_keys(orbitraTelegramLanguages())) as $scope) {
        $lang = $scope === 'default' ? 'en' : $scope;
        $commands = [];
        foreach (orbitraTelegramCommands() as $c) {
            $commands[] = ['command' => $c['command'], 'description' => botText($lang, $c['key'])];
        }
        $payloads[$scope] = ['commands' => $commands];
        if ($scope !== 'default') {
            $payloads[$scope]['language_code'] = $scope;
        }
    }
    return $payloads;
}

/** Push the localized quick-command menu to Telegram. Best effort. */
function orbitraTelegramRegisterCommands(string $token): array
{
    $results = [];
    foreach (orbitraTelegramCommandPayloads() as $scope => $params) {
        $results[$scope] = orbitraTelegramApi($token, 'setMyCommands', $params);
    }
    // The menu button next to the input field is the one-tap entry point to
    // that command list; clients default to it, but an explicit set makes the
    // quick commands visible even where an operator's client drifted.
    $results['menu_button'] = orbitraTelegramApi($token, 'setChatMenuButton', [
        'menu_button' => ['type' => 'commands'],
    ]);
    return $results;
}

/**
 * Escape the four entity characters of Telegram's legacy Markdown outside of
 * entities. Campaign names are operator input: "Pockets_Final" must arrive as
 * text, not as an accidental italic run that eats the message's own markers.
 */
function orbitraTelegramEscape(string $text): string
{
    return str_replace(
        ['\\', '_', '*', '[', '`'],
        ['\\\\', '\\_', '\\*', '\\[', '\\`'],
        $text
    );
}

/** Re-register the command menu at most once a day (the poller calls this). */
function orbitraTelegramMaybeRegisterCommands(PDO $pdo, string $token): void
{
    $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'telegram_commands_sent'");
    if ($stmt && $stmt->fetchColumn() === date('Y-m-d')) {
        return;
    }
    orbitraTelegramRegisterCommands($token);
    $pdo->prepare("INSERT OR REPLACE INTO settings (key, value) VALUES ('telegram_commands_sent', ?)")
        ->execute([date('Y-m-d')]);
}

/** Inline keyboard: one row of language buttons, callback lang:<code>. */
function orbitraLangKeyboard(): array
{
    $row = [];
    foreach (orbitraTelegramLanguages() as $code => $name) {
        $row[] = ['text' => $name, 'callback_data' => 'lang:' . $code];
    }
    // 4 + 3 reads better than one long strip on a phone.
    return ['inline_keyboard' => [array_slice($row, 0, 4), array_slice($row, 4)]];
}

/**
 * The pinned keyboard at the bottom of the chat — the visual menu. Buttons
 * carry readable localized labels ("📊 Статистика"), not slash syntax, so
 * there is nothing to memorize; orbitraTelegramResolveCommand() maps a tap
 * back to the command it stands for. is_persistent parks it at the input
 * field, resize_keyboard collapses it to compact rows.
 */
function orbitraReplyKeyboard(string $lang): array
{
    $labels = [];
    foreach (orbitraTelegramCommands() as $c) {
        if (!empty($c['menu'])) {
            $labels[] = ['text' => botText($lang, $c['menu'])];
        }
    }
    // 4 rows of two reads as a menu; a 2x4 wall of eight is cramped on phones.
    return [
        'keyboard' => [
            array_slice($labels, 0, 2),
            array_slice($labels, 2, 2),
            array_slice($labels, 4, 2),
            array_slice($labels, 6, 2),
        ],
        'is_persistent' => true,
        'resize_keyboard' => true,
        'input_field_placeholder' => botText($lang, 'kbd_hint'),
    ];
}

/**
 * What did the operator actually ask for? Typed input arrives as "/stats" or
 * "/stats@my_bot"; a pinned-keyboard tap arrives as the button's readable
 * label ("📊 Статистика"), matched against the chat's current language.
 * Returns the canonical "/command", or '' when nothing matches.
 */
function orbitraTelegramResolveCommand(string $text, string $lang): string
{
    $text = trim($text);
    if ($text === '') {
        return '';
    }
    if ($text[0] === '/') {
        // Typed input: the command is the first word only — everything after
        // it is the argument ("/lang qq" must resolve to /lang, not to the
        // unknown "/lang qq").
        $head = explode(' ', $text, 2)[0];
        return strtolower(preg_replace('/@[^@\s]+$/', '', $head));
    }
    foreach (orbitraTelegramCommands() as $c) {
        if (isset($c['menu']) && $text === botText($lang, $c['menu'])) {
            return '/' . $c['command'];
        }
    }
    return '';
}

/** Generic Bot API call. Returns the decoded response, or null on failure. */
function orbitraTelegramApi(string $token, string $method, array $params = [], int $timeout = 10): ?array
{
    // Test seam: the suite asserts outgoing payloads without touching the net.
    if (defined('ORBITRA_TELEGRAM_TEST_OUTBOX')) {
        $GLOBALS['orbitra_telegram_outbox'][] = ['method' => $method, 'params' => $params];
        return ['ok' => true, 'result' => true];
    }

    $url = "https://api.telegram.org/bot{$token}/{$method}";
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($params));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    $result = curl_exec($ch);
    // curl_close() deprecated in PHP 8.5 - resources are auto-freed
    return json_decode($result, true);
}

// Send message to Telegram
function sendTelegram($token, $chatId, $text, $parseMode = 'Markdown', ?array $replyMarkup = null)
{
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => $parseMode,
        'disable_web_page_preview' => true
    ];
    if ($replyMarkup !== null) {
        $data['reply_markup'] = $replyMarkup;
    }
    return orbitraTelegramApi($token, 'sendMessage', $data);
}

/**
 * Handle one Telegram update.
 *
 * Both entry points land here: the webhook below, and telegram_poll_cron.php,
 * which is how the bot works on an install that has no HTTPS for Telegram to
 * call back to. Keep every command in this one switch — a second copy in the
 * poller is how the two drift apart.
 *
 * Handles message updates and callback_query updates (the inline keyboards
 * for /start, /lang, /notify, /daily and /campaigns answer with those).
 *
 * Returns true when the update carried something this bot acts on.
 */
function orbitraTelegramProcessUpdate(PDO $pdo, string $botToken, array $update): bool
{
    if (isset($update['callback_query'])) {
        return orbitraTelegramProcessCallback($pdo, $botToken, $update['callback_query']);
    }

    if (!isset($update['message'])) {
        return false;
    }

    $message = $update['message'];
    if (!isset($message['chat']['id'])) {
        return false;
    }

    $chatId = (string)$message['chat']['id'];
    $text = trim($message['text'] ?? '');
    $username = $message['from']['username'] ?? '';
    $firstName = $message['from']['first_name'] ?? '';

    // Register/update chat and read the language it picked.
    $lang = orbitraTelegramChatLang($pdo, $chatId, $username, $firstName);

    // Parse command: typed "/stats 7d" or a pinned-keyboard label tap
    // ("📊 Статистика" — the whole text is the label, no argument).
    $command = orbitraTelegramResolveCommand($text, $lang);
    $arg = '';
    if ($command !== '' && $text[0] === '/') {
        $parts = explode(' ', $text, 2);
        $arg = trim($parts[1] ?? '');
    }

    switch ($command) {
        case '/start':
            sendTelegram($botToken, $chatId, botText($lang, 'welcome'), 'Markdown', orbitraLangKeyboard());
            // Pin the visual menu right away: /start is all the setup a new
            // chat needs.
            sendTelegram($botToken, $chatId, botText($lang, 'help'), 'Markdown', orbitraReplyKeyboard($lang));
            break;

        case '/help':
            // Re-pins the menu if the operator removed it.
            sendTelegram($botToken, $chatId, botText($lang, 'help'), 'Markdown', orbitraReplyKeyboard($lang));
            break;

        case '/stats':
            handleStats($pdo, $botToken, $chatId, $lang, $arg);
            break;

        case '/campaigns':
            handleCampaigns($pdo, $botToken, $chatId, $lang);
            break;

        case '/campaign':
            handleCampaignDetail($pdo, $botToken, $chatId, $lang, $arg);
            break;

        case '/top':
            handleTop($pdo, $botToken, $chatId, $lang);
            break;

        case '/conversions':
            handleConversions($pdo, $botToken, $chatId, $lang);
            break;

        case '/notify':
            handleNotify($pdo, $botToken, $chatId, $lang, $arg);
            break;

        case '/daily':
            handleDaily($pdo, $botToken, $chatId, $lang, $arg);
            break;

        case '/lang':
            handleLang($pdo, $botToken, $chatId, $lang, $arg);
            break;

        case '/sources':
            handleSources($pdo, $botToken, $chatId, $lang);
            break;

        case '/checksources':
            handleCheckSources($pdo, $botToken, $chatId, $lang);
            break;

        default:
            sendTelegram($botToken, $chatId, botText($lang, 'unknown'));
            break;
    }

    return true;
}

/**
 * A tap on one of the bot's inline buttons. Telegram keeps the message the
 * button was attached to, so every tap is a fresh conversation turn: answer
 * the query (stops the client's spinner) and act on the payload.
 */
function orbitraTelegramProcessCallback(PDO $pdo, string $botToken, array $cb): bool
{
    $chatId = (string)($cb['message']['chat']['id'] ?? '');
    $data = (string)($cb['data'] ?? '');
    if ($chatId === '' || $data === '') {
        return false;
    }

    orbitraTelegramApi($botToken, 'answerCallbackQuery', ['callback_query_id' => $cb['id'] ?? '']);

    $from = $cb['from'] ?? [];
    $lang = orbitraTelegramChatLang($pdo, $chatId, $from['username'] ?? '', $from['first_name'] ?? '');

    if (str_starts_with($data, 'lang:')) {
        $newLang = substr($data, 5);
        if (isset(orbitraTelegramLanguages()[$newLang])) {
            $pdo->prepare("UPDATE telegram_bot_chats SET language = ? WHERE chat_id = ?")->execute([$newLang, $chatId]);
            // The new keyboard replaces the pinned one, so the menu re-labels
            // in the chosen language on the same tap.
            sendTelegram($botToken, $chatId, botText($newLang, 'lang_set'), 'Markdown', orbitraReplyKeyboard($newLang));
        }
        return true;
    }

    if (str_starts_with($data, 'camp:')) {
        handleCampaignDetail($pdo, $botToken, $chatId, $lang, substr($data, 5));
        return true;
    }

    if ($data === 'notify' || $data === 'daily') {
        $column = $data === 'notify' ? 'notify_conversions' : 'notify_daily';
        $key = $data === 'notify' ? 'notify_on' : 'daily_on';
        $keyOff = $data === 'notify' ? 'notify_off' : 'daily_off';
        // Toggle from the button's own current state.
        $stmt = $pdo->prepare("SELECT {$column} FROM telegram_bot_chats WHERE chat_id = ?");
        $stmt->execute([$chatId]);
        $enabled = ((int)$stmt->fetchColumn()) ? 0 : 1;
        $pdo->prepare("UPDATE telegram_bot_chats SET {$column} = ? WHERE chat_id = ?")->execute([$enabled, $chatId]);
        sendTelegram($botToken, $chatId, botText($lang, $enabled ? $key : $keyOff));
        return true;
    }

    return true;
}

/** Register/refresh a chat and return the language it will be spoken to in. */
function orbitraTelegramChatLang(PDO $pdo, string $chatId, string $username, string $firstName): string
{
    $stmt = $pdo->prepare("INSERT OR IGNORE INTO telegram_bot_chats (chat_id, username, first_name) VALUES (?, ?, ?)");
    $stmt->execute([$chatId, $username, $firstName]);
    $pdo->prepare("UPDATE telegram_bot_chats SET username = ?, first_name = ?, is_active = 1 WHERE chat_id = ?")->execute([$username, $firstName, $chatId]);

    $stmt = $pdo->prepare("SELECT language FROM telegram_bot_chats WHERE chat_id = ?");
    $stmt->execute([$chatId]);
    $lang = $stmt->fetchColumn() ?: 'ru';
    return isset(orbitraTelegramLanguages()[$lang]) ? $lang : 'ru';
}

/**
 * /lang — without an argument shows the current language and a one-tap
 * picker; a misspelled argument is an error, not a silent reset to Russian
 * (which is what the old default-branch did).
 */
function handleLang($pdo, $token, $chatId, $lang, $arg)
{
    $arg = strtolower(trim($arg));
    if ($arg === '') {
        $msg = botText($lang, 'lang_prompt', ['lang' => orbitraTelegramLanguages()[$lang]]);
        sendTelegram($token, $chatId, $msg, 'Markdown', orbitraLangKeyboard());
        return;
    }
    if (!isset(orbitraTelegramLanguages()[$arg])) {
        sendTelegram($token, $chatId, botText($lang, 'lang_invalid', ['arg' => $arg]));
        return;
    }
    $pdo->prepare("UPDATE telegram_bot_chats SET language = ? WHERE chat_id = ?")->execute([$arg, $chatId]);
    // lang_set doubles as the re-pin: the new keyboard replaces the old one,
    // so the menu labels and placeholder switch to the chosen language.
    sendTelegram($token, $chatId, botText($arg, 'lang_set'), 'Markdown', orbitraReplyKeyboard($arg));
}

/**
 * /notify and /daily share one shape: no argument answers with the current
 * state and a toggle button, on|off set it explicitly. The old handler read
 * a bare /notify as "off" — an easy way to unsubscribe by accident.
 */
function handleNotify($pdo, $token, $chatId, $lang, $arg)
{
    orbitraHandleToggle($pdo, $token, $chatId, $lang, $arg, 'notify_conversions', 'notify');
}

function handleDaily($pdo, $token, $chatId, $lang, $arg)
{
    orbitraHandleToggle($pdo, $token, $chatId, $lang, $arg, 'notify_daily', 'daily');
}

function orbitraHandleToggle($pdo, $token, $chatId, $lang, $arg, string $column, string $name)
{
    $onKey = $name === 'notify' ? 'notify_on' : 'daily_on';
    $offKey = $name === 'notify' ? 'notify_off' : 'daily_off';
    $statusKey = $name === 'notify' ? 'notify_status' : 'daily_status';

    $stmt = $pdo->prepare("SELECT {$column} FROM telegram_bot_chats WHERE chat_id = ?");
    $stmt->execute([$chatId]);
    $current = (int)((bool)$stmt->fetchColumn());

    $val = strtolower(trim($arg));
    if ($val === 'on' || $val === '1') {
        $new = 1;
    } elseif ($val === 'off' || $val === '0') {
        $new = 0;
    } else {
        // No or unknown argument: report the state, offer the toggle.
        $msg = botText($lang, $current ? $statusKey . '_on' : $statusKey . '_off');
        $btn = $current ? 'btn_disable' : 'btn_enable';
        sendTelegram($token, $chatId, $msg, 'Markdown', [
            'inline_keyboard' => [[['text' => botText($lang, $btn), 'callback_data' => $name]]],
        ]);
        return;
    }

    $pdo->prepare("UPDATE telegram_bot_chats SET {$column} = ? WHERE chat_id = ?")->execute([$new, $chatId]);
    sendTelegram($token, $chatId, botText($lang, $new ? $onKey : $offKey));
}

// === Webhook entry point ===
// telegram_poll_cron.php requires this file for the handler above and defines
// ORBITRA_TELEGRAM_NO_WEBHOOK first, so the request-scoped code below stays out
// of the CLI path.
if (!defined('ORBITRA_TELEGRAM_NO_WEBHOOK') && PHP_SAPI !== 'cli') {
    $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'telegram_bot_token'");
    $botToken = $stmt ? $stmt->fetchColumn() : '';

    if (!$botToken) {
        http_response_code(200);
        die('No token configured');
    }

    $update = json_decode(file_get_contents('php://input'), true);
    if (is_array($update)) {
        orbitraTelegramProcessUpdate($pdo, $botToken, $update);
    }

    http_response_code(200);
    echo 'ok';
}

// === Command Handlers ===

function handleStats($pdo, $token, $chatId, $lang, $period)
{
    $period = strtolower(trim($period));

    // Determine date range
    $now = new DateTime();
    switch ($period) {
        case 'yesterday':
            $from = (clone $now)->modify('-1 day')->format('Y-m-d 00:00:00');
            $to = (clone $now)->modify('-1 day')->format('Y-m-d 23:59:59');
            $label = botText($lang, 'yesterday');
            break;
        case '7d':
            $from = (clone $now)->modify('-7 days')->format('Y-m-d 00:00:00');
            $to = $now->format('Y-m-d 23:59:59');
            $label = botText($lang, 'last_7d');
            break;
        case '30d':
            $from = (clone $now)->modify('-30 days')->format('Y-m-d 00:00:00');
            $to = $now->format('Y-m-d 23:59:59');
            $label = botText($lang, 'last_30d');
            break;
        default: // today
            $from = $now->format('Y-m-d 00:00:00');
            $to = $now->format('Y-m-d 23:59:59');
            $label = botText($lang, 'today');
            break;
    }

    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as clicks,
            COUNT(DISTINCT ip) as unique_clicks,
            SUM(is_conversion) as conversions,
            SUM(revenue) as revenue,
            SUM(cost) as cost
        FROM clicks 
        WHERE created_at BETWEEN ? AND ?
    ");
    $stmt->execute([$from, $to]);
    $data = $stmt->fetch();

    if (!$data || $data['clicks'] == 0) {
        sendTelegram($token, $chatId, botText($lang, 'no_data'));
        return;
    }

    $clicks = (int)$data['clicks'];
    $unique = (int)$data['unique_clicks'];
    $conv = (int)$data['conversions'];
    $rev = number_format((float)$data['revenue'], 2);
    $costVal = number_format((float)$data['cost'], 2);
    $profit = number_format((float)$data['revenue'] - (float)$data['cost'], 2);
    $cr = $clicks > 0 ? number_format(($conv / $clicks) * 100, 2) : '0.00';
    $roi = (float)$data['cost'] > 0 ? number_format((((float)$data['revenue'] - (float)$data['cost']) / (float)$data['cost']) * 100, 1) : '∞';

    $msg = botText($lang, 'stats_title', ['period' => $label]) . "\n\n";
    $msg .= "👆 " . botText($lang, 'clicks') . ": *{$clicks}* ({$unique} " . botText($lang, 'unique_clicks') . ")\n";
    $msg .= "🎯 " . botText($lang, 'conversions') . ": *{$conv}*\n";
    $msg .= "💰 " . botText($lang, 'revenue') . ": *\${$rev}*\n";
    $msg .= "💸 " . botText($lang, 'cost') . ": *\${$costVal}*\n";
    $msg .= "📈 " . botText($lang, 'profit') . ": *\${$profit}*\n";
    $msg .= "📊 " . botText($lang, 'cr') . ": *{$cr}%* | " . botText($lang, 'roi') . ": *{$roi}%*";

    sendTelegram($token, $chatId, $msg);
}

function handleCampaigns($pdo, $token, $chatId, $lang)
{
    $today = date('Y-m-d');
    $stmt = $pdo->query("
        SELECT c.id, c.name, c.alias,
            (SELECT COUNT(*) FROM clicks WHERE campaign_id = c.id AND DATE(created_at) = '{$today}') as clicks,
            (SELECT SUM(revenue) FROM clicks WHERE campaign_id = c.id AND DATE(created_at) = '{$today}') as revenue,
            (SELECT SUM(is_conversion) FROM clicks WHERE campaign_id = c.id AND DATE(created_at) = '{$today}') as conv
        FROM campaigns c 
        WHERE c.is_archived = 0
        ORDER BY clicks DESC
        LIMIT 20
    ");
    $campaigns = $stmt->fetchAll();

    if (empty($campaigns)) {
        sendTelegram($token, $chatId, botText($lang, 'no_campaigns'));
        return;
    }

    $msg = botText($lang, 'campaigns_title') . "\n\n";
    foreach ($campaigns as $i => $c) {
        $clicks = (int)$c['clicks'];
        $rev = number_format((float)($c['revenue'] ?? 0), 2);
        $conv = (int)($c['conv'] ?? 0);
        $num = $i + 1;
        $msg .= "*{$num}.* `[{$c['id']}]` " . orbitraTelegramEscape($c['name']) . "\n";
        $msg .= "   👆 {$clicks} | 🎯 {$conv} | 💰 \${$rev}\n\n";
    }

    // The first ten campaigns become buttons: tapping one opens the detail
    // view without the operator retyping the ID. One button per row — two
    // columns of truncated campaign names read worse than a list.
    $keyboard = ['inline_keyboard' => []];
    foreach (array_slice($campaigns, 0, 10) as $c) {
        $label = "[{$c['id']}] " . mb_substr($c['name'], 0, 24);
        $keyboard['inline_keyboard'][] = [['text' => $label, 'callback_data' => 'camp:' . $c['id']]];
    }
    sendTelegram($token, $chatId, $msg, 'Markdown', $keyboard);
}

function handleCampaignDetail($pdo, $token, $chatId, $lang, $campaignId)
{
    $campaignId = (int)trim($campaignId);
    if (!$campaignId) {
        sendTelegram($token, $chatId, botText($lang, 'campaign_not_found'));
        return;
    }

    $stmt = $pdo->prepare("SELECT id, name, alias FROM campaigns WHERE id = ?");
    $stmt->execute([$campaignId]);
    $campaign = $stmt->fetch();

    if (!$campaign) {
        sendTelegram($token, $chatId, botText($lang, 'campaign_not_found'));
        return;
    }

    $today = date('Y-m-d');
    $stmt = $pdo->prepare("
        SELECT 
            COUNT(*) as clicks,
            COUNT(DISTINCT ip) as unique_clicks,
            SUM(is_conversion) as conversions,
            SUM(revenue) as revenue,
            SUM(cost) as cost
        FROM clicks 
        WHERE campaign_id = ? AND DATE(created_at) = ?
    ");
    $stmt->execute([$campaignId, $today]);
    $data = $stmt->fetch();

    $clicks = (int)$data['clicks'];
    $unique = (int)$data['unique_clicks'];
    $conv = (int)$data['conversions'];
    $rev = number_format((float)$data['revenue'], 2);
    $costVal = number_format((float)$data['cost'], 2);
    $profit = number_format((float)$data['revenue'] - (float)$data['cost'], 2);
    $cr = $clicks > 0 ? number_format(($conv / $clicks) * 100, 2) : '0.00';

    $msg = botText($lang, 'campaign_detail', ['name' => orbitraTelegramEscape($campaign['name'])]) . "\n";
    $msg .= "🔗 Alias: `{$campaign['alias']}`\n\n";
    $msg .= "👆 " . botText($lang, 'clicks') . ": *{$clicks}* ({$unique})\n";
    $msg .= "🎯 " . botText($lang, 'conversions') . ": *{$conv}*\n";
    $msg .= "💰 " . botText($lang, 'revenue') . ": *\${$rev}*\n";
    $msg .= "💸 " . botText($lang, 'cost') . ": *\${$costVal}*\n";
    $msg .= "📈 " . botText($lang, 'profit') . ": *\${$profit}*\n";
    $msg .= "📊 " . botText($lang, 'cr') . ": *{$cr}%*";

    sendTelegram($token, $chatId, $msg);
}

function handleTop($pdo, $token, $chatId, $lang)
{
    $today = date('Y-m-d');
    $stmt = $pdo->query("
        SELECT c.id, c.name,
            COUNT(*) as clicks,
            SUM(cl.is_conversion) as conv,
            SUM(cl.revenue) as revenue
        FROM clicks cl
        JOIN campaigns c ON c.id = cl.campaign_id
        WHERE DATE(cl.created_at) = '{$today}'
        GROUP BY c.id
        ORDER BY revenue DESC
        LIMIT 5
    ");
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        sendTelegram($token, $chatId, botText($lang, 'no_top'));
        return;
    }

    $medals = ['🥇', '🥈', '🥉', '4️⃣', '5️⃣'];
    $msg = botText($lang, 'top_title') . "\n\n";
    foreach ($rows as $i => $r) {
        $rev = number_format((float)$r['revenue'], 2);
        $conv = (int)($r['conv'] ?? 0);
        $msg .= "{$medals[$i]} *" . orbitraTelegramEscape($r['name']) . "*\n";
        $msg .= "   💰 \${$rev} | 🎯 {$conv} | 👆 {$r['clicks']}\n\n";
    }

    sendTelegram($token, $chatId, $msg);
}

function handleConversions($pdo, $token, $chatId, $lang)
{
    $stmt = $pdo->query("
        SELECT cv.status, cv.payout, cv.currency, cv.created_at,
               c.name as campaign_name,
               cl.country
        FROM conversions cv
        LEFT JOIN clicks cl ON cl.id = cv.click_id
        LEFT JOIN campaigns c ON c.id = cl.campaign_id
        ORDER BY cv.created_at DESC
        LIMIT 10
    ");
    $rows = $stmt->fetchAll();

    if (empty($rows)) {
        sendTelegram($token, $chatId, botText($lang, 'no_conversions'));
        return;
    }

    $msg = botText($lang, 'conversions_title') . "\n\n";
    foreach ($rows as $r) {
        $time = date('H:i', strtotime($r['created_at']));
        $payout = number_format((float)$r['payout'], 2);
        $flag = getCountryFlag($r['country'] ?? '');
        $msg .= "• `{$r['status']}` | \${$payout} | " . orbitraTelegramEscape((string)$r['campaign_name']) . " {$flag} {$time}\n";
    }

    sendTelegram($token, $chatId, $msg);
}

// Helper: country code to flag emoji
function getCountryFlag($code)
{
    if (strlen($code) !== 2)
        return '';
    $code = strtoupper($code);
    return mb_chr(0x1F1E6 + ord($code[0]) - ord('A')) . mb_chr(0x1F1E6 + ord($code[1]) - ord('A'));
}

// Handle /sources command - show traffic sources status
function handleSources($pdo, $token, $chatId, $lang)
{
    $stmt = $pdo->query("
        SELECT name, url, http_status, last_checked
        FROM traffic_sources
        WHERE is_archived = 0
        ORDER BY name ASC
    ");
    $sources = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Filter sources that have URL set
    $sources = array_filter($sources, function($s) {
        return !empty($s['url']);
    });

    if (empty($sources)) {
        $msg = botText($lang, 'sources_empty');
        sendTelegram($token, $chatId, $msg);
        return;
    }

    $msg = botText($lang, 'sources_title') . "\n\n";
    $okCount = 0;
    $errorCount = 0;

    foreach ($sources as $s) {
        $status = $s['http_status'] ?? 'unknown';
        $name = $s['name'];
        $url = $s['url'];

        if ($status === '200') {
            $icon = "✅";
            $okCount++;
        } elseif ($status === 'error' || $status === 'unknown') {
            $icon = "❌";
            $errorCount++;
        } elseif ($status === 'timeout') {
            $icon = "⏰";
            $errorCount++;
        } else {
            $icon = "⚠️";
            $errorCount++;
        }

        $msg .= $icon . " *" . $name . "*\n";
        $msg .= "   `" . $url . "` → `" . $status . "`\n";

        if ($s['last_checked']) {
            $time = date('H:i', strtotime($s['last_checked']));
            $msg .= "   _Проверено: " . $time . "_\n";
        }
        $msg .= "\n";
    }

    $summary = str_replace(['{ok}', '{errors}'], [$okCount, $errorCount], botText($lang, 'sources_summary'));
    $msg .= $summary;

    $msg .= "\n\n💡 /checksources — проверить все URLs";

    sendTelegram($token, $chatId, $msg);
}

// Handle /checksources command - check all traffic source URLs
function handleCheckSources($pdo, $token, $chatId, $lang)
{
    // Send initial message
    sendTelegram($token, $chatId, botText($lang, 'sources_checking'));

    // Get all sources with URLs
    $stmt = $pdo->query("
        SELECT id, url FROM traffic_sources
        WHERE url IS NOT NULL AND url != '' AND is_archived = 0
    ");
    $sources = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if (empty($sources)) {
        sendTelegram($token, $chatId, botText($lang, 'sources_empty'));
        return;
    }

    // Check each URL (reuse checkUrlAvailability function from api.php if available, otherwise inline)
    $checked = 0;
    $okCount = 0;

    foreach ($sources as $s) {
        $url = $s['url'];
        $result = checkSourceUrlInline($url);

        $updateStmt = $pdo->prepare("UPDATE traffic_sources SET http_status = ?, last_checked = datetime('now'), status_message = ? WHERE id = ?");
        $updateStmt->execute([$result['status'], $result['message'], $s['id']]);

        $checked++;
        if ($result['status'] === '200') {
            $okCount++;
        }
    }

    // Send results
    handleSources($pdo, $token, $chatId, $lang);
}

// Inline URL check function (simplified version of api.php function)
function checkSourceUrlInline($url)
{
    // Ensure URL has a scheme
    if (!empty($url) && !preg_match('~^https?://~i', $url)) {
        $url = 'https://' . $url;
    }

    // Validate URL
    if (!filter_var($url, FILTER_VALIDATE_URL)) {
        return ['status' => 'error', 'message' => 'Invalid URL'];
    }

    // SSL verification: only disable in local development
    $isLocalDev = (getenv('APP_ENV') === 'local') || (getenv('ORBITRA_ENV') === 'dev') || (php_uname('n') === 'localhost');

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_NOBODY => true, // HEAD request
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => $isLocalDev ? false : true,
        CURLOPT_SSL_VERIFYHOST => $isLocalDev ? false : 2,
        CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; Orbitra/1.0)',
    ]);

    curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    // curl_close() deprecated in PHP 8.5 - resources are auto-freed

    if ($error) {
        if (strpos($error, 'timed out') !== false || strpos($error, 'timeout') !== false) {
            return ['status' => 'timeout', 'message' => 'Timeout'];
        }
        return ['status' => 'error', 'message' => $error];
    }

    if ($httpCode >= 200 && $httpCode < 400) {
        return ['status' => (string) $httpCode, 'message' => 'OK'];
    }

    return ['status' => (string) $httpCode, 'message' => "HTTP $httpCode"];
}