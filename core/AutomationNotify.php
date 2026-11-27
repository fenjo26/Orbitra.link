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
            'ko' => [
                'rotation_title' => '🔁 자동 최적화가 트래픽을 재배분했습니다',
                'campaign' => '캠페인',
                'stream' => '스트림',
                'list_offers' => '오퍼',
                'list_landings' => '랜딩',
                'by_metric' => '최근 {days}일 기준 {metric}',
                'rule_title_notify' => '⚠️ 규칙 «{rule}»이(가) 발동했습니다',
                'rule_title_pause' => '⏸ 규칙 «{rule}»이(가) 캠페인을 일시 중지했습니다',
                'rule_title_disable' => '⛔ 규칙 «{rule}»이(가) {kind} «{item}»을(를) 비활성화했습니다',
                'kind_offers' => '오퍼',
                'kind_landings' => '랜딩',
                'item_offers' => '오퍼',
                'item_landings' => '랜딩',
                'window' => '최근 {hours}시간',
                'pause_note' => '이제 캠페인 링크의 방문자에게 "Campaign is disabled"가 표시됩니다. 광고 네트워크에서 광고를 중지할 때까지 예산은 계속 소진됩니다.',
                'm_clicks' => '클릭', 'm_conversions' => '전환', 'm_sales' => '판매',
                'm_registrations' => '회원가입', 'm_deposits' => '입금',
                'm_cost' => '지출', 'm_revenue' => '수익', 'm_profit' => '이익',
                'm_roi' => 'ROI', 'm_cr' => 'CR', 'm_epc' => 'EPC',
                'm_epv_confirmed' => 'EPV', 'm_epc_confirmed' => 'EPC', 'm_roi_confirmed' => 'ROI',
            ],
        ];
    }

    /** Link-check alerts (core/LinkChecks.php), in the same languages as above. */
    function orbitraLinkChecksStrings(): array
    {
        return [
            'en' => [
                'kind_offer' => 'Offer',
                'kind_domain' => 'Domain',
                'failed' => '🔴 {kind} «{name}» is not opening',
                'recovered' => '🟢 {kind} «{name}» is working again (was down for {duration})',
                'warning' => '🟡 {kind} «{name}» needs attention',
                'reason' => 'Reason: {reason}',
                'chain' => 'Chain: {chain}',
                'paused' => 'The offer was paused automatically.',
                'many_failed' => '🔴 {count} links stopped opening at once: {names}',
                'many_recovered' => '🟢 {count} links are working again',
                'proxy_failed' => '⚠️ Proxy «{name}» is not responding. Checks through it: {count}.',
                'proxy_recovered' => '✅ Proxy «{name}» is responding again ({ip}).',
                'more' => 'and {count} more',
                'r_timeout' => 'the site did not answer in time',
                'r_dns_error' => 'the domain does not resolve',
                'r_connection_refused' => 'the server refused the connection',
                'r_connection_error' => 'no connection to the site',
                'r_ssl_error' => 'certificate error',
                'r_redirect_loop' => 'redirect loop',
                'r_too_many_redirects' => 'too many redirects',
                'r_unexpected_final_url' => 'leads to another domain (expected {expected}, got {got})',
                'r_site_protection' => 'site protection did not let the check through',
                'r_https_to_http' => 'redirects from HTTPS to HTTP',
                'r_slow_response' => 'slow answer ({seconds} s)',
                'r_proxy_error' => 'the proxy is not responding',
                'r_url_blocked' => 'the link leads to a private address and was refused',
                'r_http' => 'the site answered HTTP {code}',
            ],
            'ru' => [
                'kind_offer' => 'Оффер',
                'kind_domain' => 'Домен',
                'failed' => '🔴 {kind} «{name}» не открывается',
                'recovered' => '🟢 {kind} «{name}» снова работает (не открывался {duration})',
                'warning' => '🟡 {kind} «{name}» требует внимания',
                'reason' => 'Причина: {reason}',
                'chain' => 'Цепочка: {chain}',
                'paused' => 'Оффер поставлен на паузу автоматически.',
                'many_failed' => '🔴 Сразу {count} ссылок перестали открываться: {names}',
                'many_recovered' => '🟢 {count} ссылок снова работают',
                'proxy_failed' => '⚠️ Прокси «{name}» не отвечает. Проверок через него: {count}.',
                'proxy_recovered' => '✅ Прокси «{name}» снова отвечает ({ip}).',
                'more' => 'и ещё {count}',
                'r_timeout' => 'сайт не ответил вовремя',
                'r_dns_error' => 'домен не резолвится',
                'r_connection_refused' => 'сервер отклонил соединение',
                'r_connection_error' => 'нет соединения с сайтом',
                'r_ssl_error' => 'ошибка сертификата',
                'r_redirect_loop' => 'петля редиректов',
                'r_too_many_redirects' => 'слишком много редиректов',
                'r_unexpected_final_url' => 'ведёт на другой домен (ожидали {expected}, получили {got})',
                'r_site_protection' => 'защита сайта не пустила проверку',
                'r_https_to_http' => 'переходит с HTTPS на HTTP',
                'r_slow_response' => 'медленный ответ ({seconds} с)',
                'r_proxy_error' => 'прокси не отвечает',
                'r_url_blocked' => 'ссылка ведёт на частный адрес и отклонена',
                'r_http' => 'сайт ответил HTTP {code}',
            ],
            'uk' => [
                'kind_offer' => 'Офер',
                'kind_domain' => 'Домен',
                'failed' => '🔴 {kind} «{name}» не відкривається',
                'recovered' => '🟢 {kind} «{name}» знову працює (не відкривався {duration})',
                'warning' => '🟡 {kind} «{name}» потребує уваги',
                'reason' => 'Причина: {reason}',
                'chain' => 'Ланцюжок: {chain}',
                'paused' => 'Офер поставлено на паузу автоматично.',
                'many_failed' => '🔴 Одразу {count} посилань перестали відкриватися: {names}',
                'many_recovered' => '🟢 {count} посилань знову працюють',
                'proxy_failed' => '⚠️ Проксі «{name}» не відповідає. Перевірок через нього: {count}.',
                'proxy_recovered' => '✅ Проксі «{name}» знову відповідає ({ip}).',
                'more' => 'і ще {count}',
                'r_timeout' => 'сайт не відповів вчасно',
                'r_dns_error' => 'домен не визначається',
                'r_connection_refused' => 'сервер відхилив з’єднання',
                'r_connection_error' => 'немає з’єднання з сайтом',
                'r_ssl_error' => 'помилка сертифіката',
                'r_redirect_loop' => 'петля редиректів',
                'r_too_many_redirects' => 'забагато редиректів',
                'r_unexpected_final_url' => 'веде на інший домен (чекали {expected}, отримали {got})',
                'r_site_protection' => 'захист сайту не пропустив перевірку',
                'r_https_to_http' => 'переходить з HTTPS на HTTP',
                'r_slow_response' => 'повільна відповідь ({seconds} с)',
                'r_proxy_error' => 'проксі не відповідає',
                'r_url_blocked' => 'посилання веде на приватну адресу і відхилено',
                'r_http' => 'сайт відповів HTTP {code}',
            ],
            'es' => [
                'kind_offer' => 'Oferta',
                'kind_domain' => 'Dominio',
                'failed' => '🔴 {kind} «{name}» no abre',
                'recovered' => '🟢 {kind} «{name}» vuelve a funcionar (estuvo caído {duration})',
                'warning' => '🟡 {kind} «{name}» necesita atención',
                'reason' => 'Motivo: {reason}',
                'chain' => 'Cadena: {chain}',
                'paused' => 'La oferta se pausó automáticamente.',
                'many_failed' => '🔴 {count} enlaces dejaron de abrir a la vez: {names}',
                'many_recovered' => '🟢 {count} enlaces vuelven a funcionar',
                'proxy_failed' => '⚠️ El proxy «{name}» no responde. Comprobaciones a través de él: {count}.',
                'proxy_recovered' => '✅ El proxy «{name}» vuelve a responder ({ip}).',
                'more' => 'y {count} más',
                'r_timeout' => 'el sitio no respondió a tiempo',
                'r_dns_error' => 'el dominio no resuelve',
                'r_connection_refused' => 'el servidor rechazó la conexión',
                'r_connection_error' => 'sin conexión con el sitio',
                'r_ssl_error' => 'error de certificado',
                'r_redirect_loop' => 'bucle de redirección',
                'r_too_many_redirects' => 'demasiadas redirecciones',
                'r_unexpected_final_url' => 'lleva a otro dominio (se esperaba {expected}, se obtuvo {got})',
                'r_site_protection' => 'la protección del sitio no dejó pasar la comprobación',
                'r_https_to_http' => 'redirige de HTTPS a HTTP',
                'r_slow_response' => 'respuesta lenta ({seconds} s)',
                'r_proxy_error' => 'el proxy no responde',
                'r_url_blocked' => 'el enlace apunta a una dirección privada y se rechazó',
                'r_http' => 'el sitio respondió HTTP {code}',
            ],
            'de' => [
                'kind_offer' => 'Angebot',
                'kind_domain' => 'Domain',
                'failed' => '🔴 {kind} «{name}» öffnet nicht',
                'recovered' => '🟢 {kind} «{name}» funktioniert wieder (war {duration} nicht erreichbar)',
                'warning' => '🟡 {kind} «{name}» braucht Aufmerksamkeit',
                'reason' => 'Grund: {reason}',
                'chain' => 'Kette: {chain}',
                'paused' => 'Das Angebot wurde automatisch pausiert.',
                'many_failed' => '🔴 {count} Links öffnen auf einmal nicht mehr: {names}',
                'many_recovered' => '🟢 {count} Links funktionieren wieder',
                'proxy_failed' => '⚠️ Proxy «{name}» antwortet nicht. Prüfungen darüber: {count}.',
                'proxy_recovered' => '✅ Proxy «{name}» antwortet wieder ({ip}).',
                'more' => 'und {count} weitere',
                'r_timeout' => 'die Seite hat nicht rechtzeitig geantwortet',
                'r_dns_error' => 'die Domain löst nicht auf',
                'r_connection_refused' => 'der Server hat die Verbindung abgelehnt',
                'r_connection_error' => 'keine Verbindung zur Seite',
                'r_ssl_error' => 'Zertifikatsfehler',
                'r_redirect_loop' => 'Weiterleitungsschleife',
                'r_too_many_redirects' => 'zu viele Weiterleitungen',
                'r_unexpected_final_url' => 'führt auf eine andere Domain (erwartet {expected}, erhalten {got})',
                'r_site_protection' => 'der Seitenschutz ließ die Prüfung nicht durch',
                'r_https_to_http' => 'leitet von HTTPS auf HTTP weiter',
                'r_slow_response' => 'langsame Antwort ({seconds} s)',
                'r_proxy_error' => 'der Proxy antwortet nicht',
                'r_url_blocked' => 'der Link führt auf eine private Adresse und wurde abgelehnt',
                'r_http' => 'die Seite antwortete HTTP {code}',
            ],
            'fr' => [
                'kind_offer' => 'Offre',
                'kind_domain' => 'Domaine',
                'failed' => '🔴 {kind} « {name} » ne s’ouvre pas',
                'recovered' => '🟢 {kind} « {name} » fonctionne à nouveau (hors ligne pendant {duration})',
                'warning' => '🟡 {kind} « {name} » demande votre attention',
                'reason' => 'Cause : {reason}',
                'chain' => 'Chaîne : {chain}',
                'paused' => 'L’offre a été mise en pause automatiquement.',
                'many_failed' => '🔴 {count} liens ne s’ouvrent plus en même temps : {names}',
                'many_recovered' => '🟢 {count} liens fonctionnent à nouveau',
                'proxy_failed' => '⚠️ Le proxy « {name} » ne répond pas. Contrôles via ce proxy : {count}.',
                'proxy_recovered' => '✅ Le proxy « {name} » répond à nouveau ({ip}).',
                'more' => 'et {count} autres',
                'r_timeout' => 'le site n’a pas répondu à temps',
                'r_dns_error' => 'le domaine ne se résout pas',
                'r_connection_refused' => 'le serveur a refusé la connexion',
                'r_connection_error' => 'pas de connexion au site',
                'r_ssl_error' => 'erreur de certificat',
                'r_redirect_loop' => 'boucle de redirection',
                'r_too_many_redirects' => 'trop de redirections',
                'r_unexpected_final_url' => 'mène à un autre domaine (attendu {expected}, obtenu {got})',
                'r_site_protection' => 'la protection du site a bloqué le contrôle',
                'r_https_to_http' => 'redirige de HTTPS vers HTTP',
                'r_slow_response' => 'réponse lente ({seconds} s)',
                'r_proxy_error' => 'le proxy ne répond pas',
                'r_url_blocked' => 'le lien mène à une adresse privée et a été refusé',
                'r_http' => 'le site a répondu HTTP {code}',
            ],
            'zh' => [
                'kind_offer' => '优惠',
                'kind_domain' => '域名',
                'failed' => '🔴 {kind}「{name}」打不开',
                'recovered' => '🟢 {kind}「{name}」恢复正常（已中断 {duration}）',
                'warning' => '🟡 {kind}「{name}」需要关注',
                'reason' => '原因：{reason}',
                'chain' => '跳转链：{chain}',
                'paused' => '该优惠已自动暂停。',
                'many_failed' => '🔴 {count} 个链接同时打不开：{names}',
                'many_recovered' => '🟢 {count} 个链接已恢复',
                'proxy_failed' => '⚠️ 代理「{name}」无响应。经由它的检查：{count}。',
                'proxy_recovered' => '✅ 代理「{name}」恢复响应（{ip}）。',
                'more' => '另外 {count} 个',
                'r_timeout' => '网站未及时响应',
                'r_dns_error' => '域名无法解析',
                'r_connection_refused' => '服务器拒绝连接',
                'r_connection_error' => '无法连接到网站',
                'r_ssl_error' => '证书错误',
                'r_redirect_loop' => '重定向循环',
                'r_too_many_redirects' => '重定向过多',
                'r_unexpected_final_url' => '跳到了另一个域名（期望 {expected}，实际 {got}）',
                'r_site_protection' => '网站防护拦截了检查',
                'r_https_to_http' => '从 HTTPS 跳转到 HTTP',
                'r_slow_response' => '响应缓慢（{seconds} 秒）',
                'r_proxy_error' => '代理无响应',
                'r_url_blocked' => '链接指向私有地址，已被拒绝',
                'r_http' => '网站返回 HTTP {code}',
            ],
            'ko' => [
                'kind_offer' => '오퍼',
                'kind_domain' => '도메인',
                'failed' => '🔴 {kind} «{name}»이(가) 열리지 않습니다',
                'recovered' => '🟢 {kind} «{name}»이(가) 다시 작동합니다({duration} 동안 중단됨)',
                'warning' => '🟡 {kind} «{name}»에 주의가 필요합니다',
                'reason' => '원인: {reason}',
                'chain' => '경로: {chain}',
                'paused' => '오퍼가 자동으로 일시 중지되었습니다.',
                'many_failed' => '🔴 링크 {count}개가 한 번에 열리지 않습니다: {names}',
                'many_recovered' => '🟢 링크 {count}개가 다시 작동합니다',
                'proxy_failed' => '⚠️ 프록시 «{name}»이(가) 응답하지 않습니다. 이 프록시를 통한 검사: {count}개.',
                'proxy_recovered' => '✅ 프록시 «{name}»이(가) 다시 응답합니다({ip}).',
                'more' => '외 {count}개',
                'r_timeout' => '사이트가 제시간에 응답하지 않았습니다',
                'r_dns_error' => '도메인을 확인할 수 없습니다',
                'r_connection_refused' => '서버가 연결을 거부했습니다',
                'r_connection_error' => '사이트에 연결할 수 없습니다',
                'r_ssl_error' => '인증서 오류',
                'r_redirect_loop' => '리디렉션 루프',
                'r_too_many_redirects' => '리디렉션이 너무 많습니다',
                'r_unexpected_final_url' => '다른 도메인으로 이동합니다(예상 {expected}, 실제 {got})',
                'r_site_protection' => '사이트 보호 기능이 검사를 차단했습니다',
                'r_https_to_http' => 'HTTPS에서 HTTP로 리디렉션됩니다',
                'r_slow_response' => '응답이 느립니다({seconds}초)',
                'r_proxy_error' => '프록시가 응답하지 않습니다',
                'r_url_blocked' => '링크가 사설 주소를 가리켜 거부되었습니다',
                'r_http' => '사이트가 HTTP {code}을(를) 반환했습니다',
            ],
        ];
    }

    function orbitraLinkChecksText(string $lang, string $key, array $params = []): string
    {
        static $s = null;
        if ($s === null) {
            $s = orbitraLinkChecksStrings();
        }
        $lang = strtolower(substr($lang, 0, 2));
        $text = $s[$lang][$key] ?? $s['en'][$key] ?? $key;
        foreach ($params as $k => $v) {
            $text = str_replace('{' . $k . '}', (string) $v, $text);
        }
        return $text;
    }

    function orbitraAutomationText(
string $lang, string $key, array $params = []): string
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
            // Which chats want this kind of message: conversion alerts unless
            // the caller names another flag of telegram_bot_chats.
            $flag = in_array($opts['flag'] ?? '', ['notify_conversions', 'notify_link_checks'], true)
                ? (string) $opts['flag'] : 'notify_conversions';
            $chats = $pdo->query("SELECT chat_id, language FROM telegram_bot_chats
                                  WHERE is_active = 1 AND {$flag} = 1")->fetchAll(PDO::FETCH_ASSOC);
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
