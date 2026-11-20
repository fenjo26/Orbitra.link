<?php
// core/AutomationNotify.php
//
// Telegram messages from the automation layer: the rotation optimiser
// ("weights moved") and campaign rules ("rule fired / campaign paused / offer
// switched off").
//
// Delivery contract is the one ZoneRules and ConversionMonitor already use:
// the panel's own admin bot, every active chat that has conversion alerts on
// (/notify on). Each chat gets the text in its own bot language (/lang), with
// English as the fallback. Plain text — metric values carry % and _ that
// Markdown would eat.
//
// Never throws: a notification must not break a cron pass whose journal row
// already records what happened.

if (!function_exists('orbitraAutomationText')) {

    /** 7 bot languages, same set as telegram_bot.php botText(). */
    function orbitraAutomationStrings(): array
    {
        return [
            'en' => [
                'rotation_title' => '🔁 Auto-optimisation moved traffic',
                'campaign' => 'Campaign',
                'stream' => 'Stream',
                'list_offers' => 'Offers',
                'list_landings' => 'Landings',
                'by_metric' => 'By {metric} over {days} d',
                'rule_title_notify' => '⚠️ Rule «{rule}» fired',
                'rule_title_pause' => '⏸ Rule «{rule}» paused the campaign',
                'rule_title_disable' => '⛔ Rule «{rule}» switched off {kind} «{item}»',
                'kind_offers' => 'offer',
                'kind_landings' => 'landing',
                'item_offers' => 'Offer',
                'item_landings' => 'Landing',
                'window' => 'Last {hours} h',
                'pause_note' => 'Visitors on the campaign link now get "Campaign is disabled". The ad network keeps spending until you stop the ads there.',
                'm_clicks' => 'clicks', 'm_conversions' => 'conversions', 'm_sales' => 'sales',
                'm_registrations' => 'registrations', 'm_deposits' => 'deposits',
                'm_cost' => 'spend', 'm_revenue' => 'revenue', 'm_profit' => 'profit',
                'm_roi' => 'ROI', 'm_cr' => 'CR', 'm_epc' => 'EPC',
                'm_epv_confirmed' => 'EPV', 'm_epc_confirmed' => 'EPC', 'm_roi_confirmed' => 'ROI',
            ],
            'ru' => [
                'rotation_title' => '🔁 Автооптимизация перераспределила трафик',
                'campaign' => 'Кампания',
                'stream' => 'Поток',
                'list_offers' => 'Офферы',
                'list_landings' => 'Лендинги',
                'by_metric' => 'По {metric} за {days} дн.',
                'rule_title_notify' => '⚠️ Сработало правило «{rule}»',
                'rule_title_pause' => '⏸ Правило «{rule}» остановило кампанию',
                'rule_title_disable' => '⛔ Правило «{rule}» отключило {kind} «{item}»',
                'kind_offers' => 'оффер',
                'kind_landings' => 'лендинг',
                'item_offers' => 'Оффер',
                'item_landings' => 'Лендинг',
                'window' => 'За {hours} ч',
                'pause_note' => 'Посетители по ссылке кампании теперь видят «Campaign is disabled». Рекламная сеть продолжит тратить бюджет, пока вы не остановите объявления в ней.',
                'm_clicks' => 'клики', 'm_conversions' => 'конверсии', 'm_sales' => 'продажи',
                'm_registrations' => 'регистрации', 'm_deposits' => 'депозиты',
                'm_cost' => 'расход', 'm_revenue' => 'доход', 'm_profit' => 'профит',
                'm_roi' => 'ROI', 'm_cr' => 'CR', 'm_epc' => 'EPC',
                'm_epv_confirmed' => 'EPV', 'm_epc_confirmed' => 'EPC', 'm_roi_confirmed' => 'ROI',
            ],
            'uk' => [
                'rotation_title' => '🔁 Автооптимізація перерозподілила трафік',
                'campaign' => 'Кампанія',
                'stream' => 'Потік',
                'list_offers' => 'Офери',
                'list_landings' => 'Лендинги',
                'by_metric' => 'За {metric} за {days} дн.',
                'rule_title_notify' => '⚠️ Спрацювало правило «{rule}»',
                'rule_title_pause' => '⏸ Правило «{rule}» зупинило кампанію',
                'rule_title_disable' => '⛔ Правило «{rule}» вимкнуло {kind} «{item}»',
                'kind_offers' => 'офер',
                'kind_landings' => 'лендинг',
                'item_offers' => 'Офер',
                'item_landings' => 'Лендинг',
                'window' => 'За {hours} год',
                'pause_note' => 'Відвідувачі за посиланням кампанії тепер бачать «Campaign is disabled». Рекламна мережа й далі витрачатиме бюджет, доки ви не зупините оголошення в ній.',
                'm_clicks' => 'кліки', 'm_conversions' => 'конверсії', 'm_sales' => 'продажі',
                'm_registrations' => 'реєстрації', 'm_deposits' => 'депозити',
                'm_cost' => 'витрати', 'm_revenue' => 'дохід', 'm_profit' => 'профіт',
                'm_roi' => 'ROI', 'm_cr' => 'CR', 'm_epc' => 'EPC',
                'm_epv_confirmed' => 'EPV', 'm_epc_confirmed' => 'EPC', 'm_roi_confirmed' => 'ROI',
            ],
            'es' => [
                'rotation_title' => '🔁 La autooptimización redistribuyó el tráfico',
                'campaign' => 'Campaña',
                'stream' => 'Flujo',
                'list_offers' => 'Ofertas',
                'list_landings' => 'Landings',
                'by_metric' => 'Por {metric} en {days} d',
                'rule_title_notify' => '⚠️ Se activó la regla «{rule}»',
                'rule_title_pause' => '⏸ La regla «{rule}» pausó la campaña',
                'rule_title_disable' => '⛔ La regla «{rule}» desactivó {kind} «{item}»',
                'kind_offers' => 'la oferta',
                'kind_landings' => 'la landing',
                'item_offers' => 'Oferta',
                'item_landings' => 'Landing',
                'window' => 'Últimas {hours} h',
                'pause_note' => 'Los visitantes del enlace de la campaña ahora ven «Campaign is disabled». La red publicitaria seguirá gastando hasta que detengas los anuncios allí.',
                'm_clicks' => 'clics', 'm_conversions' => 'conversiones', 'm_sales' => 'ventas',
                'm_registrations' => 'registros', 'm_deposits' => 'depósitos',
                'm_cost' => 'gasto', 'm_revenue' => 'ingresos', 'm_profit' => 'beneficio',
                'm_roi' => 'ROI', 'm_cr' => 'CR', 'm_epc' => 'EPC',
                'm_epv_confirmed' => 'EPV', 'm_epc_confirmed' => 'EPC', 'm_roi_confirmed' => 'ROI',
            ],
            'de' => [
                'rotation_title' => '🔁 Auto-Optimierung hat Traffic umverteilt',
                'campaign' => 'Kampagne',
                'stream' => 'Stream',
                'list_offers' => 'Angebote',
                'list_landings' => 'Landingpages',
                'by_metric' => 'Nach {metric} über {days} T.',
                'rule_title_notify' => '⚠️ Regel «{rule}» ausgelöst',
                'rule_title_pause' => '⏸ Regel «{rule}» hat die Kampagne pausiert',
                'rule_title_disable' => '⛔ Regel «{rule}» hat {kind} «{item}» deaktiviert',
                'kind_offers' => 'das Angebot',
                'kind_landings' => 'die Landingpage',
                'item_offers' => 'Angebot',
                'item_landings' => 'Landingpage',
                'window' => 'Letzte {hours} Std.',
                'pause_note' => 'Besucher des Kampagnenlinks sehen jetzt «Campaign is disabled». Das Werbenetzwerk gibt weiter Budget aus, bis du die Anzeigen dort stoppst.',
                'm_clicks' => 'Klicks', 'm_conversions' => 'Conversions', 'm_sales' => 'Verkäufe',
                'm_registrations' => 'Registrierungen', 'm_deposits' => 'Einzahlungen',
                'm_cost' => 'Ausgaben', 'm_revenue' => 'Umsatz', 'm_profit' => 'Gewinn',
                'm_roi' => 'ROI', 'm_cr' => 'CR', 'm_epc' => 'EPC',
                'm_epv_confirmed' => 'EPV', 'm_epc_confirmed' => 'EPC', 'm_roi_confirmed' => 'ROI',
            ],
            'fr' => [
                'rotation_title' => '🔁 L’auto-optimisation a redistribué le trafic',
                'campaign' => 'Campagne',
                'stream' => 'Flux',
                'list_offers' => 'Offres',
                'list_landings' => 'Landings',
                'by_metric' => 'Selon {metric} sur {days} j',
                'rule_title_notify' => '⚠️ Règle « {rule} » déclenchée',
                'rule_title_pause' => '⏸ La règle « {rule} » a mis la campagne en pause',
                'rule_title_disable' => '⛔ La règle « {rule} » a désactivé {kind} « {item} »',
                'kind_offers' => 'l’offre',
                'kind_landings' => 'la landing',
                'item_offers' => 'Offre',
                'item_landings' => 'Landing',
                'window' => 'Dernières {hours} h',
                'pause_note' => 'Les visiteurs du lien de campagne voient maintenant « Campaign is disabled ». Le réseau publicitaire continue de dépenser tant que vous n’arrêtez pas les annonces chez lui.',
                'm_clicks' => 'clics', 'm_conversions' => 'conversions', 'm_sales' => 'ventes',
                'm_registrations' => 'inscriptions', 'm_deposits' => 'dépôts',
                'm_cost' => 'dépense', 'm_revenue' => 'revenu', 'm_profit' => 'profit',
                'm_roi' => 'ROI', 'm_cr' => 'CR', 'm_epc' => 'EPC',
                'm_epv_confirmed' => 'EPV', 'm_epc_confirmed' => 'EPC', 'm_roi_confirmed' => 'ROI',
            ],
            'zh' => [
                'rotation_title' => '🔁 自动优化已重新分配流量',
                'campaign' => '广告系列',
                'stream' => '流',
                'list_offers' => '优惠',
                'list_landings' => '落地页',
                'by_metric' => '按 {metric}，{days} 天',
                'rule_title_notify' => '⚠️ 规则「{rule}」已触发',
                'rule_title_pause' => '⏸ 规则「{rule}」已暂停广告系列',
                'rule_title_disable' => '⛔ 规则「{rule}」已停用{kind}「{item}」',
                'kind_offers' => '优惠',
                'kind_landings' => '落地页',
                'item_offers' => '优惠',
                'item_landings' => '落地页',
                'window' => '最近 {hours} 小时',
                'pause_note' => '访问广告系列链接的访客现在会看到 "Campaign is disabled"。在广告网络中停止广告之前，网络仍会继续消耗预算。',
                'm_clicks' => '点击', 'm_conversions' => '转化', 'm_sales' => '销售',
                'm_registrations' => '注册', 'm_deposits' => '存款',
                'm_cost' => '花费', 'm_revenue' => '收入', 'm_profit' => '利润',
                'm_roi' => 'ROI', 'm_cr' => 'CR', 'm_epc' => 'EPC',
                'm_epv_confirmed' => 'EPV', 'm_epc_confirmed' => 'EPC', 'm_roi_confirmed' => 'ROI',
            ],
        ];
    }

    function orbitraAutomationText(string $lang, string $key, array $params = []): string
    {
        static $s = null;
        if ($s === null) {
            $s = orbitraAutomationStrings();
        }
        $lang = strtolower(substr($lang, 0, 2));
        $text = $s[$lang][$key] ?? $s['en'][$key] ?? $key;
        foreach ($params as $k => $v) {
            $text = str_replace('{' . $k . '}', (string) $v, $text);
        }
        return $text;
    }

    /**
     * Send one message per eligible chat. $build is called with the chat's
     * language and returns the text (or '' to skip that chat).
     *
     * Returns how many sends were attempted. Tests inject $opts['sender']
     * (fn(string $token, string $chatId, string $text)) instead of the API.
     */
    function orbitraAutomationBroadcast(PDO $pdo, callable $build, array $opts = []): int
    {
        try {
            $stmt = $pdo->prepare('SELECT value FROM settings WHERE key = ? LIMIT 1');
            $stmt->execute(['telegram_bot_token']);
            $token = (string) $stmt->fetchColumn();
            $stmt->closeCursor();
            if ($token === '') {
                return 0;
            }
            $chats = $pdo->query("SELECT chat_id, language FROM telegram_bot_chats
                                  WHERE is_active = 1 AND notify_conversions = 1")->fetchAll(PDO::FETCH_ASSOC);
            if (!$chats) {
                return 0;
            }
            $sender = $opts['sender'] ?? null;
            if (!is_callable($sender)) {
                require_once __DIR__ . '/telegram_api.php';
                $sender = static function (string $tok, string $chatId, string $text): void {
                    orbitraTelegramApi($tok, 'sendMessage', [
                        'chat_id' => $chatId,
                        'text' => $text,
                        'disable_web_page_preview' => true,
                    ], 10);
                };
            }
            $sent = 0;
            $cache = [];
            foreach ($chats as $chat) {
                $lang = (string) ($chat['language'] ?: 'ru');
                if (!array_key_exists($lang, $cache)) {
                    $cache[$lang] = (string) $build($lang);
                }
                if ($cache[$lang] === '') {
                    continue;
                }
                try {
                    $sender($token, (string) $chat['chat_id'], $cache[$lang]);
                    $sent++;
                } catch (\Throwable $e) {
                    // One unreachable chat must not starve the others.
                }
            }
            return $sent;
        } catch (\Throwable $e) {
            return 0;
        }
    }

    /** Short number for a message: 1234.5 → "1234.5", 0.12345 → "0.1235". */
    function orbitraAutomationNum($v): string
    {
        if ($v === null) {
            return '—';
        }
        $f = (float) $v;
        if (abs($f - round($f)) < 0.00001) {
            return (string) (int) round($f);
        }
        return rtrim(rtrim(number_format($f, abs($f) < 1 ? 4 : 2, '.', ''), '0'), '.');
    }
}
