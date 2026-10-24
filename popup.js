/*!
 * Orbitra Conversion Popup — the client half of the "Conversion popups"
 * plugin (Settings → Plugins). One file, three delivery channels:
 *
 *   1. Tracker-served landings (/lander/, show_html, local) — the tracker
 *      injects <script src="/popup.js" data-orbitra-popup="{config}">.
 *   2. External sites with tracking.js or kclient.js — the script reads
 *      info.popup from the Click API response and appends the same tag.
 *   3. External sites with kclient.php — echo $client->popupScript().
 *
 * The config rides the data attribute: the server already decided THIS click
 * gets a popup (bots and safe-page visitors never load this file at all).
 * The client adds the behavioural half: delay, two distinct activity types
 * (one of them pointer/touch — scroll or keyboard alone never qualify),
 * scroll depth and desktop exit-intent, plus once-per-N-hours frequency.
 *
 * The offer button is the tracker's signed landing→offer transition, so a
 * popup press completes the funnel like any CTA: offer_at, conversions and
 * the popup CTR metric all land on the same click.
 */
(function () {
    'use strict';

    if (window.__orbitraPopup) { return; }
    window.__orbitraPopup = true;

    // === Text packs =========================================================
    // 7 verticals × languages. {amount} {currency} {country} are macros;
    // amounts are per-vertical defaults the operator can override in
    // settings. `money: true` packs quote {amount} in US dollars and get it
    // converted to the visitor's currency (geo.rate, rounded to a "nice"
    // number) — 250 must not become "250 ₫". The others quote a percentage. Extendable: add a language key and it starts working —
    // an unknown visitor language falls back to English.
    var TEXTS = {
        neutral: {
            amount: 100,
            money: true,
            en: { title: ["Wait — a bonus is available", "Before you go: a {amount} {currency} bonus"], text: "New visitors can get a {amount} {currency} bonus. Take a look before you leave.", button: "See the bonus" },
            ru: { title: ["Подождите — для вас есть бонус", "Перед уходом: бонус {amount} {currency}"], text: "Новым посетителям доступен бонус {amount} {currency}. Загляните, прежде чем уйти.", button: "Посмотреть бонус" },
            es: { title: ["Espera — hay un bono disponible", "Antes de irte: un bono de {amount} {currency}"], text: "Los nuevos visitantes pueden obtener un bono de {amount} {currency}. Échale un vistazo antes de irte.", button: "Ver el bono" },
            pt: { title: ["Espere — há um bônus disponível", "Antes de sair: um bônus de {amount} {currency}"], text: "Novos visitantes podem receber um bônus de {amount} {currency}. Dê uma olhada antes de sair.", button: "Ver o bônus" },
            fr: { title: ["Attendez — un bonus est disponible", "Avant de partir : un bonus de {amount} {currency}"], text: "Les nouveaux visiteurs peuvent obtenir un bonus de {amount} {currency}. Jetez un œil avant de partir.", button: "Voir le bonus" },
            de: { title: ["Moment — ein Bonus ist verfügbar", "Bevor Sie gehen: {amount} {currency} Bonus"], text: "Neue Besucher können einen Bonus von {amount} {currency} erhalten. Schauen Sie kurz rein, bevor Sie gehen.", button: "Bonus ansehen" },
            it: { title: ["Aspetta — c’è un bonus disponibile", "Prima di andare: un bonus di {amount} {currency}"], text: "I nuovi visitatori possono ottenere un bonus di {amount} {currency}. Dai un’occhiata prima di uscire.", button: "Vedi il bonus" },
            pl: { title: ["Chwileczkę — czeka bonus", "Zanim wyjdziesz: bonus {amount} {currency}"], text: "Nowi odwiedzający mogą otrzymać bonus {amount} {currency}. Zobacz, zanim wyjdziesz.", button: "Zobacz bonus" },
            tr: { title: ["Bekle — bir bonus var", "Ayrılmadan önce: {amount} {currency} bonus"], text: "Yeni ziyaretçiler {amount} {currency} bonus alabilir. Ayrılmadan önce bir göz at.", button: "Bonusu gör" },
            uk: { title: ["Зачекайте — для вас є бонус", "Перед тим як піти: бонус {amount} {currency}"], text: "Новим відвідувачам доступний бонус {amount} {currency}. Загляньте, перш ніж піти.", button: "Переглянути бонус" },
            zh: { title: ["等一下——有一份奖励", "离开前：{amount} {currency} 奖励"], text: "新访客可获得 {amount} {currency} 奖励，离开前不妨看看。", button: "查看奖励" }
        },
        gambling: {
            amount: 250,
            money: true,
            en: { title: ['Your welcome pack is waiting', 'Free spins — one tap away'], text: 'Register in under a minute and get a {amount} {currency} bonus plus free spins on your first deposit.', button: 'Play now' },
            ru: { title: ['Ваш приветственный пакет ждёт', 'Фриспины — в одном клике'], text: 'Регистрация за минуту — и на первый депозит вы получите бонус {amount} {currency} и фриспины.', button: 'Играть' },
            es: { title: ['Tu paquete de bienvenida te espera', 'Giros gratis a un toque'], text: 'Regístrate en menos de un minuto y recibe un bono de {amount} {currency} más giros gratis en tu primer depósito.', button: 'Jugar ya' },
            pt: { title: ['Seu pacote de boas-vindas espera', 'Rodadas grátis a um toque'], text: 'Cadastre-se em menos de um minuto e ganhe um bônus de {amount} {currency} e rodadas grátis no primeiro depósito.', button: 'Jogar agora' },
            fr: { title: ['Votre pack de bienvenue attend', 'Des tours gratuits à un clic'], text: "Inscrivez-vous en moins d'une minute et recevez un bonus de {amount} {currency} plus des tours gratuits sur votre premier dépôt.", button: 'Jouer' },
            de: { title: ['Ihr Willkommenspaket wartet', 'Freispiele — ein Klick entfernt'], text: 'Registrieren Sie sich in unter einer Minute und erhalten Sie einen Bonus von {amount} {currency} plus Freispiele auf die erste Einzahlung.', button: 'Jetzt spielen' },
            it: { title: ['Il tuo pacchetto di benvenuto aspetta', 'Giri gratis a un tocco'], text: 'Registrati in meno di un minuto e ottieni un bonus di {amount} {currency} più giri gratuiti sul primo deposito.', button: 'Gioca ora' },
            pl: { title: ['Twój pakiet powitalny czeka', 'Darmowe spiny — jedno dotknięcie'], text: 'Zarejestruj się w niecałą minutę i otrzymaj bonus {amount} {currency} oraz darmowe spiny do pierwszego depozytu.', button: 'Zagraj teraz' },
            tr: { title: ['Hoş geldin paketin seni bekliyor', 'Bedava dönüşler bir dokunuş ötede'], text: 'Bir dakikadan kısa sürede kayıt ol, ilk yatırımına {amount} {currency} bonus ve bedava dönüşler kazan.', button: 'Hemen oyna' },
            uk: { title: ['Ваш вітальний пакет чекає', 'Фріспіни — за один клік'], text: 'Реєстрація за хвилину — і на перший депозит ви отримаєте бонус {amount} {currency} та фріспіни.', button: 'Грати' },
            zh: { title: ['您的新手礼包在等你', '免费旋转——一键即得'], text: '一分钟内注册，首存即获 {amount} {currency} 奖励和免费旋转。', button: '立即游戏' }
        },
        betting: {
            amount: 100,
            money: true,
            en: { title: ['Free bet: {amount} {currency}', "Don't leave your free bet unused"], text: 'Sign up and place your first bet with a {amount} {currency} free bet — the stake is on us, win or lose.', button: 'Place a bet' },
            ru: { title: ['Фрибет на {amount} {currency}', 'Не оставляйте фрибет неиспользованным'], text: 'Регистрируйтесь и ставьте первую ставку фрибетом {amount} {currency} — ставка за наш счёт, выигрыш ваш.', button: 'Сделать ставку' },
            es: { title: ['Apuesta gratis: {amount} {currency}', 'No dejes tu apuesta gratis sin usar'], text: 'Regístrate y haz tu primera apuesta con una apuesta gratis de {amount} {currency} — la arriesgamos nosotros.', button: 'Apostar ya' },
            pt: { title: ['Aposta grátis: {amount} {currency}', 'Não deixe sua aposta grátis sem usar'], text: 'Cadastre-se e faça sua primeira aposta com uma aposta grátis de {amount} {currency} — o risco é nosso.', button: 'Apostar agora' },
            fr: { title: ['Pari gratuit : {amount} {currency}', "N'oubliez pas votre pari gratuit"], text: 'Inscrivez-vous et placez votre premier pari avec un pari gratuit de {amount} {currency} — le risque est pour nous.', button: 'Parier' },
            de: { title: ['Freiwette: {amount} {currency}', 'Lassen Sie Ihre Freiwette nicht liegen'], text: 'Registrieren Sie sich und platzieren Sie Ihre erste Wette mit einer {amount} {currency} Freiwette — das Risiko liegt bei uns.', button: 'Jetzt wetten' },
            it: { title: ['Scommessa gratis: {amount} {currency}', 'Non lasciare la tua scommessa gratis'], text: 'Registrati e piazza la tua prima scommessa con una scommessa gratis di {amount} {currency} — il rischio è nostro.', button: 'Scommetti ora' },
            pl: { title: ['Darmowy zakład: {amount} {currency}', 'Nie zostawiaj darmowego zakładu'], text: 'Zarejestruj się i postaw pierwszy kupon z darmowym zakładem {amount} {currency} — ryzyko bierzemy na siebie.', button: 'Obstaw teraz' },
            tr: { title: ['Ücretsiz kupon: {amount} {currency}', 'Ücretsiz kuponunu kullanmadan gitme'], text: 'Kayıt ol ve ilk bahsini {amount} {currency} ücretsiz kuponla oyna — risk bizde, kazanç senin.', button: 'Bahis yap' },
            uk: { title: ['Фрібет на {amount} {currency}', 'Не залишайте фрібет невикористаним'], text: 'Реєструйтесь і робіть першу ставку фрібетом {amount} {currency} — ризик за наш рахунок, виграш ваш.', button: 'Зробити ставку' },
            zh: { title: ['免费投注：{amount} {currency}', '别浪费您的免费注单'], text: '注册即享 {amount} {currency} 免费投注——输赢风险由我们承担。', button: '立即投注' }
        },
        nutra: {
            amount: 50,
            en: { title: ["-{amount}% off your order", "Before you go: -{amount}% off"], text: "Order now and get -{amount}% off the regular price.", button: "Order with discount" },
            ru: { title: ["Скидка -{amount}% на заказ", "Перед уходом: скидка -{amount}%"], text: "Оформите заказ сейчас и получите скидку -{amount}% от обычной цены.", button: "Заказать со скидкой" },
            es: { title: ["-{amount}% en tu pedido", "Antes de irte: -{amount}% de descuento"], text: "Haz tu pedido ahora y obtén un -{amount}% sobre el precio habitual.", button: "Pedir con descuento" },
            pt: { title: ["-{amount}% no seu pedido", "Antes de sair: -{amount}% de desconto"], text: "Faça seu pedido agora e ganhe -{amount}% sobre o preço normal.", button: "Pedir com desconto" },
            fr: { title: ["-{amount}% sur votre commande", "Avant de partir : -{amount}% de remise"], text: "Commandez maintenant et profitez de -{amount}% sur le prix habituel.", button: "Commander avec remise" },
            de: { title: ["-{amount}% auf Ihre Bestellung", "Bevor Sie gehen: -{amount}% Rabatt"], text: "Bestellen Sie jetzt und erhalten Sie -{amount}% auf den regulären Preis.", button: "Mit Rabatt bestellen" },
            it: { title: ["-{amount}% sul tuo ordine", "Prima di andare: -{amount}% di sconto"], text: "Ordina ora e ottieni il -{amount}% sul prezzo normale.", button: "Ordina con sconto" },
            pl: { title: ["-{amount}% na zamówienie", "Zanim wyjdziesz: -{amount}% rabatu"], text: "Zamów teraz i otrzymaj -{amount}% od regularnej ceny.", button: "Zamów z rabatem" },
            tr: { title: ["Siparişinde %{amount} indirim", "Ayrılmadan önce: %{amount} indirim"], text: "Şimdi sipariş ver, normal fiyattan %{amount} indirim kazan.", button: "İndirimle sipariş ver" },
            uk: { title: ["Знижка -{amount}% на замовлення", "Перед тим як піти: знижка -{amount}%"], text: "Оформіть замовлення зараз і отримайте знижку -{amount}% від звичайної ціни.", button: "Замовити зі знижкою" },
            zh: { title: ["订单立减 {amount}%", "离开前：立减 {amount}%"], text: "立即下单，即享原价立减 {amount}% 优惠。", button: "立即下单" }
        },
        ecom: {
            amount: 40,
            en: { title: ["Save {amount}% today", "Before you go — {amount}% off"], text: "Get -{amount}% on your order. The discount applies in one tap.", button: "Apply discount" },
            ru: { title: ["Сегодня скидка {amount}%", "Перед уходом — скидка {amount}%"], text: "Получите -{amount}% на заказ. Скидка применяется в одно нажатие.", button: "Применить скидку" },
            es: { title: ["Ahorra un {amount}% hoy", "Antes de irte — {amount}% de descuento"], text: "Obtén un -{amount}% en tu pedido. El descuento se aplica con un toque.", button: "Aplicar descuento" },
            pt: { title: ["Economize {amount}% hoje", "Antes de sair — {amount}% de desconto"], text: "Ganhe -{amount}% no seu pedido. O desconto é aplicado com um toque.", button: "Aplicar desconto" },
            fr: { title: ["Économisez {amount}% aujourd’hui", "Avant de partir — {amount}% de remise"], text: "Profitez de -{amount}% sur votre commande. La remise s’applique en un geste.", button: "Appliquer la remise" },
            de: { title: ["Heute {amount}% sparen", "Bevor Sie gehen — {amount}% Rabatt"], text: "Erhalten Sie -{amount}% auf Ihre Bestellung. Der Rabatt gilt mit einem Tipp.", button: "Rabatt anwenden" },
            it: { title: ["Risparmia il {amount}% oggi", "Prima di andare — {amount}% di sconto"], text: "Ottieni il -{amount}% sul tuo ordine. Lo sconto si applica con un tocco.", button: "Applica sconto" },
            pl: { title: ["Oszczędź dziś {amount}%", "Zanim wyjdziesz — {amount}% rabatu"], text: "Otrzymaj -{amount}% na zamówienie. Rabat zastosujesz jednym dotknięciem.", button: "Zastosuj rabat" },
            tr: { title: ["Bugün %{amount} tasarruf et", "Ayrılmadan önce — %{amount} indirim"], text: "Siparişinde %{amount} indirim kazan. İndirim tek dokunuşla uygulanır.", button: "İndirimi uygula" },
            uk: { title: ["Сьогодні знижка {amount}%", "Перед тим як піти — знижка {amount}%"], text: "Отримайте -{amount}% на замовлення. Знижка застосовується одним дотиком.", button: "Застосувати знижку" },
            zh: { title: ["今天立省 {amount}%", "离开前——立减 {amount}%"], text: "订单可享 {amount}% 优惠，一键即可使用。", button: "使用优惠" }
        },
        finance: {
            amount: 3000,
            money: true,
            en: { title: ["Loans up to {amount} {currency}", "Check your loan offer"], text: "See how much you can get — up to {amount} {currency}. Apply online in a few minutes, no branch visits.", button: "Check offer" },
            ru: { title: ["Займы до {amount} {currency}", "Узнайте своё предложение по займу"], text: "Узнайте, сколько можно получить — до {amount} {currency}. Заявка онлайн за несколько минут, без визитов в офис.", button: "Узнать условия" },
            es: { title: ["Préstamos de hasta {amount} {currency}", "Consulta tu oferta de préstamo"], text: "Descubre cuánto puedes obtener: hasta {amount} {currency}. Solicítalo en línea en pocos minutos, sin ir a una sucursal.", button: "Ver oferta" },
            pt: { title: ["Empréstimos de até {amount} {currency}", "Confira sua oferta de empréstimo"], text: "Veja quanto você pode receber — até {amount} {currency}. Peça online em poucos minutos, sem ir a uma agência.", button: "Ver oferta" },
            fr: { title: ["Prêts jusqu’à {amount} {currency}", "Découvrez votre offre de prêt"], text: "Découvrez combien vous pouvez obtenir — jusqu’à {amount} {currency}. Demande en ligne en quelques minutes, sans passer en agence.", button: "Voir l’offre" },
            de: { title: ["Kredite bis {amount} {currency}", "Prüfen Sie Ihr Kreditangebot"], text: "Sehen Sie, wie viel Sie bekommen können — bis zu {amount} {currency}. Online beantragen in wenigen Minuten, ohne Filialbesuch.", button: "Angebot prüfen" },
            it: { title: ["Prestiti fino a {amount} {currency}", "Scopri la tua offerta di prestito"], text: "Scopri quanto puoi ottenere — fino a {amount} {currency}. Richiesta online in pochi minuti, senza andare in filiale.", button: "Vedi l’offerta" },
            pl: { title: ["Pożyczki do {amount} {currency}", "Sprawdź swoją ofertę pożyczki"], text: "Sprawdź, ile możesz otrzymać — do {amount} {currency}. Wniosek online w kilka minut, bez wizyty w oddziale.", button: "Sprawdź ofertę" },
            tr: { title: ["{amount} {currency} kadar kredi", "Kredi teklifini kontrol et"], text: "Ne kadar alabileceğini gör — {amount} {currency} kadar. Şubeye gitmeden, birkaç dakikada online başvur.", button: "Teklifi gör" },
            uk: { title: ["Позики до {amount} {currency}", "Дізнайтеся свою пропозицію щодо позики"], text: "Дізнайтеся, скільки можна отримати — до {amount} {currency}. Заявка онлайн за кілька хвилин, без візитів до офісу.", button: "Дізнатися умови" },
            zh: { title: ["最高 {amount} {currency} 贷款", "查看您的贷款方案"], text: "看看您最多能贷多少——最高 {amount} {currency}。几分钟在线申请，无需前往网点。", button: "查看方案" }
        },
        crypto: {
            amount: 100,
            en: { title: ['+{amount}% on your first deposit', 'Your crypto bonus is active'], text: 'Deposit today and get a +{amount}% bonus credited instantly. Trade the top coins from day one.', button: 'Start trading' },
            ru: { title: ['+{amount}% к первому депозиту', 'Ваш крипто-бонус активен'], text: 'Пополните счёт сегодня — бонус +{amount}% зачислится мгновенно. Торгуйте топовыми монетами с первого дня.', button: 'Начать торговлю' },
            es: { title: ['+{amount}% en tu primer depósito', 'Tu bono cripto está activo'], text: 'Deposita hoy y recibe un bono del +{amount}% acreditado al instante. Opera con las principales monedas desde el primer día.', button: 'Empezar a operar' },
            pt: { title: ['+{amount}% no primeiro depósito', 'Seu bônus cripto está ativo'], text: 'Deposite hoje e receba +{amount}% creditados na hora. Opere as principais moedas desde o primeiro dia.', button: 'Começar a operar' },
            fr: { title: ['+{amount}% sur votre premier dépôt', 'Votre bonus crypto est actif'], text: 'Déposez aujourd\u2019hui et recevez un bonus de +{amount}% crédité instantanément. Tradez les principales cryptos dès le premier jour.', button: 'Commencer' },
            de: { title: ['+{amount}% auf Ihre erste Einzahlung', 'Ihr Krypto-Bonus ist aktiv'], text: 'Zahlen Sie heute ein und erhalten Sie +{amount}% sofort gutgeschrieben. Handeln Sie die Top-Coins ab Tag eins.', button: 'Jetzt starten' },
            it: { title: ['+{amount}% sul primo deposito', 'Il tuo bonus crypto è attivo'], text: 'Deposita oggi e ricevi un bonus +{amount}% accreditato all\u2019istante. Scambia le top crypto dal primo giorno.', button: 'Inizia ora' },
            pl: { title: ['+{amount}% do pierwszego depozytu', 'Twój bonus krypto jest aktywny'], text: 'Zdeponuj dziś i otrzymaj bonus +{amount}% zaliczony od razu. Handluj topowymi kryptowalutami od pierwszego dnia.', button: 'Zacznij handlować' },
            tr: { title: ['İlk yatırımına +%{amount}', 'Kripto bonusun aktif'], text: 'Bugün yatır, +%{amount} bonus anında hesabına geçsin. İlk günden en iyi coinlerle işlem yap.', button: 'İşleme başla' },
            uk: { title: ['+{amount}% до першого депозиту', 'Ваш крипто-бонус активний'], text: 'Поповніть рахунок сьогодні — бонус +{amount}% зарахується миттєво. Торгуйте топовими монетами з першого дня.', button: 'Почати торгівлю' },
            zh: { title: ['首存加赠 {amount}%', '您的加密奖励已激活'], text: '今日充值即享 +{amount}% 奖励，实时到账，第一天起交易主流币。', button: '开始交易' }
        }
    };

    // Default illustrations — compact inline SVG, no requests, nothing to
    // upload. The operator can override any of them from the media library.
    var ART = {
        neutral: "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 96 96'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' y1='0' x2='1' y2='1'%3E%3Cstop offset='0' stop-color='%236366f1'/%3E%3Cstop offset='1' stop-color='%238b5cf6'/%3E%3C/linearGradient%3E%3C/defs%3E%3Crect x='18' y='40' width='60' height='42' rx='6' fill='url(%23g)'/%3E%3Crect x='14' y='32' width='68' height='14' rx='4' fill='%234f46e5'/%3E%3Crect x='43' y='32' width='10' height='50' fill='%23c7d2fe'/%3E%3Cpath d='M48 32c-12-2-20-8-18-15 2-6 12-5 18 8 6-13 16-14 18-8 2 7-6 13-18 15z' fill='%23f43f5e'/%3E%3C/svg%3E",
        gambling: "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 96 96'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' y1='0' x2='1' y2='1'%3E%3Cstop offset='0' stop-color='%23f59e0b'/%3E%3Cstop offset='1' stop-color='%23d97706'/%3E%3C/linearGradient%3E%3C/defs%3E%3Ccircle cx='48' cy='48' r='30' fill='url(%23g)'/%3E%3Ccircle cx='48' cy='48' r='21' fill='%23fffbeb'/%3E%3Crect x='44' y='32' width='8' height='32' rx='2' fill='%237c2d12'/%3E%3Crect x='32' y='44' width='32' height='8' rx='2' fill='%237c2d12'/%3E%3Ccircle cx='48' cy='30' r='7' fill='%23f43f5e'/%3E%3Ccircle cx='30' cy='48' r='7' fill='%2316a34a'/%3E%3Ccircle cx='66' cy='48' r='7' fill='%2316a34a'/%3E%3Ccircle cx='48' cy='66' r='7' fill='%23f43f5e'/%3E%3C/svg%3E",
        betting: "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 96 96'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' y1='0' x2='1' y2='1'%3E%3Cstop offset='0' stop-color='%2322c55e'/%3E%3Cstop offset='1' stop-color='%2316a34a'/%3E%3C/linearGradient%3E%3C/defs%3E%3Ccircle cx='48' cy='50' r='30' fill='url(%23g)'/%3E%3Cpath d='M48 28l12 9-5 13H41l-5-13z' fill='%23fff'/%3E%3Cpath d='M48 72l-11-8 4-12h14l4 12z' fill='%23f8fafc'/%3E%3Cpath d='M36 49l4-12M60 49l-4-12M44 60l-4 12M52 60l4 12' stroke='%2316572f' stroke-width='2' fill='none'/%3E%3C/svg%3E",
        nutra: "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 96 96'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' y1='0' x2='1' y2='1'%3E%3Cstop offset='0' stop-color='%2334d399'/%3E%3Cstop offset='1' stop-color='%23059669'/%3E%3C/linearGradient%3E%3C/defs%3E%3Cpath d='M30 20h36v56a10 10 0 01-10 10H40a10 10 0 01-10-10z' fill='url(%23g)'/%3E%3Cpath d='M30 20h36v14a18 14 0 01-36 0z' fill='%23fff' opacity='.85'/%3E%3Crect x='40' y='44' width='16' height='42' rx='8' fill='%23fff' opacity='.9' transform='rotate(28 48 65)'/%3E%3C/svg%3E",
        ecom: "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 96 96'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' y1='0' x2='1' y2='1'%3E%3Cstop offset='0' stop-color='%2338bdf8'/%3E%3Cstop offset='1' stop-color='%230284c7'/%3E%3C/linearGradient%3E%3C/defs%3E%3Cpath d='M26 34h44l-4 44a8 8 0 01-8 7H38a8 8 0 01-8-7z' fill='url(%23g)'/%3E%3Cpath d='M34 34v-6a14 14 0 0128 0v6' fill='none' stroke='%23036591' stroke-width='6' stroke-linecap='round'/%3E%3Ccircle cx='66' cy='26' r='14' fill='%23f43f5e'/%3E%3Ctext x='66' y='32' font-family='Arial,sans-serif' font-size='16' font-weight='700' fill='%23fff' text-anchor='middle'%3E-%3C/text%3E%3C/svg%3E",
        finance: "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 96 96'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' y1='0' x2='1' y2='1'%3E%3Cstop offset='0' stop-color='%23fbbf24'/%3E%3Cstop offset='1' stop-color='%23b45309'/%3E%3C/linearGradient%3E%3C/defs%3E%3Cellipse cx='48' cy='30' rx='26' ry='10' fill='%23fde68a'/%3E%3Cpath d='M22 30v14c0 6 12 10 26 10s26-4 26-10V30' fill='url(%23g)'/%3E%3Cellipse cx='48' cy='48' rx='26' ry='10' fill='%23fde68a' opacity='.9'/%3E%3Cpath d='M22 48v14c0 6 12 10 26 10s26-4 26-10V48' fill='url(%23g)'/%3E%3Cellipse cx='48' cy='66' rx='26' ry='10' fill='%23fde68a' opacity='.8'/%3E%3C/svg%3E",
        crypto: "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 96 96'%3E%3Cdefs%3E%3ClinearGradient id='g' x1='0' y1='0' x2='1' y2='1'%3E%3Cstop offset='0' stop-color='%23f7931a'/%3E%3Cstop offset='1' stop-color='%23c2410c'/%3E%3C/linearGradient%3E%3C/defs%3E%3Ccircle cx='48' cy='48' r='32' fill='url(%23g)'/%3E%3Ccircle cx='48' cy='48' r='24' fill='%23fff7ed'/%3E%3Ctext x='48' y='62' font-family='Arial,sans-serif' font-size='34' font-weight='700' fill='%23c2410c' text-anchor='middle'%3E%E2%82%BF%3C/text%3E%3C/svg%3E"
    };

    // Accent color per design, used when the operator has not set one.
    var DESIGN_ACCENT = { card: '#4f46e5', gift: '#e11d48', alert: '#ea580c' };

    // === Bootstrap ==========================================================
    function config() {
        var el = document.querySelector('script[data-orbitra-popup]');
        if (!el) { return null; }
        try { return JSON.parse(el.getAttribute('data-orbitra-popup') || ''); }
        catch (e) { return null; }
    }

    function beacon(url) {
        try { if (navigator.sendBeacon && navigator.sendBeacon(url)) { return; } } catch (e) {}
        try { if (window.fetch) { fetch(url, { keepalive: true, mode: 'no-cors' }).catch(function () {}); return; } } catch (e) {}
        try { new Image().src = url; } catch (e) {}
    }

    function storeGet(key) {
        try { return window.localStorage.getItem(key); } catch (e) {}
        var m = document.cookie.match(new RegExp('(?:^|;\\s*)' + key + '=([^;]*)'));
        return m ? m[1] : null;
    }

    function storeSet(key, value, maxAgeSeconds) {
        try { window.localStorage.setItem(key, value); } catch (e) {}
        try {
            document.cookie = key + '=' + value + '; path=/; max-age=' + maxAgeSeconds
                + (location.protocol === 'https:' ? '; Secure' : '') + '; SameSite=Lax';
        } catch (e) {}
    }

    // Once per visitor per N hours (localStorage + cookie). 0 = every page.
    function frequencyOk(cfg) {
        if (!cfg.frequencyHours) { return true; }
        var seen = parseInt(storeGet('orbitra_pp') || '0', 10) || 0;
        return (Date.now() - seen) >= cfg.frequencyHours * 3600 * 1000;
    }

    // === Language & copy ====================================================
    function resolveLanguage(cfg, pack) {
        var allowed = null;
        if (cfg.languages) {
            allowed = {};
            String(cfg.languages).split(',').forEach(function (l) {
                var k = String(l).trim().toLowerCase();
                if (k) { allowed[k] = true; }
            });
        }
        function pick(code) {
            if (!code) { return null; }
            code = String(code).toLowerCase();
            if (pack[code]) { return (!allowed || allowed[code]) ? code : null; }
            var base = code.split('-')[0];
            if (pack[base] && (!allowed || allowed[base] || allowed[code])) { return base; }
            return null;
        }
        if (cfg.langMode === 'forced') {
            var forced = pick(cfg.lang);
            if (forced) { return forced; }
        } else {
            var nav = navigator.languages || [navigator.language || ''];
            for (var i = 0; i < nav.length; i++) {
                var hit = pick(nav[i]);
                if (hit) { return hit; }
            }
        }
        return 'en';
    }

    function countryName(iso, lang) {
        try {
            if (window.Intl && Intl.DisplayNames) {
                var dn = new Intl.DisplayNames([lang], { type: 'region' });
                var name = dn.of(iso);
                if (name && name !== iso) { return name; }
            }
        } catch (e) {}
        return iso;
    }

    // Rounds a converted amount to a figure a person would print on a banner:
    // two significant digits snapped to 1 / 1.5 / 2 / 2.5 / 3 / 4 / 5 / 7.5.
    // 250 USD -> 220 EUR -> 200 €; 250 USD -> 6.5M VND -> 7 500 000 ₫.
    function niceAmount(x) {
        if (!(x > 0)) { return x; }
        var p = Math.pow(10, Math.floor(Math.log(x) / Math.LN10));
        var steps = [1, 1.5, 2, 2.5, 3, 4, 5, 7.5, 10];
        var best = steps[0], diff = Infinity;
        for (var i = 0; i < steps.length; i++) {
            var d = Math.abs(x / p - steps[i]);
            if (d < diff) { diff = d; best = steps[i]; }
        }
        return Math.round(best * p);
    }

    function buildCopy(cfg) {
        var vertical = TEXTS[cfg.vertical] ? cfg.vertical : 'neutral';
        var pack = TEXTS[vertical];
        var lang = resolveLanguage(cfg, pack);
        var set = pack[lang] || pack.en;

        var amount = cfg.amount !== undefined && cfg.amount !== null && String(cfg.amount) !== ''
            ? cfg.amount : pack.amount;
        var amountNum = Number(amount);
        if (pack.money && isFinite(amountNum) && cfg.geo && Number(cfg.geo.rate) > 0) {
            amountNum = niceAmount(amountNum * Number(cfg.geo.rate));
        }
        var amountStr = isFinite(amountNum)
            ? amountNum.toLocaleString(lang === 'en' ? 'en-US' : lang)
            : String(amount);

        var vars = {
            amount: amountStr,
            currency: (cfg.geo && cfg.geo.currency) || '$',
            country: countryName((cfg.geo && cfg.geo.country) || '', lang)
        };
        function subst(s) {
            return String(s).replace(/\{(amount|currency|country)\}/g, function (_, k) {
                return vars[k];
            });
        }

        var titleIdx = Math.floor(Math.random() * set.title.length);
        var copy = {
            title: subst(set.title[titleIdx]),
            text: subst(set.text),
            button: subst(set.button),
            lang: lang
        };
        // Operator overrides from settings win over the dictionary.
        if (cfg.customTexts) {
            if (cfg.customTexts.title) { copy.title = subst(cfg.customTexts.title); titleIdx = 0; }
            if (cfg.customTexts.text) { copy.text = subst(cfg.customTexts.text); }
            if (cfg.customTexts.button) { copy.button = subst(cfg.customTexts.button); }
        }
        copy.titleIdx = titleIdx;
        copy.art = cfg.image || ART[vertical] || ART.neutral;
        return copy;
    }

    // === Rendering ==========================================================
    var CSS = ''
        + '.opp-root{position:fixed;inset:0;z-index:2147483000;display:flex;align-items:center;justify-content:center;padding:16px;background:rgba(15,23,42,.55);-webkit-backdrop-filter:blur(2px);backdrop-filter:blur(2px);font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif}'
        + '.opp-root.opp-pos-bottom{align-items:flex-end;padding:0}'
        + '.opp-card{position:relative;box-sizing:border-box;width:100%;max-width:380px;background:#fff;border-radius:20px;padding:26px 22px 22px;text-align:center;box-shadow:0 24px 64px rgba(2,6,23,.35);color:#0f172a}'
        + '.opp-pos-bottom .opp-card{max-width:520px;border-radius:24px 24px 0 0;padding-bottom:calc(22px + env(safe-area-inset-bottom,0px))}'
        + '.opp-card.opp-design-alert{border:3px solid var(--opp-accent)}'
        + '.opp-close{position:absolute;z-index:1;top:10px;right:10px;width:30px;height:30px;border:0;border-radius:50%;background:rgba(255,255,255,.9);color:#64748b;font-size:20px;line-height:30px;cursor:pointer;font-family:inherit}'
        + '.opp-close:hover{background:#f1f5f9;color:#475569}'
        + '.opp-img{display:block;width:88px;height:88px;margin:0 auto 12px;-webkit-user-drag:none;user-select:none}'
        + '.opp-img.opp-photo{width:100%;height:168px;object-fit:cover;border-radius:14px;margin:0 0 16px;background:#f1f5f9}'
        + '.opp-pos-bottom .opp-img.opp-photo{height:150px}'
        + '.opp-title{margin:0 0 8px;font-size:20px;line-height:1.25;font-weight:800;letter-spacing:-.01em}'
        + '.opp-text{margin:0 0 18px;font-size:14.5px;line-height:1.5;color:#475569}'
        + '.opp-btn{display:block;width:100%;box-sizing:border-box;padding:14px 16px;border:0;border-radius:12px;background:linear-gradient(135deg,var(--opp-accent),var(--opp-accent-2));color:#fff;font-size:16px;font-weight:700;text-decoration:none;cursor:pointer;box-shadow:0 10px 24px -8px var(--opp-accent);transition:transform .12s ease,box-shadow .12s ease}'
        + '.opp-btn:hover{transform:translateY(-1px);box-shadow:0 14px 28px -8px var(--opp-accent)}'
        + '.opp-note{margin:10px 0 0;font-size:11.5px;color:#94a3b8}'
        + '.opp-root.opp-anim-fade .opp-card{animation:opp-fade .25s ease both}'
        + '.opp-root.opp-anim-zoom .opp-card{animation:opp-zoom .28s cubic-bezier(.2,.9,.3,1.2) both}'
        + '.opp-root.opp-anim-slide .opp-card{animation:opp-slide .3s cubic-bezier(.2,.9,.3,1.1) both}'
        + '@keyframes opp-fade{from{opacity:0}to{opacity:1}}'
        + '@keyframes opp-zoom{from{opacity:0;transform:scale(.92)}to{opacity:1;transform:scale(1)}}'
        + '@keyframes opp-slide{from{opacity:0;transform:translateY(34px)}to{opacity:1;transform:translateY(0)}}'
        + '@media (prefers-reduced-motion:reduce){.opp-card{animation:none!important;transition:none!important}}';

    function darken(hex, fallback) {
        if (!/^#[0-9a-f]{6}$/i.test(String(hex || ''))) { return fallback; }
        var n = parseInt(hex.slice(1), 16);
        var r = Math.max(0, (n >> 16) - 28), g = Math.max(0, ((n >> 8) & 255) - 28), b = Math.max(0, (n & 255) - 28);
        return '#' + ((r << 16) | (g << 8) | b).toString(16).padStart(6, '0');
    }

    function offerUrl(cfg) {
        var url = String(cfg.offer || '');
        if (!url) { return url; }
        // Current page GET params ride along (the tracker's /?_lp=1 handler
        // merges only its whitelisted keys, so nothing hostile gets through).
        var search = window.location.search.replace(/^\?/, '');
        if (search) { url += (url.indexOf('?') === -1 ? '?' : '&') + search; }
        // Mark the click for the network side too; sub_id_30 is the slot
        // least likely to be used by a landing's own choice widgets.
        url += (url.indexOf('?') === -1 ? '?' : '&') + 'sub_id_30=popup';
        return url;
    }

    function show(cfg) {
        var existing = document.getElementById('opp-root');
        if (existing) { existing.parentNode && existing.parentNode.removeChild(existing); }

        var copy = buildCopy(cfg);
        var design = cfg.designs && cfg.designs.length
            ? cfg.designs[Math.floor(Math.random() * cfg.designs.length)]
            : 'card';
        var accent = cfg.accent || DESIGN_ACCENT[design] || DESIGN_ACCENT.card;
        var variant = design + '.' + copy.titleIdx;

        if (!document.getElementById('opp-style')) {
            var style = document.createElement('style');
            style.id = 'opp-style';
            style.textContent = CSS;
            (document.head || document.documentElement).appendChild(style);
        }

        var root = document.createElement('div');
        root.id = 'opp-root';
        root.className = 'opp-root opp-anim-' + (cfg.animation || 'zoom')
            + (cfg.position === 'bottom' ? ' opp-pos-bottom' : '')
            + (' opp-design-' + design);
        root.style.setProperty('--opp-accent', accent);
        root.style.setProperty('--opp-accent-2', darken(accent, accent));

        var href = offerUrl(cfg);
        root.innerHTML =
            '<div class="opp-card" role="dialog" aria-modal="true">'
            + '<button type="button" class="opp-close" aria-label="Close">&times;</button>'
            + '<img class="opp-img' + (/^data:/.test(copy.art) ? '' : ' opp-photo') + '" alt="" src="' + copy.art.replace(/"/g, '&quot;') + '">'
            + '<h3 class="opp-title"></h3>'
            + '<p class="opp-text"></p>'
            + (href ? '<a class="opp-btn" href="' + href.replace(/"/g, '&quot;') + '" rel="noopener"></a>' : '')
            + '</div>';
        // textContent, not innerHTML interpolation: the copy may carry quotes
        // and angle brackets from operator custom texts.
        root.querySelector('.opp-title').textContent = copy.title;
        root.querySelector('.opp-text').textContent = copy.text;
        var btn = root.querySelector('.opp-btn');
        if (btn) { btn.textContent = copy.button; }

        function report(event) {
            var q = cfg.endpoint + '?action=popup&subid=' + encodeURIComponent(cfg.subid || '')
                + '&e=' + encodeURIComponent(event)
                + (event === 'close' ? '' : '&v=' + encodeURIComponent(variant))
                + '&_=' + Date.now();
            beacon(q);
        }

        function close() {
            report('close');
            detach();
        }
        function detach() {
            document.removeEventListener('keydown', onKey);
            if (root.parentNode) { root.parentNode.removeChild(root); }
        }
        function onKey(e) { if (e.key === 'Escape' || e.keyCode === 27) { close(); } }

        root.querySelector('.opp-close').addEventListener('click', close);
        root.addEventListener('click', function (e) { if (e.target === root) { close(); } });
        document.addEventListener('keydown', onKey);
        if (btn) {
            btn.addEventListener('click', function () {
                report('click');
                storeSet('orbitra_pp', String(Date.now()), Math.max(1, (cfg.frequencyHours || 24) * 3600));
            });
        }

        (document.body || document.documentElement).appendChild(root);
        // Frequency is written at SHOW time: a visitor who never saw it (left
        // before the gates opened) deserves one on the next page.
        storeSet('orbitra_pp', String(Date.now()), Math.max(1, (cfg.frequencyHours || 24) * 3600));
        report('shown');
    }

    // === Gates ==============================================================
    function arm(cfg) {
        var needPointer = !!cfg.requireActivity;
        var seen = { pointer: false, scroll: false, key: false, exit: false, depth: false };

        function onPointer() { seen.pointer = true; }
        function onScroll() { seen.scroll = true; dwellDeep(); }
        function onKey() { seen.key = true; }
        function onExit(e) {
            // Desktop-only trigger: the cursor leaving through the TOP of the
            // viewport. clientY <= 10 filters the bottom/side leaks.
            if (e.clientY !== undefined && e.clientY <= 10) { seen.exit = true; }
        }
        function dwellDeep() {
            if (!cfg.scrollDepth) { return; }
            try {
                var d = document.documentElement, b = document.body || {};
                var h = Math.max(d.scrollHeight || 0, b.scrollHeight || 0, d.offsetHeight || 0);
                var v = window.innerHeight || d.clientHeight || 0;
                var y = window.pageYOffset || d.scrollTop || 0;
                if (h > v && Math.round(((y + v) / h) * 100) >= cfg.scrollDepth) { seen.depth = true; }
            } catch (e) {}
        }

        try {
            document.addEventListener('mousemove', onPointer, { passive: true });
            document.addEventListener('touchstart', onPointer, { passive: true });
            document.addEventListener('pointerdown', onPointer, { passive: true });
            window.addEventListener('scroll', onScroll, { passive: true });
            document.addEventListener('keydown', onKey);
            if (cfg.exitIntent && window.matchMedia && matchMedia('(pointer: fine)').matches) {
                document.addEventListener('mouseout', onExit);
            }
        } catch (e) {}

        // Two DIFFERENT types, one of them pointer/touch — scroll or keyboard
        // alone never qualify (scripts fake those far too easily; same rule as
        // the pointer_activity hint). Scroll-depth and exit-intent are
        // triggers of their own, but with "wait for activity" on they still
        // need the pointer half.
        // With "wait for activity" off, the delay alone is what was asked for.
        function checkActivity() {
            if (!needPointer) { return true; }
            // A scripted scroll to the trigger depth must not open the gate on
            // its own: scroll depth and exit intent still need a real pointer.
            if (seen.depth || seen.exit) { return seen.pointer; }
            return seen.pointer && (seen.scroll || seen.key);
        }

        var delayLeft = (cfg.delay || 1) * 1000;
        var elapsedBefore = 0;
        var last = Date.now();
        var poll = setInterval(function () {
            var now = Date.now();
            if (document.hidden !== true) { elapsedBefore += now - last; }
            last = now;
            if (elapsedBefore < delayLeft) { return; }
            if (checkActivity()) {
                clearInterval(poll);
                var fire = function () { show(cfg); };
                if (document.hidden === true) {
                    document.addEventListener('visibilitychange', function onVis() {
                        if (document.hidden !== true) {
                            document.removeEventListener('visibilitychange', onVis);
                            fire();
                        }
                    });
                } else {
                    fire();
                }
            }
        }, 400);
        // Hard stop: a visitor who stays 10 minutes without qualifying never
        // gets a popup from this page load.
        setTimeout(function () { clearInterval(poll); }, 10 * 60 * 1000);
    }

    function boot() {
        var cfg = config();
        if (!cfg || !cfg.subid || !cfg.offer || !cfg.endpoint) { return; }
        if (document.getElementById('opp-root')) { return; }
        if (!frequencyOk(cfg)) { return; }
        arm(cfg);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', boot);
    } else {
        boot();
    }
})();
