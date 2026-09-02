# ТЗ: Web Push в PWA-лендингах Orbitra — почему не работает и что чинить

Дата: 2026-09-02. Основание: пуши не доходят при рабочем PWA-конструкторе.
Референсы разобраны: `themartz90/jellydash` (PHP + own-VAPID, ближайший аналог),
`AltanS/collie` (образцовый клиентский флоу), `kamru1i/qc-manager-app` (manifest/SW),
`ionicthemes/ionic-pwa` (Angular SwPush + Firebase FCM — **нам не подходит**, мы на
своём VAPID без посредников).

---

## 0. Диагноз

Цепочка `подписка → очередь → VAPID → push-сервис → устройство` у нас собрана
почти целиком (PushBase/PushSender/PushQueue/push_cron — RFC 8291 + 8292 честно
реализованы на openssl). **Обрыв на последнем метре: на устройстве нечему показать
уведомление.**

| # | Приоритет | Что сломано | Где |
|---|---|---|---|
| 1 | **P0** | В сгенерированном `sw.js` **нет обработчиков `push` и `notificationclick`**. Есть только `install`/`activate`/`fetch`. Push-сервис отвечает 201, воркер пишет `sent`, телефон молчит. | `core/PwaLanding.php::renderSw()` |
| 2 | **P0** | `afterPush()` ставит `orbitra_push_done` при **любом** исходе, включая упавший `subscribe()`; 10-секундный fail-safe редиректит посетителя на оффер и убивает незавершённый `fetch('/push_subscribe')` вместе со страницей. Итог: промпт показан, подписка не доехала, карточка больше никогда не покажется. | `PwaLanding.php` ~1159–1210 (чинится параллельно) |
| 3 | **P0** | Нет идемпотентной ре-синхронизации: `getSubscription()` не вызывается никогда. Один сетевой сбой = подписка потеряна навсегда. | там же |
| 4 | P1 | Нет проверки ротации VAPID. `push_vapid_generate` в панели молча убивает все существующие подписки: они остаются `is_active = 1`, но привязаны к старому ключу — вечные 403. | клиент + `api.php:4979` |
| 5 | P1 | `push_vapid_sub` по умолчанию `mailto:orbitra@localhost`. `web.push.apple.com` валидирует claim и отвечает 403 BadJwtToken → **все iOS-подписчики падают**, даже когда всё остальное работает. | `PushSender::VAPID_SUB_DEFAULT` |
| 6 | P1 | На инсталлах старше фикса `cli/push_cron.php` не прописан в crontab — очередь копится, `queued: 1` навсегда. | `install.sh`, маркер `# orbitra-push` |
| 7 | P1 | `/push_subscribe` не проверяет длины ключей. Кривой `p256dh`/`auth` кладётся в базу и потом вечно валит `encrypt()` в воркере. | `index.php:2630` |
| 8 | P2 | Нет тестовой отправки на своё устройство и понятной причины отказа — оператор не может отличить «нет разрешения» от «нет ключей» от «воркер стоит». | `PushBasePage.jsx` |
| 9 | P2 | Manifest: одна иконка 512 `purpose: any`. Нет 192, нет `maskable`, нет badge — Android рисует серый кружок вместо иконки в шторке. | `PwaLanding.php::renderManifest()` |

### Почему пункт 1 — не просто «не показывается»

`userVisibleOnly: true` — это контракт с браузером. Если пришёл push, а SW не
вызвал `showNotification()`, Chrome сам рисует «Этот сайт обновлён в фоне», а
после нескольких нарушений **отзывает подписку**. То есть текущий код не просто
не показывает пуши — он активно выжигает базу подписчиков.

---

## 1. Что берём у референсов

