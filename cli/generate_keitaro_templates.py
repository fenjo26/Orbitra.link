#!/usr/bin/env python3
"""Regenerate data/keitaro_traffic_sources.json and data/keitaro_affiliate_networks.json.

Usage (from the project root):

    python3 cli/generate_keitaro_templates.py \
        /path/to/source_templates.json /path/to/network_templates.json

Both inputs are Keitaro's own template exports. Everything the tracker shows in the
"Template" dropdown for traffic sources and affiliate networks is derived from them —
do not hand-edit the generated files. A macro that looks plausible but is not the one
the network actually sends produces clicks with an empty external_id, and every
conversion posted back afterwards has nothing to attach to.

Entries already shipped inline in api.php are skipped, so the generated packs only
ever add to the built-in list. Run `php tests/keitaro_templates_test.php` afterwards.
"""
import json
import re
import sys
import unicodedata
from pathlib import Path

# Names shipped inline in api.php (traffic_source_templates / affiliate_network_templates).
BUILTIN_SOURCES = ['Facebook Ads', 'Google Ads', 'TikTok Ads', 'Taboola', 'Outbrain', 'MGID',
                   'ExoClick', 'PropellerAds', 'Яндекс.Директ', 'Zeropark', 'HasOffers', 'Email',
                   'Custom source']
BUILTIN_SOURCE_KEYS = ['facebook.com', 'Google_Ads', 'tiktok', 'taboola.com', 'outbrain.com',
                       'mgid.com', 'exoclick.com', 'propellerads.com', 'yandex_direct',
                       'zeropark.com', 'hasoffers']
BUILTIN_NETWORKS = ['Generic', 'Everflow (platform)', 'CAKE (platform)', 'HitPath (platform)',
                    'Affise (platform)', 'TUNE / HasOffers (platform)', '1win.run', '22BET.com',
                    '4rabetpartner.com', 'Advertise.net', 'Alfaleads.net', 'AppsFlyer.com',
                    'Boomerang-partners.com', 'Biamopartners.com', 'Cataffs.team', 'Cpabro.vip',
                    'Enot.partners', 'Gambling.pro', 'GGBetAff', 'Hellpartners.com',
                    'Jimpartners.com', 'Leadbit.com', 'M1-shop.ru', 'M4leads.com', 'MB.partners',
                    'Melbetaffiliates.com', 'Mostbet.partners(cpa)', 'Mostbet.partners(revshare)',
                    'Nutra.Media', 'Partners.cpa.rip', 'Pin-up.partners', 'Profitov.Partners',
                    'Q3.network', 'Riddick.guru', 'Royal.partners', 'Vulkan.bet',
                    'Vulkanpartner.com', 'WelcomePartners', 'X-partners.com', 'Dr.Cash',
                    'AdCombo', 'Partners1xBet', 'Traffic Light', 'LemonAD', 'Custom network']

TLDS = ('com', 'net', 'ru', 'org', 'io', 'pro', 'biz', 'app', 'me', 'co', 'link', 'shop',
        'group', 'team', 'club', 'online', 'partners', 'agency', 'vip', 'rip', 'guru',
        'bet', 'network', 'media', 'cash', 'tools', 'ai', 'dev', 'space', 'store', 'one',
        'market', 'top', 'pics', 'run', 'games', 'gg', 'mba', 'direct', 'ua', 'pw')

# postback.php reads the click id from subid/clickid only.
PARAM_ALIASES = {'sub_id': 'subid', 'sub-id': 'subid', 'clickid': 'subid', 'click_id': 'subid',
                 'ext_id': 'subid'}
# Keitaro's "ignore" outcome (record nothing) has no equivalent in the tracker.
DROP_PARAMS = {'ignore_status'}


def norm(s):
    return re.sub(r'[^a-z0-9]', '', unicodedata.normalize('NFKD', str(s)).lower())


def base(s):
    """Normalised name with trailing TLD-ish tokens stripped: Cataffs.team -> cataffs."""
    parts = [p for p in re.split(r'[^a-zA-Z0-9]+', str(s).lower()) if p]
    while len(parts) > 1 and parts[-1] in TLDS:
        parts.pop()
    return ''.join(parts)


def slug(s):
    s = re.sub(r'[^a-z0-9]+', '_', unicodedata.normalize('NFKD', str(s)).lower())
    return s.strip('_') or 'template'


def taken_index(names):
    idx = set()
    for n in names:
        idx.add(norm(n))
        idx.add(base(n))
    return idx


