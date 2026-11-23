// What the confirmation card shows for a held tool call: a few plain lines
// instead of the raw arguments the model sent. Pure — it reads the entity
// cache and loads nothing itself; whatever is still missing goes into
// `wants`, and the chat loads it (useEntityCache) and renders again.

const OPS = { gt: '>', gte: '≥', lt: '<', lte: '≤', eq: '=' };
const PERCENT_METRICS = new Set(['roi', 'cr']);

const text = (v) => (typeof v === 'string' ? v : v && typeof v === 'object' && typeof v.text === 'string' ? v.text : '');
const num = (v) => (Number.isFinite(Number(v)) ? Number(v) : 0);
const pct = (share) => (share === null || share === undefined ? '—' : `${String(Math.round(share * 10) / 10)}%`);
const isOn = (v) => v === true || v === 1 || v === '1';

/** Does the router take this rotation item into the weighted pick? */
const itemEnabled = (it) => !(it.state === 'disabled' || it.state === 'paused') && !(it.is_active === false || it.is_active === 0 || it.is_active === '0');

/** schema_custom as the editor sees it: an object with offers / landings lists. */
function rotation(stream) {
    const raw = stream?.schema_custom ?? stream?.schema_custom_json;
    if (raw && typeof raw === 'object') return raw;
    try {
        const parsed = JSON.parse(String(raw || ''));
        return parsed && typeof parsed === 'object' && !Array.isArray(parsed) ? parsed : {};
    } catch {
        return {};
    }
}

/** Share of each enabled regular stream among them, in % — what the router weighs. */
function streamShares(streams, override = {}) {
    const list = Array.isArray(streams) ? streams : [];
    const inContest = (s) => Number(s.is_active ?? 1) === 1 && (s.type ?? 'regular') === 'regular';
    const weight = (s) => Math.max(0, num(override[s.id] ?? s.weight));
    const total = list.filter(inContest).reduce((sum, s) => sum + weight(s), 0);
    const out = {};
    list.forEach((s) => {
        out[s.id] = inContest(s) && total > 0 ? (weight(s) / total) * 100 : null;
    });
    return out;
}

/** The same for the offers or landings of one stream's rotation. */
function listShares(list, override = {}) {
    const items = (Array.isArray(list) ? list : []).filter((it) => it && typeof it === 'object');
    const weight = (it) => Math.max(0, num(override[it.id] ?? it.weight));
    const total = items.filter(itemEnabled).reduce((sum, it) => sum + weight(it), 0);
    const out = {};
    items.forEach((it) => {
        out[it.id] = itemEnabled(it) && total > 0 ? (weight(it) / total) * 100 : null;
    });
    return out;
}

/** What a card needs from the tracker to show names and "was → will be". */
export function wantsFor(name, args) {
    const a = args && typeof args === 'object' ? args : {};
    const one = (kind, id) => (id ? [{ kind, id }] : []);
    switch (name) {
        case 'orbitra_save_text_test':
            return one('landing', a.landing_id);
        case 'orbitra_set_stream_weights':
        case 'orbitra_set_stream_active':
        case 'orbitra_set_campaign_state':
        case 'orbitra_save_campaign_rule':
        case 'orbitra_update_campaign':
            return one('campaign', a.campaign_id ?? a.id);
        case 'orbitra_delete_campaign':
            return one('campaign', a.id);
        case 'orbitra_delete_offer':
            return one('offer', a.id);
        case 'orbitra_set_rotation_weights': {
            const items = (list, kind) => (Array.isArray(a[list]) ? a[list] : []).map((w) => one(kind, w?.id)).flat();
            return [...one('campaign', a.campaign_id), ...items('offers', 'offer'), ...items('landings', 'landing')];
        }
        default:
            return [];
    }
}