| Референс | Паттерн | Куда в Orbitra |
|---|---|---|
| **jellydash** `public/sw.js` | `push` → parse JSON → `showNotification(title, {body, icon, badge, tag, data:{url}})`; `notificationclick` → `matchAll` → `focus()`+`navigate()` иначе `openWindow()` | Т-1 |
| **jellydash** `PushSubscriptionValidator` | endpoint только https, без user/pass/fragment, ≤4096; `p256dh` = 65 байт после base64url-декода, `auth` = 16 | Т-4 |
| **jellydash** `WebPushSender` | `allow_redirects: false`; отдельный список `expired` (404/410) для прунинга | Т-6 (у нас уже есть, добить) |
| **jellydash** `push.js` | тестовый пуш сразу после успешной подписки — оператор видит, что канал живой | Т-8 |
| **collie** `lib/push.ts` | `enablePush()` идемпотентный: `getSubscription()` первым, есть подписка — просто переслать на сервер | Т-2 |
| **collie** `keysMatch()` | сравнение `sub.options.applicationServerKey` с текущим VAPID; не совпало — `unsubscribe()` + переподписка | Т-2 |
| **collie** `PushAvailability` | таксономия причин: `unsupported / insecure / server-off / denied / ready` вместо одной молчаливой пометки | Т-2, Т-8 |
| **collie** `setUserDisabled` | «больше не спрашивать» — только по явному действию юзера, никогда по сетевому сбою | Т-2 |
| **collie** `replaces` | устройство само называет свой прошлый endpoint — сироты после переустановки не копятся | Т-4 (опционально) |
| **qc-manager-app** `manifest.json` | 192 + 512 + `purpose: maskable` | Т-7 |
| ionic-pwa | Angular SwPush + FCM | **не берём** — Firebase нам не нужен, свой VAPID уже есть |

---

## 2. Задачи

### Т-1 (P0). `push` и `notificationclick` в сгенерированном SW

`core/PwaLanding.php::renderSw()` — добавить к существующим хендлерам:

```js
self.addEventListener('push', function (e) {
    var d = {};
    try { d = e.data ? e.data.json() : {}; } catch (err) { d = { body: e.data ? e.data.text() : '' }; }
    var opts = {
        body: d.body || '',
        icon: d.icon || './icon.png',
        badge: d.badge || undefined,
        tag: d.tag || 'orbitra',
        renotify: d.renotify !== false,
        requireInteraction: !!d.requireInteraction,
        data: { url: (d.data && d.data.url) || d.url || './' }
    };
    // Контракт userVisibleOnly: показать ОБЯЗАНЫ всегда, даже на битом payload.
    e.waitUntil(self.registration.showNotification(d.title || 'Notification', opts));
});

self.addEventListener('notificationclick', function (e) {
    e.notification.close();
    var target = (e.notification.data && e.notification.data.url) || './';
    e.waitUntil(self.clients.matchAll({ type: 'window', includeUncontrolled: true }).then(function (list) {
        for (var i = 0; i < list.length; i++) {
            if ('focus' in list[i]) {
                list[i].focus();
                if ('navigate' in list[i]) { try { list[i].navigate(target); } catch (err) {} }
                return;
            }
        }
        if (self.clients.openWindow) return self.clients.openWindow(target);
    }));
});

self.addEventListener('pushsubscriptionchange', function (e) {
    // Браузер сам ротировал подписку. Переподписаться и отправить новую на сервер,
    // иначе устройство молча выпадает из базы.
});
```

`pushsubscriptionchange` требует VAPID-ключ внутри SW — вшить его в тело
воркера тем же макросом `{vapid_public}`, что и в HTML (сейчас SW рендерится
без макро-подстановки — надо провести её и через `sw.js`).

**Acceptance:** DevTools → Application → Service Workers → Push с тестовым JSON
показывает системное уведомление; клик открывает `data.url`.

### Т-2 (P0). Клиентский флоу по образцу collie

`PwaLanding.php`, блок «Push subscription»:

1. Разделить `afterPush(reason)` на два пути: `dismissPush()` (юзер нажал «Не сейчас» / `denied` → ставим `orbitra_push_done`) и `failPush()` (сеть/SW/сервер упали → **не ставим флаг**, повторим в следующий запуск).
2. `syncPush()` при каждом старте standalone-приложения: `getSubscription()` → если есть и `applicationServerKey` совпадает с текущим VAPID → тихо переслать на `/push_subscribe` (само-восстановление); если не совпадает → `unsubscribe()` + переподписка; если нет — показать карточку (при `!pushDone`).
3. Fail-safe **не должен редиректить, пока летит `fetch`**: либо `keepalive: true` на fetch, либо переносить `performAppAction()` в `finally` цепочки, либо отправлять подписку через `navigator.sendBeacon()`.
4. Явные причины отказа в beacon: `unsupported / insecure / server-off / denied / error` вместо общего `decline` — это единственный способ потом понять по статистике, что именно ломается на проде.

**Acceptance:** отключить сеть → нажать «Разрешить» → подписка не сохранилась,
`orbitra_push_done` НЕ выставлен, при следующем запуске карточка снова показана
и подписка доезжает.

### Т-3 (P1). Контракт payload

