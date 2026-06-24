<?php
/**
 * Telegram Bot Webhook Handler
 * Receives updates from Telegram and processes bot commands
 */
require_once 'config.php';

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
        ]
    ];

    $text = $texts[$lang][$key] ?? $texts['en'][$key] ?? $key;
    $params['version'] = $version;
    foreach ($params as $k => $v) {
        $text = str_replace('{' . $k . '}', $v, $text);
    }
    return $text;
}

// Send message to Telegram
function sendTelegram($token, $chatId, $text, $parseMode = 'Markdown')
{
    $url = "https://api.telegram.org/bot{$token}/sendMessage";
    $data = [
        'chat_id' => $chatId,
        'text' => $text,
        'parse_mode' => $parseMode,
        'disable_web_page_preview' => true
    ];

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    $result = curl_exec($ch);
    // curl_close() deprecated in PHP 8.5 - resources are auto-freed
    return json_decode($result, true);
}

// Get bot token from settings
$stmt = $pdo->query("SELECT value FROM settings WHERE key = 'telegram_bot_token'");
$botToken = $stmt ? $stmt->fetchColumn() : '';

if (!$botToken) {
    http_response_code(200);
    die('No token configured');
}

// Read incoming update
$input = file_get_contents('php://input');
$update = json_decode($input, true);

if (!$update || !isset($update['message'])) {
    http_response_code(200);
    die('ok');
}

$message = $update['message'];
$chatId = (string)$message['chat']['id'];
$text = trim($message['text'] ?? '');
$username = $message['from']['username'] ?? '';
$firstName = $message['from']['first_name'] ?? '';

// Register/update chat
$stmt = $pdo->prepare("INSERT OR IGNORE INTO telegram_bot_chats (chat_id, username, first_name) VALUES (?, ?, ?)");
$stmt->execute([$chatId, $username, $firstName]);
$pdo->prepare("UPDATE telegram_bot_chats SET username = ?, first_name = ?, is_active = 1 WHERE chat_id = ?")->execute([$username, $firstName, $chatId]);

// Get chat language
$stmt = $pdo->prepare("SELECT language FROM telegram_bot_chats WHERE chat_id = ?");
$stmt->execute([$chatId]);
$lang = $stmt->fetchColumn() ?: 'ru';

// Parse command
$parts = explode(' ', $text, 2);
$command = strtolower($parts[0]);
$arg = $parts[1] ?? '';

switch ($command) {
    case '/start':
        sendTelegram($botToken, $chatId, botText($lang, 'welcome'));
        break;

    case '/help':
        sendTelegram($botToken, $chatId, botText($lang, 'help'));
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
        $val = strtolower(trim($arg));
        $enabled = ($val === 'on' || $val === '1') ? 1 : 0;
        $pdo->prepare("UPDATE telegram_bot_chats SET notify_conversions = ? WHERE chat_id = ?")->execute([$enabled, $chatId]);
        sendTelegram($botToken, $chatId, botText($lang, $enabled ? 'notify_on' : 'notify_off'));
        break;

    case '/daily':
        $val = strtolower(trim($arg));
        $enabled = ($val === 'on' || $val === '1') ? 1 : 0;
        $pdo->prepare("UPDATE telegram_bot_chats SET notify_daily = ? WHERE chat_id = ?")->execute([$enabled, $chatId]);
        sendTelegram($botToken, $chatId, botText($lang, $enabled ? 'daily_on' : 'daily_off'));
        break;

    case '/lang':
        $newLang = strtolower(trim($arg));
        if (!in_array($newLang, ['ru', 'en', 'uk', 'es', 'zh', 'fr', 'de']))
            $newLang = 'ru';
        $pdo->prepare("UPDATE telegram_bot_chats SET language = ? WHERE chat_id = ?")->execute([$newLang, $chatId]);
        sendTelegram($botToken, $chatId, botText($newLang, 'lang_set'));
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

http_response_code(200);
echo 'ok';

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
        $msg .= "*{$num}.* `[{$c['id']}]` {$c['name']}\n";
        $msg .= "   👆 {$clicks} | 🎯 {$conv} | 💰 \${$rev}\n\n";
    }

    sendTelegram($token, $chatId, $msg);
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

    $msg = botText($lang, 'campaign_detail', ['name' => $campaign['name']]) . "\n";
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
        $msg .= "{$medals[$i]} *{$r['name']}*\n";
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
        $msg .= "• `{$r['status']}` | \${$payout} | {$r['campaign_name']} {$flag} {$time}\n";
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

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_NOBODY => true, // HEAD request
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_TIMEOUT => 10,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
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