const textTestCard = (a, t, get) => ({
    kind: 'textTest',
    title: t('ai.action.textTest', { id: a.landing_id }),
    subject: get('landing', a.landing_id)?.name || null,
    notes: [a.mode === 'equal' ? t('textTests.modeEqual') : t('textTests.modeAuto')],
    variants: {
        original: text(a.original_text),
        items: (Array.isArray(a.variants) ? a.variants : []).map(text).filter(Boolean),
    },
});

const streamWeightsCard = (a, t, get) => {
    const campaign = get('campaign', a.campaign_id);
    const streams = Array.isArray(campaign?.streams) ? campaign.streams : [];
    const changes = {};
    (Array.isArray(a.weights) ? a.weights : []).forEach((w) => {
        if (w && w.stream_id !== undefined) changes[String(w.stream_id)] = w.weight;
    });
    const before = streamShares(streams);
    const after = streamShares(streams, changes);
    const notes = [];
    if (a.rotation_type === 'weight') notes.push(t('ai.action.rotationSwitch'));
    else if ((campaign?.rotation_type ?? 'position') === 'position') notes.push(t('ai.action.positionNote'));
    return {
        kind: 'weights',
        title: t('ai.action.streamWeights'),
        subject: campaign?.name || null,
        notes,
        rows: Object.keys(changes).map((id) => {
            const s = streams.find((x) => String(x.id) === id);
            return { key: id, label: s?.name || `#${id}`, from: campaign ? pct(before[id]) : '…', to: pct(after[id]) };
        }),
    };
};

const streamSwitchCard = (a, t, get) => {
    const campaign = get('campaign', a.campaign_id);
    const streams = Array.isArray(campaign?.streams) ? campaign.streams : [];
    const word = (v) => (v === undefined ? '—' : t(isOn(v) ? 'ai.action.on' : 'ai.action.off'));
    return {
        kind: 'switches',
        title: t('ai.action.streamSwitch'),
        subject: campaign?.name || null,
        rows: (Array.isArray(a.streams) ? a.streams : []).filter((u) => u && u.stream_id !== undefined).map((u) => {
            const s = streams.find((x) => String(x.id) === String(u.stream_id));
            return {
                key: String(u.stream_id),
                label: s?.name || `#${u.stream_id}`,
                from: s ? word(s.is_active) : '…',
                to: word(isOn(u.is_active)),
            };
        }),
    };
};

const campaignStateCard = (a, t, get) => {
    const campaign = get('campaign', a.campaign_id);
    const word = (state) => t(state === 'active' ? 'ai.action.running' : 'ai.action.stopped');
    return {
        kind: 'state',
        title: t('ai.action.campaignState'),
        subject: campaign?.name || null,
        notes: [t(a.sync_ads ? 'ai.action.syncAds' : 'ai.action.noSyncAds')],
        rows: [{ key: 'state', label: t('ai.action.state'), from: campaign ? word(campaign.state) : '…', to: word(a.state) }],
    };
};

const rotationWeightsCard = (a, t, get) => {
    const campaign = get('campaign', a.campaign_id);
    const streams = Array.isArray(campaign?.streams) ? campaign.streams : [];
    const stream = streams.find((x) => String(x.id) === String(a.stream_id));
    const custom = rotation(stream);
    const rows = [];
    ['offers', 'landings'].forEach((list) => {
        const items = Array.isArray(a[list]) ? a[list] : [];
        if (!items.length) return;
        const kind = list === 'offers' ? 'offer' : 'landing';
        const listLabel = t(list === 'offers' ? 'ai.action.offers' : 'ai.action.landings');
        const current = Array.isArray(custom[list]) ? custom[list] : [];
        const changes = {};
        items.forEach((w) => {
            if (w && w.id !== undefined) changes[String(w.id)] = w.weight;
        });
        const before = listShares(current);
        const after = listShares(current, changes);
        items.forEach((w) => {
            if (!w || w.id === undefined) return;
            const name = get(kind, w.id)?.name;
            rows.push({
                key: `${list}:${w.id}`,
                label: `${listLabel}: ${name || `#${w.id}`}`,
                from: stream ? pct(before[w.id]) : '…',
                to: pct(after[w.id]),
            });
        });
    });
    return {
        kind: 'weights',
        title: t('ai.action.rotationWeights', { id: a.stream_id }),
        subject: campaign?.name || null,
        notes: a.turn_off_auto ? [t('ai.action.turnOffAuto')] : [],
        rows,
    };
};