`PushSender::send()` сейчас шлёт `{title, body, icon, data:{url}}`. Расширить до
`{title, body, icon, badge, tag, renotify, data:{url, message_id, subid}}` —
`tag` нужен, чтобы серия пушей по одному лиду схлопывалась в один слот, а не
заваливала шторку. Лимит записи aes128gcm ~4062 байта — обрезать `body` на
сервере, а не ловить исключение в воркере.

### Т-4 (P1). Валидация подписки на входе

`index.php:2630` — портировать `PushSubscriptionValidator`: https-only endpoint
без `user`/`pass`/`fragment`, `p256dh` ровно 65 байт, `auth` ровно 16 байт после
base64url-декода. Кривое — 400, в базу не кладём. Опционально: поле `replaces`
(collie) для деактивации предыдущего endpoint того же устройства.

### Т-5 (P1). VAPID: контакт и ротация

- `push_vapid_contact_save` уже есть — сделать поле **обязательным** перед первой
  генерацией ключей и показать предупреждение «без валидного контакта Apple
  вернёт 403 на все iOS-подписки».
- `push_vapid_generate`: перед перегенерацией показывать модалку «это отвяжет N
  существующих подписок»; после генерации помечать все `push_subscriptions`
  как требующие ре-синка (клиент из Т-2 сам переподпишется при следующем открытии).

### Т-6 (P1). Воркер и очередь

- Проверить на проде: `crontab -l | grep orbitra-push`. Нет — перезапустить
  `install.sh` (маркер-блоки идемпотентны) или добавить строку руками.
- 404/410 → `is_active = 0` (уже есть); 403 → писать `last_fail_code` и
  подсвечивать в панели как «проверьте VAPID-контакт»; 429 → уважать `Retry-After`.
- `allow_redirects: false` на HTTP-клиенте (jellydash) — редирект push-сервиса
  ломает подпись VAPID.

### Т-7 (P2). Manifest

Добавить 192×192 `purpose: any`, 512×512 `purpose: any` и 512×512
`purpose: maskable`, плюс монохромный badge для шторки Android.

### Т-8 (P2). Диагностика в панели

- Кнопка «Отправить тест на это устройство» (подписать браузер оператора и
  сразу отправить пуш) — по образцу `postJson('/api/push/test.php')` в jellydash.
- Панель состояния: ключи сгенерированы / контакт задан / воркер тикал N минут
  назад / активных подписок / последний код ошибки. Часть уже есть в
  `push_queue_list` — свести в один блок.

### Т-9 (P0, финальный шаг). Bump `RENDERER_VERSION`

`PwaLanding::RENDERER_VERSION` 12 → 13. Без этого маршрут `/lander/` не
перегенерирует статику существующих PWA, и все уже созданные приложения
останутся со старым `sw.js` без push-хендлера. Это ровно тот случай, ради
которого версия и заведена.

### Т-10. Тесты

- `tests/pwa_landing_test.php`: сгенерированный `sw.js` содержит `addEventListener('push'` и `'notificationclick'`; `{vapid_public}` подставляется и в SW.
- Новый тест на валидатор подписки (граничные длины ключей).
- `tests/push_sender_test.php`: payload содержит `tag`/`badge`; обрезка длинного body.

---

## 3. Порядок работ

**Спринт 1 (блокеры, один релиз):** Т-1 → Т-2 → Т-9 → Т-10.
Без Т-9 первые две задачи не доедут до боевых лендингов.

**Спринт 2:** Т-5, Т-6, Т-4, Т-3.

**Спринт 3:** Т-7, Т-8.

---

## 4. Ручная проверка после релиза

1. Сгенерировать VAPID-ключи, задать реальный `mailto:` контакт.
2. Android/Chrome: открыть `/lander/<slug>/`, установить, запустить с домашнего экрана, разрешить уведомления. Проверить строку в `push_subscriptions` с непустым `click_id`.
3. Панель → Push → «Отправить сейчас» → в течение минуты уведомление на телефоне; тап открывает нужный URL.
4. iOS 16.4+/Safari: то же самое **только из установленного на домашний экран приложения** — в самой Safari подписка невозможна.
5. Удалить приложение с телефона → следующая отправка даёт 410 → `is_active = 0`.
6. Перегенерировать VAPID → открыть приложение → подписка автоматически пересоздалась под новый ключ (Т-2).
7. Проверить `var/logs` — воркер пишет только ошибки (`--quiet`).

---

## 5. Отдельно: jellydash как источник фич для дашборда

