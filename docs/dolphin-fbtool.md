# Dolphin / Fbtool — приём расходов (Keitaro Admin API)

Dolphin и Fbtool.pro не имеют собственных интеграций с трекерами. Оба сервиса
умеют только одно: отправлять расходы в Keitaro через его Admin API. Поэтому
«поддержка Dolphin/Fbtool» в Orbitra — это совместимый входящий endpoint,
который сервисы вызывают, думая, что говорят с Keitaro:

```
POST https://your-tracker.com/admin_api/v1/campaigns/CAMPAIGN_ID/update_costs
Authorization: Bearer <API_KEY>
Content-Type: application/json

{
  "start_date": "2026-08-15",
  "end_date": "2026-08-15",
  "cost": 12.34,
  "currency": "USD",
  "timezone": "Europe/Berlin",
  "filters": { "sub_id_4": "120212558973560058" }
}
```

Ответ — форма Keitaro: `{"success":true, ...}` или HTTP-код + `{"success":false,"error":"..."}`.

Endpoint реализован в `admin_api.php`, роутинг — в `.htaccess` (Apache) и
`core/nginx_config.php` (nginx). Регенерация nginx-конфига после обновления
подхватывает location автоматически.

---

## 1. Создание API-ключа

Endpoint принимает персональные ключи трекера (таблица `user_api_keys`) — те же,
что использует MCP.

1. Откройте **Пользователи** → свой профиль.
2. Сгенерируйте ключ с правами **write** (read-only ключи получают `403`).
3. Ключ передаётся заголовком `Authorization: Bearer <key>` или `X-Api-Key: <key>`.

## 2. Настройка Dolphin

Dolphin → **Настройки → Экспорт расходов → Keitaro**:

- **Адрес трекера**: `https://your-tracker.com`
- **API-ключ**: ключ из шага 1
- **Матчинг**: by Adset ID (по умолчанию) — тогда фильтр `sub_id_3` совпадёт с
  параметром `adset_id` клика автоматически.

## 3. Настройка Fbtool.pro

Fbtool → **Расходы → Keitaro**:

- **Адрес админки**: `https://your-tracker.com`
- **API-ключ**: ключ из шага 1
- Fbtool матчит по `ad.id` и шлёт фильтр `sub_id_4` — он совпадёт с параметром
  `ad_id` клика автоматически.

## 4. Что должно быть настроено в кампании

Фильтры матчатся по **параметрам клика** (`clicks.parameters_json`). Параметры
на клик ставит шаблон источника трафика Facebook: кампания должна получать
трафик со ссылкой, содержащей

```
ad_id={{ad.id}}&adset_id={{adset.id}}&campaign_id={{campaign.id}}
```

В редакторе кампании это делает выбор источника «Facebook Ads»: параметры
подставляются автоматически (вкладка «Параметры», ссылка с макросами).

## 5. Как распределяется расход

- Клики кампании за `[start_date .. end_date]`, суженные фильтрами.
- Ключ фильтра пробуется как имя параметра как есть (`ad_id`, `adset_id`,
  `campaign_id`, …) плюс дефолты FB-шаблона Keitaro: `sub_id_3`→`adset_id`,
  `sub_id_4`→`ad_id`. Несколько фильтров объединяются по AND.
- Сумма конвертируется в валюту трекера (`core/CurrencyRates.php`) и делится
  поровну между совпавшими кликами (flat CPC) — та же модель, что у
  `core/CostImporter.php`.
- Повторная отправка того же периода **перезаписывает** распределение, а не
  суммирует (сервисы шлют расход каждые 60–90 минут).
- Если кликов не нашлось, сумма сохраняется в `cost_records` под скрытым
  подключением `external_api` без атрибуции — чтобы расход не пропал молча.
  Проверьте фильтры и параметры кампании, если расход «не доходит».

## 6. Проверка

```bash
curl -X POST 'https://your-tracker.com/admin_api/v1/campaigns/10/update_costs' \
  -H 'Authorization: Bearer <API_KEY>' \
  -H 'Content-Type: application/json' \
  -d '{"start_date":"2026-08-15","end_date":"2026-08-15","cost":10,"currency":"USD","filters":{"ad_id":"123456"}}'
```

Ответ `{"success":true,"clicks":N,...}` — расход записан и распределён по N
кликам. `clicks:0` — расход запаркован без атрибуции: фильтр не совпал ни с
одним кликом за период.

---

*Добавлено в v0.9.7.7.*