const ruleCard = (a, t, get) => {
    const metric = (c) => t(`campaignRules.metric.${c?.metric ?? 'sales'}`);
    const conditions = (Array.isArray(a.conditions) ? a.conditions : [])
        .map((c) => `${metric(c)} ${OPS[c?.op] ?? c?.op ?? ''} ${c?.value ?? ''}${PERCENT_METRICS.has(c?.metric) ? '%' : ''}`)
        .join(` ${t('campaignRules.and')} `);
    return {
        kind: 'rule',
        title: t('ai.action.rule'),
        subject: get('campaign', a.campaign_id)?.name || null,
        notes: [
            t('campaignRules.sentence', {
                target: t(`campaignRules.target.${a.target ?? 'campaign'}`),
                hours: a.window_hours,
                conditions,
                action: t(`campaignRules.action.${a.action ?? 'notify'}`),
            }),
            t('campaignRules.minutes', { n: a.interval_min }),
        ],
    };
};

const campaignUpdateCard = (a, t, get) => {
    const notes = [];
    const removing = Array.isArray(a.delete_stream_ids) ? a.delete_stream_ids : [];
    const adding = (Array.isArray(a.streams) ? a.streams : []).filter((s) => s && !s.id).length;
    if (removing.length) notes.push(t('ai.action.deleteStreams', { ids: removing.map((id) => `#${id}`).join(', ') }));
    if (adding) notes.push(t('ai.action.addStreams', { n: adding }));
    if (a.rotation_type) notes.push(t(a.rotation_type === 'weight' ? 'ai.action.rotationSwitch' : 'ai.action.rotationPosition'));
    return notes.length
        ? { kind: 'notes', title: t('ai.action.campaignUpdate'), subject: get('campaign', a.id)?.name || null, notes, extra: true }
        : null;
};

const removeCard = (a, t, get, kind) => ({
    kind: 'remove',
    title: t(kind === 'campaign' ? 'ai.action.removeCampaign' : 'ai.action.removeOffer', { id: a.id }),
    subject: get(kind, a.id)?.name || null,
});

/**
 * The card for a held call, or null when there is nothing better to show than
 * the arguments themselves. `extra: true` keeps the arguments visible under
 * the lines, for calls that carry more than the lines cover.
 */
export function describeCall(name, args, t, cache) {
    const a = args && typeof args === 'object' ? args : {};
    const get = (kind, id) => (id ? cache[`${kind}:${id}`]?.data : undefined);
    const make = {
        orbitra_save_text_test: () => textTestCard(a, t, get),
        orbitra_set_stream_weights: () => streamWeightsCard(a, t, get),
        orbitra_set_stream_active: () => streamSwitchCard(a, t, get),
        orbitra_set_campaign_state: () => campaignStateCard(a, t, get),
        orbitra_set_rotation_weights: () => rotationWeightsCard(a, t, get),
        orbitra_save_campaign_rule: () => ruleCard(a, t, get),
        orbitra_update_campaign: () => campaignUpdateCard(a, t, get),
        orbitra_delete_campaign: () => removeCard(a, t, get, 'campaign'),
        orbitra_delete_offer: () => removeCard(a, t, get, 'offer'),
    }[name];
    const card = make ? make() : null;
    return card ? { ...card, wants: wantsFor(name, a) } : null;
}

/** Variants kept for a text test: picks[i] is the checkbox of variants[i]. */
export function pickedVariants(card, picks) {
    const items = card?.variants?.items ?? [];
    return items.filter((_, i) => !picks || picks[i] !== false);
}