К пушам не относится, но раз репозиторий разобран — что там осмысленно
посмотреть под наши задачи: модуль `src/Notifications/` с несколькими каналами
(Web Push / Telegram / Discord / Pushover) за одним `NotificationDispatcher` —
у нас Telegram и пуши живут отдельными ветками кода, их стоило бы свести;
`src/Updates/` (проверка новой версии и баннер в панели); структура страниц
статистики (Trending / Most Watched / Devices) как эталон разбиения одного
дашборда на вкладки. Разбирать отдельной задачей, не смешивая с пушами.

---

# Спринт 2: подписка по-прежнему не доезжает (продакшн, 2026-09-02)

Спринт 1 закрыт и раскатан: renderer 14, `sw.js` отдаётся с `push` /
`notificationclick` и подставленным `{vapid_public}`, `/push_subscribe`
отвечает, VAPID-контакт заполнен, крон стоит. Тем не менее база подписчиков
пустая. Ниже — что показал живой клик и что из этого следует.

## 0. Факты, а не догадки

Клик `e403008e-28f3-490f-9b21-a1d8b265622e`, лендинг `test5`
(`venerara.net.ru`), iPhone, iOS 18.7, Safari 26.6.1:

| поле | значение |
| --- | --- |
| `landing_at` | 18:52:17 |
| `pwa_install_at` | 18:52:33 |
| `pwa_open_at` | 18:52:33 |
| `push_prompted_at` | 18:52:35 |
| `push_declined_at` | **NULL** |
| `push_subscribed_at` | **NULL** |
| `push_fail_reason` | **NULL** |
| `offer_at` | 18:53:21 |

Читается однозначно:

1. `18:53:21 − 18:52:35 = 46 с` — это ровно `failSafe` на 45 000 мс из Т-2.
   Значит ветка «разрешение получено» отработала, а `syncSubscription()` за
   45 секунд не завершился ни успехом, ни ошибкой.
2. `push_declined_at IS NULL` ⇒ `Notification.requestPermission()` вернул
   `granted`. Отказа не было.
3. `push_fail_reason IS NULL`, хотя ветка таймаута обязана была отправить
   `beacon('pushfail', 'timeout')`. **Маяк не доехал** — см. Дефект 1.

Серверная половина проверена отдельно и исправна: `/lander/test5/sw.js`
отдаёт renderer-14 воркер с обоими обработчиками и подставленным ключом,
`manifest.webmanifest` корректен (`start_url` / `scope` = `./`),
`POST /push_subscribe` доступен и отвечает `{"status":"error","message":"Invalid
subscription"}` на пустое тело. `navigator.serviceWorker.ready` на десктопном
Chrome резолвится за 1 мс, `getSubscription()` — за 2 мс. Ломается клиент, и
ломается он на устройстве.

## 1. Корневая причина

`core/PwaLanding.php`, клиентский IIFE, порядок операторов:

```js
  if (isStandalone) {
    beacon('open');
    ...
    if (pushAvailable()) { showPush(); return; }   // <- выход из IIFE
    performAppAction();
  } else if (cfg.auto > 0) { ... }

  if ('serviceWorker' in navigator) {              // <- сюда уже не доходим
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('sw.js');
    });
  }
```

Регистрация сервис-воркера стояла **ниже** standalone-ветки, а та заканчивается
`return`. То есть в установленном приложении — единственном месте, где мы вообще
спрашиваем разрешение, — `navigator.serviceWorker.register()` **не вызывался
никогда**. На витрине в Safari `isStandalone === false`, до регистрации
доходило, воркер там появлялся — поэтому `sw.js` и выглядел живым при любой
внешней проверке. Но у Home Screen web app на iOS **своё хранилище**, отдельное
от Safari: внутри приложения регистрации не было, `navigator.serviceWorker.ready`
не резолвился никогда, флоу вставал намертво, через 45 с срабатывал fail-safe и
выкидывал визитёра на оффер. Это ровно то, что видел оператор: «нажимаю Allow —
ничего не происходит, через 45 секунд перекинуло на оффер».

Всё остальное ниже — то, что этот `return` прикрывал собой и что всплыло бы
следующим.

## 2. Дефекты

### Д-0 (P0). Воркер не регистрируется в установленном приложении

См. выше. Регистрация поднята в **самое начало** IIFE, до любой ветки с
`return`, и больше не ждёт `window.load` — визитёр физически может ответить на
карточку раньше, чем `load` придёт на медленной сети.

### Д-1 (P0). Диагностический маяк умирает вместе с редиректом