def convert_postback(url):
    """Keitaro conversion macros -> the macros postback.php substitutes."""
    if not url:
        return ''
    url = url.strip()
    if url and not re.match(r'^https?://', url):
        url = 'https://' + url.lstrip('/')
    url = re.sub(r'\{conversion_revenue(:[a-z]{3})?\}', '{payout}', url)
    return url.replace('{profit}', '{payout}')


def build_sources(src, taken):
    """Keitaro parameter key -> Orbitra alias (the parameters_json key),
    Keitaro "name" -> Orbitra param (the URL query parameter),
    Keitaro "placeholder" -> Orbitra macro (what the network substitutes)."""
    out, seen = [], set()
    for key, v in src.items():
        if key in BUILTIN_SOURCE_KEYS:
            continue
        display = str(v.get('name') or key).strip()
        if norm(display) in taken or base(display) in taken or norm(key) in taken:
            continue
        params = []
        for pkey, p in (v.get('parameters') or {}).items():
            if not isinstance(p, dict) or not p.get('placeholder'):
                continue
            params.append({'alias': pkey,
                           'param': p.get('name') or pkey,
                           'macro': str(p['placeholder'])})
        if not params:
            continue
        name = slug(key)
        while name in seen:
            name += '_x'
        seen.add(name)
        taken.update({norm(display), base(display)})
        out.append({'name': name,
                    'display_name': display,
                    'postback_url': convert_postback(v.get('postback', '')),
                    'parameters': params})
    out.sort(key=lambda x: x['display_name'].lower())
    return out


def build_networks(net, taken):
    out, seen = [], set()
    for key, v in net.items():
        display = str(v.get('name') or key).strip()
        if norm(display) in taken or base(display) in taken or norm(key) in taken or base(key) in taken:
            continue
        params = v.get('params') if isinstance(v.get('params'), dict) else {}
        offer_param = str(v.get('offer_param') or '').strip()
        if not params and not offer_param:
            continue
        pairs, has_subid = [], False
        for pk, pv in params.items():
            pk = PARAM_ALIASES.get(pk, pk)
            if pk in DROP_PARAMS:
                continue
            if pk == 'subid':
                if has_subid:
                    continue
                has_subid = True
            pairs.append(f'{pk}={pv}')
        if has_subid:
            pairs.sort(key=lambda s: 0 if s.startswith('subid=') else 1)
            pairs.append('from=' + re.sub(r'[&=?#\s]+', '_', display))
        else:
            # No click-id parameter in the export: a postback built from these could
            # never match a click, so ship the offer parameters only and let the user
            # paste the network's own postback (same as the platform templates).
            pairs = []
        offer_param = offer_param or 'subid={subid}'
        offer_tpl = offer_param if offer_param.startswith('/') else '&' + offer_param.lstrip('&')
        name = slug(key)
        while name in seen:
            name += '_x'
        seen.add(name)
        taken.update({norm(display), base(display)})
        out.append({
            'name': name,
            'display_name': display,
            'offer_params_template': offer_tpl,
            'postback_url_template': ('http://{domain}/{postback_key}/postback?' + '&'.join(pairs)) if pairs else '',
        })
    out.sort(key=lambda x: x['display_name'].lower())
    return out


def main():
    if len(sys.argv) != 3:
        print(__doc__)
        return 1
    src = json.loads(Path(sys.argv[1]).read_text(encoding='utf-8'))
    net = json.loads(Path(sys.argv[2]).read_text(encoding='utf-8'))

    out_dir = Path(__file__).resolve().parent.parent / 'data'
    out_dir.mkdir(exist_ok=True)

    sources = build_sources(src, taken_index(BUILTIN_SOURCES + BUILTIN_SOURCE_KEYS))
    networks = build_networks(net, taken_index(BUILTIN_NETWORKS))

    (out_dir / 'keitaro_traffic_sources.json').write_text(
        json.dumps(sources, ensure_ascii=False, indent=1), encoding='utf-8')
    (out_dir / 'keitaro_affiliate_networks.json').write_text(
        json.dumps(networks, ensure_ascii=False, indent=1), encoding='utf-8')

    print(f'traffic sources   : {len(sources)} written (of {len(src)} exported)')
    print(f'affiliate networks: {len(networks)} written (of {len(net)} exported)')
    print('now run: php tests/keitaro_templates_test.php')
    return 0


if __name__ == '__main__':
    sys.exit(main())