`beacon()` (`core/PwaLanding.php:1157`) шлёт `new Image(); img.src = ...`.
Ветка таймаута делает это и **тем же тиком** вызывает `afterPush(false)` →
`performAppAction()` → `window.location.href`. Навигация отменяет ещё не
ушедший запрос картинки. Отсюда `push_fail_reason IS NULL` при явно
сработавшем таймауте: система построена так, что причина отказа теряется
ровно в тот момент, когда она нужна.

Чинить: `navigator.sendBeacon()` (с фолбэком на `fetch(..., {keepalive:true})`
и `new Image()` для совсем старых браузеров) — он переживает навигацию по
контракту. Это первая задача спринта: без телеметрии остальное чинится
вслепую.

### Д-2 (P0). Успехом считается любой ответ сервера

```js
return fetch('/push_subscribe?...', {...}).then(function () { return true; });
```

`res.ok` не проверяется. Ингест, получивший 400 (валидатор длин ключей из
Т-4), 404 или 500, возвращает `ok = true` → `afterPush(true)` → в
`localStorage` ложится `orbitra_push_done`, и **этот браузер больше никогда
не спросит**. Один отказ сервера = навсегда сожжённый подписчик, при том что
в базе его нет.

Чинить: `ok` = `res.ok === true`. Всё остальное — `pushfail` с кодом статуса
в `reason`.

### Д-3 (P0, структурный). Подписка живёт в жизненном цикле страницы, а
страница обязана уйти на оффер

Сейчас `pushManager.subscribe()` держится документом, который через
`performAppAction()` уходит на внешний домен оффера. `failSafe` не страхует
флоу — он его **убивает**: по таймауту мы сами делаем навигацию, которая
сносит незавершённый `subscribe()`. Следующий открытие приложения запускает
silent-sync с потолком 30 с и точно так же обрывает его редиректом.
Это и есть тот livelock, который в `e6b9263` пытались вылечить, подняв
таймаут 10 → 45 с: окно стало шире, гонка осталась. Никакое значение
таймаута её не закрывает.

Чинить архитектурно: подписка должна пережить навигацию, а сервис-воркер её
переживает по определению.

- страница после ответа на промпт делает
  `registration.active.postMessage({type:'orbitra-subscribe', subid, lang})`
  и **сразу** отдаёт управление `performAppAction()` — визитёр не ждёт
  вообще;
- воркер в `message`-обработчике внутри `event.waitUntil()` делает
  `getSubscription()` → при необходимости `subscribe()` → `POST
  /push_subscribe` (код уже почти написан — это тело
  `pushsubscriptionchange`, его надо вынести в общую функцию);
- воркер отвечает странице `postMessage`-ом только если она ещё жива; если
  нет — ничего страшного, результат уже в базе;
- `orbitra_push_done` ставится по ответу визитёра (он ответил на промпт), а
  не по успеху транспорта — это уже так и есть и это правильно, но теперь
  «успех транспорта» перестаёт быть условием чего-либо на странице.

`subid` воркеру передаётся сообщением, потому что у воркера своего клика нет.
Второй путь — `client.postMessage` при `pushsubscriptionchange` — уже
покрыт: там `subid` не шлётся и ингест просто апсертит подписку по
`endpoint`, не трогая `clicks`.

### Д-4 (P1). iOS требует user activation на `subscribe()`

WebKit разрешает `pushManager.subscribe()` только при transient activation.
У нас он вызывается через три асинхронных перехода после клика
(`requestPermission().then` → `serviceWorker.ready.then` →
`getSubscription().then`), то есть активация давно потрачена. На Safari это
даёт либо `NotAllowedError`, либо (наблюдаемое здесь) промис, который не
резолвится вовсе. Перенос в воркер (Д-3) снимает вопрос: `subscribe()` из
`message`-обработчика к активации страницы не привязан. Если по итогам
Д-1 телеметрия покажет `NotAllowedError` — задача закрыта тем же Д-3.

Дополнительно: `navigator.serviceWorker.ready` в `enablePush()` вызывается
без собственного потолка. Ставим `Promise.race` с 5 с, как в silent-sync,
и репортим `pushfail:noreg` — «воркер не зарегистрировался» и «подписка не
создалась» это разные болезни, и различать их надо в цифрах.

### Д-5 (P1). Кэш воркера мёртв

`core/PwaLanding.php:485`:

```js
if (res && res.ok && url.pathname.indexOf(self.registration.scope) === 0) {
```

`url.pathname` — это `/lander/test5/…`, `self.registration.scope` — полный
URL `https://venerara.net.ru/lander/test5/`. `indexOf` всегда `-1`, условие
никогда не истинно, в кэш не кладётся ничего. Значит и офлайн-фолбэк
`caches.match('./')` в navigate-ветке всегда промахивается. Сравнивать надо
`url.href.indexOf(self.registration.scope) === 0` (или
`new URL(self.registration.scope).pathname`).

### Д-6 (P2). Регистрация воркера отложена до `load`

`navigator.serviceWorker.register('sw.js')` висит внутри
`window.addEventListener('load', …)`. На этом лендинге `load` приходит за
~350 мс, так что здесь это не причина, но визитёр физически может нажать
Allow раньше, чем регистрация вообще запрошена, — и тогда `ready` ждёт то,
чего ещё никто не просил. Регистрировать сразу, без ожидания `load`.

## 3. Критерии приёмки

1. На клике, где подписка не удалась, `push_fail_reason` **всегда** заполнен
   (`timeout` / `noreg` / `http400`…). Проверка: спровоцировать отказ и
   увидеть код в `click_details`, а не NULL.
2. HTTP-ответ ингеста, отличный от 2xx, не ставит `orbitra_push_done`.
3. Навигация на оффер сразу после ответа на промпт не мешает подписке
   доехать: в `push_subscriptions` появляется строка, в `clicks` —
   `push_subscribed_at`, даже если визитёр уже на домене оффера.
4. Ручная проверка на реальном iPhone (iOS 17+) и Android Chrome: установка
   → Allow → переход на оффер без задержки → строка в «Пуш-базе» в течение
   нескольких секунд → тестовый пуш доходит и открывает нужный URL.
5. `RENDERER_VERSION` **14 → 15**, иначе ни один уже созданный PWA не
   получит ни новый `sw.js`, ни новый клиент.

## 4. Порядок

Д-0 → Д-1 (телеметрия) → Д-2 → Д-3 (перенос в воркер) → Д-4 (потолок на `ready`)
→ Д-5 → Д-6 → bump renderer → тесты (`tests/push_base_test.php`: ингест
отвечает 400 на кривые ключи — добавить кейс «клиент не считает 400
успехом», и фикстура на `sw.js`, что оба обработчика и `message` на месте).

## 5. Что сделано в этом проходе

Всё перечисленное реализовано, `RENDERER_VERSION` 14 → 15.

- **Д-0.** `navigator.serviceWorker.register('sw.js')` — первым делом в IIFE,
  без `window.load`. Регрессия закрыта тестом: в сгенерированном HTML позиция
  `register('sw.js')` обязана быть **раньше** `if (isStandalone) {`.
- **Д-1.** `beacon()` на `navigator.sendBeacon` (фолбэк — `fetch keepalive`,
  затем `new Image`). Плюс воркер репортит свои отказы сам (`swBeacon`), потому
  что страницы в этот момент уже нет.
- **Д-2/Д-3.** Подписка целиком переехала в `sw.js`: `orbitraSubscribe()` под
  `message` / `pushsubscriptionchange` / `activate`. Страница только делает
  `postMessage` и сразу уходит на оффер; fail-safe на 45 с удалён — ждать
  больше нечего. `res.ok` проверяется, non-2xx = `pushfail:http<code>`.
- **Д-4.** `swReady()` с потолком 8 с и отдельным кодом `noreg`; ждём только
  **передачу задания** (локальный `postMessage`, единицы миллисекунд), а не
  подписку.
- **Д-5.** `url.href.indexOf(scope)` вместо `url.pathname` — кэш воркера ожил.
- **Ингест.** `click_id = COALESCE(excluded.click_id, …)`: самолечащие пути
  воркера постят тот же endpoint без `subid`, и прежний прямой перезаписью
  стирал бы привязку к клику.

Проверено вживую в контейнере (headless Chromium, страница + сгенерированный
`sw.js`, подставной push-сервис): после `postMessage` страница уходит на оффер
за ~50 мс, а `POST /push_subscribe` с правильным `subid` и маяк `pushfail`
приходят на сервер **уже после того, как страница ушла**. Раньше и то и другое
терялось. Тесты: `pwa_landing`, `push_base`, `domain_pwa`, `lp_offer_macros` —
зелёные; по всему набору падают только 4 известных ранее
(`cf_accounts_migration`, `domains_migration_v23`, `settings_seed_v30`,
`geo_databases`).
