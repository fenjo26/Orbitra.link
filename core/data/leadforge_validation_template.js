
// LeadForge STRICT Validation
// GEO: {{GEO}}
// PATTERN: {{PATTERN}}
// MINLENGTH: {{MINLENGTH}}
// MAXLENGTH: {{MAXLENGTH}}  (hard DOM cap on phone input)
// NAME_ERR: {{NAME_ERR}}
// PHONE_ERR: {{PHONE_ERR}}
// COUNTER_INTRO: {{COUNTER_INTRO}}   e.g. "cifre inserite, " (IT)
// COUNTER_MID: {{COUNTER_MID}}        e.g. " mancanti" (IT)
// COUNTER_COMPLETE: {{COUNTER_COMPLETE}}  e.g. "Numero complete" (IT)
// COUNTER_ERR: {{COUNTER_ERR}}        e.g. "Inserisci 10 cifre che iniziano con 3" (IT)
// COUNTRY_ISO: {{COUNTRY_ISO}}
// COUNTRY_PREFIX: {{COUNTRY_PREFIX}}
// NATIONAL_PREFIX: {{NATIONAL_PREFIX}}
// TRUNK_PREFIX: {{TRUNK_PREFIX}}
// ALLOWED_PREFIXES: {{ALLOWED_PREFIXES}}
// PHONE_HELPER: {{PHONE_HELPER}}
// ALL_GEO_RULES: {{ALL_GEO_RULES}}

// ═══ OUTERMOST GUARD — catches parse errors before JS engine even parses ═══
// These MUST be outside any try block so they survive even when the IIFE throws.
window.__LF_DID_RUN = false;
window.__LF_ERR = null;
window.__LF_SCRIPT_DID_PARSE = false;
window.__LF_PARSE_ERR = null;
var __LF_PREVIOUS_ONERROR = window.onerror;
window.onerror = function(msg, src, line, col, err) {
  var text = String(msg || '');
  if (!(text === 'Script error.' && Number(line || 0) === 0 && Number(col || 0) === 0)) {
    window.__LF_ERR = text + ' | line:' + line + ' col:' + col + (err ? ' ' + err.stack : '');
  }
  if (typeof __LF_PREVIOUS_ONERROR === 'function') {
    return __LF_PREVIOUS_ONERROR.apply(this, arguments);
  }
  return false;
};
(function(){
  'use strict';
  window.__LF_DID_RUN = true;
  window.__LF_SCRIPT_DID_PARSE = true;
  var DEFAULT_RULE = {
    geo: String({{COUNTRY_ISO}} || '').toUpperCase(),
    pattern: "{{PATTERN}}",
    minlength: parseInt('{{MINLENGTH}}', 10),
    maxlength: parseInt('{{MAXLENGTH}}', 10),
    name_err: {{NAME_ERR}},
    phone_err: {{PHONE_ERR}},
    counter_intro: {{COUNTER_INTRO}},
    counter_mid: {{COUNTER_MID}},
    counter_complete: {{COUNTER_COMPLETE}},
    counter_err: {{COUNTER_ERR}},
    country_iso: {{COUNTRY_ISO}},
    country_prefix: {{COUNTRY_PREFIX}},
    national_prefix: {{NATIONAL_PREFIX}},
    trunk_prefix: {{TRUNK_PREFIX}},
    allowed_prefixes: {{ALLOWED_PREFIXES}},
    phone_helper: {{PHONE_HELPER}},
    example_local: "",
    example_international: ""
  };
  var ALL_GEO_RULES = {{ALL_GEO_RULES}};
  var ACTIVE_RULE = null;
  var PHONE_REGEX = null;
  var MINLENGTH = 0;
  var MAXLENGTH = 0;
  var NAME_ERR = '';
  var PHONE_ERR = '';
  var COUNTER_INTRO = '';
  var COUNTER_MID = '';
  var COUNTER_COMPLETE = '';
  var COUNTER_ERR = '';
  var COUNTRY_ISO = '';
  var COUNTRY_PREFIX = '';
  var NATIONAL_PREFIX = '';
  var TRUNK_PREFIX = false;
  var ALLOWED_PREFIXES = [];
  var PHONE_HELPER = '';
  var EXAMPLE_LOCAL = '';
  var EXAMPLE_INTERNATIONAL = '';
  var RTL_COUNTRIES = ['AE', 'BH', 'DZ', 'EG', 'IQ', 'JO', 'KW', 'LB', 'LY', 'MA', 'OM', 'PS', 'QA', 'SA', 'TN', 'YE'];
  var IS_RTL = false;
  function buildProgressPrefixes(prefixes) {
    if (!prefixes || !prefixes.length) return [];
    var clean = Array.from(new Set(prefixes.map(function(prefix) {
      return String(prefix || '').replace(/[^0-9]/g, '');
    }).filter(Boolean)));
    if (!clean.length) return [];
    return clean;
  }
  var PROGRESS_PREFIXES = [];
  function ruleForGeo(iso) {
    var code = String(iso || '').trim().toUpperCase();
    return (code && ALL_GEO_RULES && ALL_GEO_RULES[code]) || DEFAULT_RULE;
  }
  function applyActiveRule(rule) {
    ACTIVE_RULE = rule || DEFAULT_RULE;
    PHONE_REGEX = new RegExp(String(ACTIVE_RULE.pattern || DEFAULT_RULE.pattern || '.*'));
    MINLENGTH = parseInt(ACTIVE_RULE.minlength || DEFAULT_RULE.minlength || 0, 10);
    MAXLENGTH = parseInt(ACTIVE_RULE.maxlength || DEFAULT_RULE.maxlength || 0, 10);
    NAME_ERR = String(ACTIVE_RULE.name_err || DEFAULT_RULE.name_err || '');
    PHONE_ERR = String(ACTIVE_RULE.phone_err || DEFAULT_RULE.phone_err || '');
    COUNTER_INTRO = String(ACTIVE_RULE.counter_intro || DEFAULT_RULE.counter_intro || '');
    COUNTER_MID = String(ACTIVE_RULE.counter_mid || DEFAULT_RULE.counter_mid || '');
    COUNTER_COMPLETE = String(ACTIVE_RULE.counter_complete || DEFAULT_RULE.counter_complete || '');
    COUNTER_ERR = String(ACTIVE_RULE.counter_err || DEFAULT_RULE.counter_err || '');
    COUNTRY_ISO = String(ACTIVE_RULE.country_iso || ACTIVE_RULE.geo || DEFAULT_RULE.country_iso || '').toLowerCase();
    COUNTRY_PREFIX = String(ACTIVE_RULE.country_prefix || DEFAULT_RULE.country_prefix || '');
    NATIONAL_PREFIX = String(ACTIVE_RULE.national_prefix || DEFAULT_RULE.national_prefix || '');
    TRUNK_PREFIX = Boolean(ACTIVE_RULE.trunk_prefix);
    ALLOWED_PREFIXES = Array.isArray(ACTIVE_RULE.allowed_prefixes) ? ACTIVE_RULE.allowed_prefixes : (DEFAULT_RULE.allowed_prefixes || []);
    PHONE_HELPER = String(ACTIVE_RULE.phone_helper || DEFAULT_RULE.phone_helper || '');
    EXAMPLE_LOCAL = String(ACTIVE_RULE.example_local || '');
    EXAMPLE_INTERNATIONAL = String(ACTIVE_RULE.example_international || '');
    IS_RTL = RTL_COUNTRIES.indexOf(String(COUNTRY_ISO || '').toUpperCase()) !== -1;
    PROGRESS_PREFIXES = buildProgressPrefixes(ALLOWED_PREFIXES);
  }
  applyActiveRule(DEFAULT_RULE);
  var forms = document.querySelectorAll('.wv_order-form');
  var LEAD_FIELD_ALIASES = {
    name: ['name', 'first_name', 'full_name', 'fullname', 'fio', 'customer_name'],
    phone: ['phone', 'mobile', 'telephone', 'tel', 'phone_number', 'msisdn']
  };

  // --- CSS for error states ---
  var styleEl = document.createElement('style');
  styleEl.textContent = [
    '.wv_order-form input.wv_phone{direction:ltr!important;unicode-bidi:plaintext!important;}',
    '.wv_order-form input[type="hidden"]{display:none!important;width:0!important;height:0!important;margin:0!important;padding:0!important;border:0!important;position:absolute!important;left:-99999px!important;}',
    '.wv-input-error { border: 2px solid #ff3333 !important; box-shadow: 0 0 0 3px rgba(255,51,51,0.25) !important; background: #fff0f0 !important; }',
    '.wv-input-nudge { outline: 2px solid rgba(255,51,51,0.52) !important; outline-offset: 2px !important; }',
    '.wv-form-nudge { animation: wv-soft-shake 0.22s ease-in-out !important; transform-origin:center center!important; }',
    '@keyframes wv-shake { 0%,100%{transform:translateX(0)} 15%,45%,75%{transform:translateX(-8px)} 30%,60%,90%{transform:translateX(8px)} }',
    '@keyframes wv-soft-shake { 0%,100%{transform:translateX(0)} 25%{transform:translateX(-4px)} 75%{transform:translateX(4px)} }',
    '.wv-val-error { color:#fff;background:#cc0000;font-size:14px;font-weight:600;margin:8px 0;padding:12px 16px;border-radius:6px;text-align:center;border:2px solid #ff3333;box-shadow:0 4px 12px rgba(204,0,0,0.4);animation:wv-slidein 0.3s ease-out; }',
    '.phone-warning,.name-warning,.wv-field-hint { display:block!important;clear:both!important;max-width:100%!important;box-sizing:border-box!important;text-align:center!important;font-size:clamp(12px,2.6vw,14px)!important;margin:6px auto!important;padding:4px 8px!important;min-height:24px!important;font-weight:600!important;line-height:1.28!important;white-space:normal!important;overflow-wrap:normal!important;word-break:normal!important;pointer-events:none!important;writing-mode:horizontal-tb!important;text-orientation:mixed!important;}',
    '.phone-warning:empty,.name-warning:empty,.wv-field-hint:empty { display:none !important; margin:0 !important; min-height:0 !important; padding:0 !important; border:0 !important; }',
    '.lf-field-hint-row:has(.wv-field-hint:empty){display:none!important;margin:0!important;min-height:0!important;}',
    '.wv-field-hint.lf-warning-active { min-width:0!important;border-radius:8px!important;background:var(--lf-hint-bg,rgba(255,246,230,0.96))!important;color:var(--lf-hint-text,#b84f14)!important;box-shadow:var(--lf-hint-shadow,inset 0 0 0 1px rgba(230,126,34,0.24),0 8px 22px rgba(0,0,0,0.10))!important;margin:0 auto!important;padding:7px 10px!important;border:1px solid var(--lf-hint-border,rgba(230,126,34,0.24))!important;text-shadow:var(--lf-hint-text-shadow,none)!important;word-break:normal!important;hyphens:none!important; }',
    '.wv-field-hint.lf-warning-strong { background:var(--lf-hint-bg,rgba(255,238,235,0.96))!important;color:var(--lf-hint-text,#c0392b)!important;box-shadow:var(--lf-hint-shadow,inset 0 0 0 1px rgba(231,76,60,0.28),0 8px 22px rgba(0,0,0,0.10))!important;border-color:var(--lf-hint-border,rgba(231,76,60,0.30))!important; }',
    '.phone-warning *, .name-warning *, .wv-field-hint * { max-width:100%!important;writing-mode:horizontal-tb!important;text-orientation:mixed!important; }',
    '.iti__selected-flag{gap:6px;}',
    '.iti__selected-dial-code{font-weight:500;letter-spacing:0;}',
    '.lf-emoji-flag{display:inline-flex;align-items:center;justify-content:center;width:20px;height:15px;font-size:15px;line-height:1;overflow:hidden;transform:translateY(-0.5px);}',
    '@keyframes wv-slidein { from{opacity:0;transform:translateY(-10px)} to{opacity:1;transform:translateY(0)} }'
  ].join('\n');
  document.head.appendChild(styleEl);

  // --- VIBRATE helper ---
  function vibrate(pattern) {
    if (navigator.vibrate) { navigator.vibrate(pattern); }
  }

  function parseRgbColor(value) {
    var raw = String(value || '').trim();
    if (!raw || raw === 'transparent') return null;
    var rgba = raw.match(/^rgba?\(([^)]+)\)$/i);
    if (rgba) {
      var parts = rgba[1].split(',').map(function(part) { return part.trim(); });
      var alpha = parts.length > 3 ? parseFloat(parts[3]) : 1;
      if (isNaN(alpha) || alpha <= 0.08) return null;
      return {
        r: Math.max(0, Math.min(255, parseFloat(parts[0]) || 0)),
        g: Math.max(0, Math.min(255, parseFloat(parts[1]) || 0)),
        b: Math.max(0, Math.min(255, parseFloat(parts[2]) || 0)),
        a: alpha
      };
    }
    if (raw.charAt(0) === '#') {
      var hex = raw.slice(1);
      if (hex.length === 3) {
        hex = hex.replace(/./g, function(ch) { return ch + ch; });
      }
      if (/^[0-9a-f]{6}$/i.test(hex)) {
        return {
          r: parseInt(hex.slice(0, 2), 16),
          g: parseInt(hex.slice(2, 4), 16),
          b: parseInt(hex.slice(4, 6), 16),
          a: 1
        };
      }
    }
    return null;
  }

  function colorLuminance(color) {
    if (!color) return 1;
    function channel(v) {
      v = v / 255;
      return v <= 0.03928 ? v / 12.92 : Math.pow((v + 0.055) / 1.055, 2.4);
    }
    return (0.2126 * channel(color.r)) + (0.7152 * channel(color.g)) + (0.0722 * channel(color.b));
  }

  function colorHue(color) {
    if (!color) return 0;
    var r = color.r / 255;
    var g = color.g / 255;
    var b = color.b / 255;
    var max = Math.max(r, g, b);
    var min = Math.min(r, g, b);
    var delta = max - min;
    if (!delta) return 0;
    var hue = 0;
    if (max === r) hue = ((g - b) / delta) % 6;
    else if (max === g) hue = ((b - r) / delta) + 2;
    else hue = ((r - g) / delta) + 4;
    hue *= 60;
    return hue < 0 ? hue + 360 : hue;
  }

  function visibleBackgroundColor(node) {
    var current = node;
    var depth = 0;
    while (current && current.nodeType === 1 && depth < 12) {
      try {
        var style = window.getComputedStyle(current);
        var color = parseRgbColor(style.backgroundColor);
        if (color) return color;
      } catch (err) {}
      current = current.parentElement;
      depth += 1;
    }
    try {
      return parseRgbColor(window.getComputedStyle(document.body).backgroundColor) ||
        parseRgbColor(window.getComputedStyle(document.documentElement).backgroundColor) ||
        { r: 255, g: 255, b: 255, a: 1 };
    } catch (err) {
      return { r: 255, g: 255, b: 255, a: 1 };
    }
  }

  function hintThemeForBackground(hintEl, strong, positive) {
    var row = hintEl && hintEl.parentNode && hintEl.parentNode.classList && hintEl.parentNode.classList.contains('lf-field-hint-row') ? hintEl.parentNode : hintEl;
    var anchor = row && row.parentElement ? row.parentElement : (hintEl && hintEl.closest ? hintEl.closest('form') : null);
    var bg = visibleBackgroundColor(anchor);
    var lum = colorLuminance(bg);
    var hue = colorHue(bg);
    var warmBg = hue <= 28 || hue >= 335 || (hue >= 285 && hue < 335);
    var coolBg = hue >= 185 && hue <= 275;
    var greenBg = hue >= 80 && hue <= 165;
    var darkBg = lum < 0.22;
    var deepBg = lum < 0.38;

    if (positive) {
      if (darkBg || greenBg) {
        return {
          bg: 'rgba(236,255,242,0.97)',
          text: '#0f6232',
          border: 'rgba(99,214,132,0.58)',
          shadow: 'inset 0 0 0 1px rgba(99,214,132,0.42),0 10px 24px rgba(0,0,0,0.18)',
          textShadow: 'none'
        };
      }
      return {
        bg: 'rgba(239,255,244,0.94)',
        text: '#12743b',
        border: 'rgba(46,204,113,0.42)',
        shadow: 'inset 0 0 0 1px rgba(46,204,113,0.30),0 8px 20px rgba(0,0,0,0.08)',
        textShadow: 'none'
      };
    }

    if (darkBg || coolBg) {
      return {
        bg: strong ? 'rgba(255,241,236,0.97)' : 'rgba(255,249,229,0.97)',
        text: strong ? '#8f2118' : '#694000',
        border: strong ? 'rgba(255,159,143,0.70)' : 'rgba(245,191,98,0.72)',
        shadow: 'inset 0 0 0 1px rgba(255,255,255,0.34),0 12px 28px rgba(0,0,0,0.24)',
        textShadow: 'none'
      };
    }

    if (warmBg || deepBg) {
      return {
        bg: strong ? 'rgba(255,255,255,0.96)' : 'rgba(255,253,244,0.96)',
        text: strong ? '#9d241b' : '#7b4700',
        border: strong ? 'rgba(255,189,178,0.76)' : 'rgba(244,190,86,0.72)',
        shadow: 'inset 0 0 0 1px rgba(255,255,255,0.40),0 10px 24px rgba(0,0,0,0.16)',
        textShadow: 'none'
      };
    }

    return {
      bg: strong ? 'rgba(255,238,235,0.96)' : 'rgba(255,246,230,0.96)',
      text: strong ? '#c0392b' : '#b85b13',
      border: strong ? 'rgba(231,76,60,0.30)' : 'rgba(230,126,34,0.30)',
      shadow: 'inset 0 0 0 1px rgba(230,126,34,0.22),0 8px 22px rgba(0,0,0,0.10)',
      textShadow: 'none'
    };
  }

  function applyAdaptiveHintTheme(hintEl, strong) {
    if (!hintEl || !hintEl.style) return;
    var text = String(hintEl.textContent || '').trim();
    if (!text) {
      ['--lf-hint-bg', '--lf-hint-text', '--lf-hint-border', '--lf-hint-shadow', '--lf-hint-text-shadow'].forEach(function(name) {
        hintEl.style.removeProperty(name);
      });
      return;
    }
    var positive = text.indexOf('\u2705') === 0 || /^\s*✅/.test(text);
    var theme = hintThemeForBackground(hintEl, Boolean(strong), positive);
    hintEl.style.setProperty('--lf-hint-bg', theme.bg);
    hintEl.style.setProperty('--lf-hint-text', theme.text);
    hintEl.style.setProperty('--lf-hint-border', theme.border);
    hintEl.style.setProperty('--lf-hint-shadow', theme.shadow);
    hintEl.style.setProperty('--lf-hint-text-shadow', theme.textShadow || 'none');
    hintEl.setAttribute('data-lf-adaptive-warning', positive ? 'success' : (strong ? 'strong' : 'warning'));
    hintEl.setAttribute('data-lf-bg-luma', String(Math.round(colorLuminance(visibleBackgroundColor(hintEl.parentElement || hintEl)) * 100) / 100));
  }

  function ensureFieldHint(input, preferredClass) {
    if (!input || !input.parentNode) return null;
    var form = input.closest('form');
    var label = input.closest('label');
    var wrapper = input.closest('.inp-line, .form_container, .form__item, .form__field, .form-input-block, .form__input, .form-group, .input-group, .input-wrapper, .input-wrap, .input-line, .field-wrap, .field-wrapper, .order_form_row, .iti, .intl-tel-input, .phone-input-wrapper, .phone-input, .phone-wrapper, .phone-field, .field, .form-field');
    var anchor = label && (!wrapper || label.contains(wrapper)) ? label : (wrapper || label || input);
    var searchRoot = form || input.parentNode;
    var hintKey = input.name || preferredClass;
    function hideDuplicateHint(node) {
      if (!node || !node.style) return;
      node.textContent = '';
      node.setAttribute('data-lf-hidden-duplicate', 'true');
      node.classList && node.classList.remove('lf-warning-active', 'lf-warning-strong');
      node.style.display = 'none';
      node.style.margin = '0';
      node.style.padding = '0';
      node.style.minHeight = '0';
      var parentRow = node.parentNode && node.parentNode.classList && node.parentNode.classList.contains('lf-field-hint-row') ? node.parentNode : null;
      if (parentRow) {
        parentRow.style.display = 'none';
        parentRow.style.margin = '0';
        parentRow.style.padding = '0';
        parentRow.style.minHeight = '0';
      }
    }
    function pruneDuplicateHints(chosenHint) {
      if (!chosenHint || !searchRoot) return;
      var selectors = [
        '.' + preferredClass,
        '.wv-field-hint[data-lf-hint-for="' + String(hintKey).replace(/"/g, '\\"') + '"]'
      ].join(',');
      Array.prototype.forEach.call(searchRoot.querySelectorAll(selectors), function(candidate) {
        if (candidate === chosenHint) return;
        if (candidate.getAttribute && candidate.getAttribute('data-lf-hint-for')) {
          if (candidate.getAttribute('data-lf-hint-for') !== hintKey) return;
        }
        hideDuplicateHint(candidate);
      });
    }
    function placeHint(hintNode) {
      if (!hintNode) return null;
      var row = hintNode.parentNode && hintNode.parentNode.classList && hintNode.parentNode.classList.contains('lf-field-hint-row')
        ? hintNode.parentNode
        : document.createElement('div');
      function rectWidth(node) {
        if (!node || !node.getBoundingClientRect) return 0;
        try { return node.getBoundingClientRect().width || 0; } catch (err) { return 0; }
      }
      function placeInWideFormSlot() {
        if (!form || !form.contains(anchor)) return false;
        var submit = Array.prototype.find.call(
          form.querySelectorAll('button, input[type="submit"], input[type="button"], .btn, .button'),
          function(node) {
            return node && node.parentNode && node.getBoundingClientRect && node.getBoundingClientRect().width > 0;
          }
        );
        row.setAttribute('data-lf-wide-hint-row', 'true');
        row.style.width = '100%';
        row.style.maxWidth = '100%';
        row.style.gridColumn = '1 / -1';
        if (submit && submit.parentNode && form.contains(submit.parentNode)) {
          var submitParent = submit.parentNode;
          if (submitParent.parentNode === form) {
            form.insertBefore(row, submitParent);
            return true;
          }
          form.insertBefore(row, submit);
          return true;
        }
        form.appendChild(row);
        return true;
      }
      row.className = 'lf-field-hint-row';
      row.setAttribute('data-lf-hint-row-for', hintKey);
      row.style.display = hintNode.textContent ? 'block' : 'none';
      row.style.clear = 'both';
      row.style.float = 'none';
      row.style.position = 'relative';
      row.style.inset = 'auto';
      row.style.transform = 'none';
      row.style.width = '100%';
      row.style.maxWidth = '100%';
      row.style.boxSizing = 'border-box';
      row.style.flex = '0 0 100%';
      row.style.margin = hintNode.textContent ? '10px auto 12px' : '0';
      row.style.padding = '0';
      row.style.minHeight = hintNode.textContent ? '28px' : '0';
      row.style.lineHeight = '1.25';
      row.style.zIndex = '1';
      if (!row.contains(hintNode)) {
        row.appendChild(hintNode);
      }
      var formWidth = rectWidth(form);
      var anchorWidth = rectWidth(anchor);
      var parentWidth = rectWidth(anchor && anchor.parentElement);
      var isNarrowSlot = !!(formWidth && anchorWidth && anchorWidth < Math.min(170, formWidth * 0.62));
      if (isNarrowSlot || (parentWidth && formWidth && parentWidth < Math.min(170, formWidth * 0.62))) {
        placeInWideFormSlot();
      } else if (anchor.contains(row) || row.parentNode !== anchor.parentNode || row.previousElementSibling !== anchor) {
        anchor.insertAdjacentElement('afterend', row);
      }
      pruneDuplicateHints(hintNode);
      return hintNode;
    }
    var hint = Array.prototype.find.call(searchRoot.querySelectorAll('.' + preferredClass + '[data-lf-hint-for]'), function(node) {
        return node.getAttribute('data-lf-hint-for') === hintKey;
      }) ||
      Array.prototype.find.call(input.parentNode.querySelectorAll('.' + preferredClass), function(node) {
        var nodeKey = node.getAttribute ? (node.getAttribute('data-lf-hint-for') || '') : '';
        return !nodeKey || nodeKey === hintKey;
      }) ||
      Array.prototype.find.call(input.parentNode.querySelectorAll('.wv-field-hint'), function(node) {
        var nodeKey = node.getAttribute ? (node.getAttribute('data-lf-hint-for') || '') : '';
        return nodeKey === hintKey || (!nodeKey && !node.classList.contains('phone-warning') && !node.classList.contains('name-warning'));
      });
    if (hint) {
      if (!hint.classList.contains(preferredClass)) hint.classList.add(preferredClass);
      hint.classList.add('wv-field-hint');
      hint.setAttribute('data-lf-hint-for', hintKey);
      return placeHint(hint);
    }
    hint = document.createElement('div');
    hint.className = preferredClass + ' wv-field-hint';
    hint.setAttribute('data-lf-hint-for', hintKey);
    return placeHint(hint);
  }

  function setFieldHint(hintEl, text, color) {
    if (!hintEl) return;
    var nextText = text || '';
    var nextColor = color || '';
    var previousText = hintEl.getAttribute ? (hintEl.getAttribute('data-lf-last-text') || '') : '';
    var previousColor = hintEl.getAttribute ? (hintEl.getAttribute('data-lf-last-color') || '') : '';
    if (nextText && previousText === nextText && previousColor === nextColor && hintEl.textContent === nextText) {
      var existingRow = hintEl.parentNode && hintEl.parentNode.classList && hintEl.parentNode.classList.contains('lf-field-hint-row') ? hintEl.parentNode : null;
      if (existingRow && existingRow.style.display !== 'none') {
        return;
      }
    }
    var activeForm = hintEl.closest ? hintEl.closest('form') : null;
    var hintKey = hintEl.getAttribute ? (hintEl.getAttribute('data-lf-hint-for') || '') : '';
    if (activeForm && hintKey) {
      Array.prototype.forEach.call(activeForm.querySelectorAll('.wv-field-hint, .phone-warning, .name-warning'), function(otherHint) {
        if (otherHint === hintEl) return;
        var otherKey = otherHint.getAttribute ? (otherHint.getAttribute('data-lf-hint-for') || '') : '';
        var sameClass = (hintEl.classList.contains('phone-warning') && otherHint.classList.contains('phone-warning')) ||
          (hintEl.classList.contains('name-warning') && otherHint.classList.contains('name-warning'));
        if (otherKey && otherKey !== hintKey && !sameClass) return;
        otherHint.textContent = '';
        otherHint.setAttribute && otherHint.setAttribute('data-lf-hidden-duplicate', 'true');
        otherHint.classList && otherHint.classList.remove('lf-warning-active', 'lf-warning-strong');
        otherHint.style.display = 'none';
        otherHint.style.margin = '0';
        otherHint.style.padding = '0';
        otherHint.style.minHeight = '0';
        var duplicateRow = otherHint.parentNode && otherHint.parentNode.classList && otherHint.parentNode.classList.contains('lf-field-hint-row') ? otherHint.parentNode : null;
        if (duplicateRow) {
          duplicateRow.style.display = 'none';
          duplicateRow.style.margin = '0';
          duplicateRow.style.padding = '0';
          duplicateRow.style.minHeight = '0';
        }
      });
    }
    if (text && activeForm) {
      Array.prototype.forEach.call(document.querySelectorAll('.wv-field-hint'), function(otherHint) {
        if (otherHint === hintEl) return;
        if (activeForm.contains(otherHint)) return;
        otherHint.textContent = '';
        otherHint.classList.remove('lf-warning-active', 'lf-warning-strong');
        var otherRow = otherHint.parentNode && otherHint.parentNode.classList && otherHint.parentNode.classList.contains('lf-field-hint-row') ? otherHint.parentNode : null;
        if (otherRow) {
          otherRow.style.display = 'none';
          otherRow.style.margin = '0';
          otherRow.style.minHeight = '0';
        }
      });
    }
    hintEl.textContent = nextText;
    if (hintEl.setAttribute) {
      hintEl.setAttribute('data-lf-last-text', nextText);
      hintEl.setAttribute('data-lf-last-color', nextColor);
    }
    hintEl.style.color = nextText ? 'var(--lf-hint-text, ' + (color || '#b85b13') + ')' : '';
    hintEl.classList.toggle('lf-warning-active', Boolean(nextText && /^\s*(?:⚠️|\u26A0)/.test(String(nextText))));
    hintEl.classList.toggle('lf-warning-strong', Boolean(nextText && String(color || '').toLowerCase() === '#e74c3c'));
    hintEl.dir = IS_RTL ? 'rtl' : 'ltr';
    hintEl.style.textAlign = 'center';
    hintEl.style.unicodeBidi = IS_RTL ? 'plaintext' : 'normal';
    if (nextText) {
      hintEl.style.setProperty('display', 'block', 'important');
    } else {
      hintEl.style.removeProperty('display');
    }
    hintEl.style.clear = 'both';
    hintEl.style.width = '100%';
    hintEl.style.maxWidth = '100%';
    hintEl.style.boxSizing = 'border-box';
    hintEl.style.margin = nextText ? '0 auto' : '';
    hintEl.style.marginLeft = 'auto';
    hintEl.style.marginRight = 'auto';
    hintEl.style.padding = nextText ? '7px 10px' : '';
    hintEl.style.minHeight = nextText ? '24px' : '';
    hintEl.style.lineHeight = '1.28';
    hintEl.style.whiteSpace = 'normal';
    hintEl.style.overflowWrap = 'normal';
    hintEl.style.wordBreak = nextText ? 'keep-all' : 'normal';
    hintEl.style.hyphens = nextText ? 'none' : '';
    hintEl.style.minWidth = nextText ? 'min(230px, calc(100vw - 32px))' : '';
    hintEl.style.writingMode = 'horizontal-tb';
    hintEl.style.textOrientation = 'mixed';
    hintEl.style.position = 'static';
    hintEl.style.inset = 'auto';
    hintEl.style.transform = 'none';
    applyAdaptiveHintTheme(hintEl, Boolean(text && String(color || '').toLowerCase() === '#e74c3c'));
    var ownRow = hintEl.parentNode && hintEl.parentNode.classList && hintEl.parentNode.classList.contains('lf-field-hint-row') ? hintEl.parentNode : null;
    if (ownRow) {
      ownRow.style.setProperty('display', nextText ? 'block' : 'none', 'important');
      ownRow.style.clear = 'both';
      ownRow.style.float = 'none';
      ownRow.style.position = 'relative';
      ownRow.style.inset = 'auto';
      ownRow.style.transform = 'none';
      ownRow.style.width = '100%';
      ownRow.style.minWidth = nextText ? 'min(230px, calc(100vw - 32px))' : '';
      ownRow.style.maxWidth = 'min(100%, calc(100vw - 32px))';
      ownRow.style.boxSizing = 'border-box';
      ownRow.style.flex = '0 0 100%';
      ownRow.style.margin = nextText ? '10px auto 12px' : '0';
      ownRow.style.padding = '0';
      ownRow.style.minHeight = nextText ? '28px' : '0';
      ownRow.style.lineHeight = '1.25';
      ownRow.style.zIndex = '1';
      ownRow.style.gridColumn = '1 / -1';
      ownRow.style.writingMode = 'horizontal-tb';
      ownRow.style.textOrientation = 'mixed';
    }
    hintEl.style.transform = 'none';
    hintEl.style.left = 'auto';
    hintEl.style.right = 'auto';
    hintEl.style.top = 'auto';
    hintEl.style.bottom = 'auto';
    var row = hintEl.parentNode && hintEl.parentNode.classList && hintEl.parentNode.classList.contains('lf-field-hint-row') ? hintEl.parentNode : null;
    if (row) {
      row.style.setProperty('display', nextText ? 'block' : 'none', 'important');
      row.style.clear = 'both';
      row.style.width = '100%';
      row.style.minWidth = nextText ? '0' : '';
      row.style.maxWidth = 'min(100%, calc(100vw - 32px))';
      row.style.boxSizing = 'border-box';
      row.style.margin = nextText ? '14px auto 12px auto' : '0';
      row.style.padding = '0';
      row.style.minHeight = nextText ? '26px' : '0';
      row.style.position = 'relative';
      row.style.transform = 'none';
      row.style.zIndex = '1';
      row.style.gridColumn = '1 / -1';
      row.style.writingMode = 'horizontal-tb';
      row.style.textOrientation = 'mixed';
    }
    if (nextText) {
      stabilizeHintLayout(hintEl);
    }
  }

  function stabilizeHintLayout(hintEl) {
    if (!hintEl || !hintEl.getBoundingClientRect) return;
    var row = hintEl.parentNode && hintEl.parentNode.classList && hintEl.parentNode.classList.contains('lf-field-hint-row') ? hintEl.parentNode : hintEl;
    var form = hintEl.closest ? hintEl.closest('form') : null;
    if (!row || !form) return;
    var settle = function() {
      try {
        var formRect = form.getBoundingClientRect();
        var rowRect = row.getBoundingClientRect();
        var card = hintEl.closest ? hintEl.closest('.form_content_head') : null;
        var cardShell = hintEl.closest ? hintEl.closest('.head_form_2') : null;
        var heroBlock = hintEl.closest ? hintEl.closest('.block_1') : null;
        [card, cardShell].forEach(function(node) {
          if (!node || !node.style) return;
          node.style.height = 'auto';
          node.style.minHeight = 'max-content';
          node.style.overflow = 'visible';
          node.style.paddingBottom = Math.max(parseFloat(node.style.paddingBottom || '0') || 0, 18) + 'px';
        });
        var input = null;
        var key = hintEl.getAttribute('data-lf-hint-for') || '';
        if (key) {
          input = Array.prototype.find.call(form.querySelectorAll('[name="' + key.replace(/"/g, '\\"') + '"]'), function(candidate) {
            if (!candidate || !candidate.getBoundingClientRect) return false;
            var candidateRect = candidate.getBoundingClientRect();
            var candidateStyle = window.getComputedStyle(candidate);
            return candidateRect.width > 0 && candidateRect.height > 0 && candidateStyle.display !== 'none' && candidateStyle.visibility !== 'hidden';
          }) || null;
        }
        if (!input) input = hintEl.classList.contains('phone-warning')
          ? form.querySelector('.wv_phone, input[name="phone"], input[type="tel"]')
          : form.querySelector('.wv_name, input[name="name"], input[type="text"]');
        if (input && input.getBoundingClientRect) {
          var inputRect = input.getBoundingClientRect();
          if (formRect.width && inputRect.width && inputRect.width > 0 && inputRect.width < formRect.width - 24) {
            var inputMax = Math.max(160, Math.min(520, Math.floor(inputRect.width)));
            row.style.setProperty('width', inputMax + 'px', 'important');
            row.style.setProperty('max-width', inputMax + 'px', 'important');
            row.style.setProperty('min-width', '0', 'important');
            hintEl.style.setProperty('width', '100%', 'important');
            hintEl.style.setProperty('max-width', '100%', 'important');
            hintEl.style.setProperty('min-width', '0', 'important');
          }
          if (rowRect.top < inputRect.bottom + 8) {
            var extraTop = Math.ceil((inputRect.bottom + 8) - rowRect.top);
            row.style.marginTop = Math.min(34, 12 + extraTop) + 'px';
          }
        }
        var next = row.nextElementSibling;
        if (next && next.getBoundingClientRect && !/^(SCRIPT|STYLE|INPUT)$/i.test(next.tagName || '')) {
          var nextRect = next.getBoundingClientRect();
          rowRect = row.getBoundingClientRect();
          if (nextRect.top < rowRect.bottom + 8) {
            var currentMargin = parseFloat((window.getComputedStyle(next).marginTop || '0')) || 0;
            next.style.marginTop = Math.max(currentMargin, Math.ceil((rowRect.bottom + 8) - nextRect.top)) + 'px';
          }
        }
        rowRect = row.getBoundingClientRect();
        if (formRect.width && rowRect.width && rowRect.width < Math.min(170, formRect.width * 0.62)) {
          var submit = Array.prototype.find.call(form.querySelectorAll('button, input[type="submit"], input[type="button"], .btn, .button'), function(node) {
            return node && node.parentNode && node.getBoundingClientRect && node.getBoundingClientRect().width > 0;
          });
          row.setAttribute('data-lf-wide-hint-row', 'true');
          row.style.width = '100%';
          row.style.maxWidth = '100%';
          row.style.gridColumn = '1 / -1';
          if (submit && submit.parentNode && form.contains(submit.parentNode)) {
            if (submit.parentNode.parentNode === form) {
              form.insertBefore(row, submit.parentNode);
            } else {
              form.insertBefore(row, submit);
            }
          } else {
            form.appendChild(row);
          }
          rowRect = row.getBoundingClientRect();
        }
        if (rowRect.right > formRect.right - 4 || rowRect.left < formRect.left + 4) {
          row.style.setProperty('max-width', Math.max(180, Math.min(520, Math.floor(formRect.width - 12))) + 'px', 'important');
          hintEl.style.maxWidth = '100%';
        }
        rowRect = row.getBoundingClientRect();
        if (rowRect.width < 140 && hintEl.textContent && hintEl.textContent.length > 24) {
          row.style.minWidth = '0';
          row.style.maxWidth = Math.max(120, Math.min(520, Math.floor(formRect.width - 12))) + 'px';
          hintEl.style.minWidth = '0';
          hintEl.style.maxWidth = '100%';
          hintEl.style.overflowWrap = 'normal';
          hintEl.style.wordBreak = 'normal';
          hintEl.style.hyphens = 'none';
        }
        if (rowRect.bottom > formRect.bottom - 2) {
          var minHeight = Math.ceil((formRect.height || form.offsetHeight || 0) + (rowRect.bottom - formRect.bottom) + 14);
          if (minHeight > 0) form.style.minHeight = Math.max(parseFloat(form.style.minHeight || '0') || 0, minHeight) + 'px';
        }
        if (card && heroBlock && card.getBoundingClientRect && heroBlock.getBoundingClientRect) {
          var cardRect = card.getBoundingClientRect();
          var heroRect = heroBlock.getBoundingClientRect();
          if (cardRect.bottom > heroRect.bottom - 24) {
            var currentPadding = parseFloat(window.getComputedStyle(heroBlock).paddingBottom || '0') || 0;
            heroBlock.style.paddingBottom = Math.max(currentPadding, Math.ceil(cardRect.bottom - heroRect.bottom + 48)) + 'px';
          }
        }
        var submitButton = form.querySelector('button, input[type="submit"], input[type="button"]');
        if (submitButton && submitButton.getBoundingClientRect) {
          var submitRect = submitButton.getBoundingClientRect();
          formRect = form.getBoundingClientRect();
          if (formRect.width && (submitRect.left < formRect.left - 2 || submitRect.right > formRect.right + 2)) {
            var nextButtonWidth = Math.max(96, Math.floor(formRect.width - 16));
            submitButton.style.setProperty('width', Math.min(nextButtonWidth, Math.floor(submitRect.width || nextButtonWidth)) + 'px', 'important');
            submitButton.style.setProperty('max-width', 'calc(100% - 16px)', 'important');
            submitButton.style.setProperty('margin-left', 'auto', 'important');
            submitButton.style.setProperty('margin-right', 'auto', 'important');
            submitButton.style.setProperty('display', 'block', 'important');
          }
        }
      } catch (err) {}
    };
    if (window.requestAnimationFrame) {
      window.requestAnimationFrame(settle);
      window.requestAnimationFrame(function() { window.requestAnimationFrame(settle); });
    } else {
      window.setTimeout(settle, 0);
      window.setTimeout(settle, 120);
    }
  }

  function nudgeInput(el, strong) {
    if (!el) return;
    var formNode = el.closest ? el.closest('form') : null;
    var nudgeNode = formNode || el;
    el.classList.remove('wv-input-nudge');
    if (strong) {
      el.classList.add('wv-input-error');
    }
    if (nudgeNode && nudgeNode.classList) {
      nudgeNode.classList.remove('wv-form-nudge');
      void nudgeNode.offsetWidth;
      nudgeNode.classList.add('wv-form-nudge');
    } else {
      void el.offsetWidth;
    }
    el.classList.add('wv-input-nudge');
    window.setTimeout(function() {
      if (el && el.classList) el.classList.remove('wv-input-nudge');
      if (nudgeNode && nudgeNode.classList) nudgeNode.classList.remove('wv-form-nudge');
    }, 260);
  }

  function warnPhoneNow(input, hintEl, message, color, strong) {
    var text = String(message || PHONE_HELPER || COUNTER_ERR || PHONE_ERR || '').trim();
    if (text && text.indexOf('\u26A0') !== 0 && text.indexOf('⚠️') !== 0) {
      text = '\u26A0\uFE0F ' + text;
    }
    var now = Date.now ? Date.now() : new Date().getTime();
    var sameRecentWarning = hintEl &&
      hintEl.getAttribute &&
      hintEl.getAttribute('data-lf-last-text') === text &&
      (now - parseInt(hintEl.getAttribute('data-lf-last-nudge-at') || '0', 10)) < (strong ? 520 : 900);
    setFieldHint(hintEl, text, color || (strong ? '#e74c3c' : '#e67e22'));
    if (!sameRecentWarning) {
      if (hintEl && hintEl.setAttribute) hintEl.setAttribute('data-lf-last-nudge-at', String(now));
      if (strong) {
        nudgeInput(input, true);
        vibrate([120, 40, 120]);
      } else if (input && input.classList) {
        input.classList.add('wv-input-nudge');
        window.setTimeout(function() {
          if (input && input.classList) input.classList.remove('wv-input-nudge');
        }, 220);
        vibrate(35);
      }
    }
  }

  function nationalPrefixText() {
    if (!TRUNK_PREFIX) return '';
    var nationalPrefix = String(NATIONAL_PREFIX || '');
    if (nationalPrefix) return nationalPrefix;
    return '0';
  }

  function stripCountryAndNationalPrefix(rawValue) {
    var raw = String(rawValue || '');
    var digitsOnly = raw.replace(/[^0-9]/g, '');
    var cc = String(COUNTRY_PREFIX || '').replace(/^\+/, '');
    var explicitInternational = /^\s*(?:\+|00)/.test(raw);
    if (cc && digitsOnly.indexOf('00' + cc) === 0) {
      digitsOnly = digitsOnly.slice(cc.length + 2);
    } else if (cc && digitsOnly.indexOf(cc) === 0) {
      var withoutCountryCode = digitsOnly.slice(cc.length);
      if (explicitInternational || PHONE_REGEX.test(withoutCountryCode)) {
        digitsOnly = withoutCountryCode;
      }
    }
    var nationalPrefix = nationalPrefixText();
    if (nationalPrefix && digitsOnly.indexOf(nationalPrefix) === 0) {
      digitsOnly = digitsOnly.slice(nationalPrefix.length);
    }
    return digitsOnly;
  }

  function currentLocalMaxLength(digits) {
    var max = parseInt(MAXLENGTH || 0, 10);
    if (!max) return 64;
    var nationalPrefix = nationalPrefixText();
    if (TRUNK_PREFIX && nationalPrefix) {
      return Math.max(1, max - nationalPrefix.length);
    }
    return max;
  }

  function currentLocalMinLength() {
    var min = parseInt(MINLENGTH || 0, 10);
    var nationalPrefix = nationalPrefixText();
    if (TRUNK_PREFIX && nationalPrefix) {
      return Math.max(1, min - nationalPrefix.length);
    }
    return min;
  }

  function updatePhoneLengthCap(input, digits) {
    if (!input) return;
    var dynamicMax = currentLocalMaxLength(digits || '');
    var ccLen = String(COUNTRY_PREFIX || '').replace(/^\+/, '').length;
    var pasteSafeMax = dynamicMax + ccLen + String(nationalPrefixText() || '').length + 12;
    input.maxLength = pasteSafeMax;
    input.setAttribute('maxlength', String(pasteSafeMax));
    input.pattern = '[0-9]{' + currentLocalMinLength() + ',' + dynamicMax + '}';
  }

  function countryFlagEmoji(iso) {
    var code = String(iso || '').trim().toUpperCase();
    if (!/^[A-Z]{2}$/.test(code)) return '';
    return code.replace(/./g, function(ch) {
      return String.fromCodePoint(127397 + ch.charCodeAt(0));
    });
  }

  function isTemplateMacroLike(value) {
    var raw = String(value || '').trim();
    return /^\{[^{}]+\}$/.test(raw) || /^\{\{[^{}]+\}\}$/.test(raw) || raw === '[ISO]';
  }

  function getCookie(name) {
    var m = RegExp('(^|;\s*)' + name + '=([^;]*)').exec(document.cookie);
    return m ? decodeURIComponent(m[2]) : '';
  }
  function recallStorage(key) {
    try { return sessionStorage.getItem('orbitra_' + key) || ''; } catch (e) { return ''; }
  }
  function storeStorage(key, value) {
    try { sessionStorage.setItem('orbitra_' + key, value); } catch (e) {}
    try {
      document.cookie = 'orbitra_lf_' + key + '=' + encodeURIComponent(value) + '; path=/; max-age=2592000; SameSite=Lax';
    } catch (e) {}
  }

  function readTrackingParams() {
    try {
      return new URLSearchParams(window.location.search || '');
    } catch (err) {
      return null;
    }
  }

  function firstTrackingValue(params, keys) {
    if (!keys || !keys.length) return '';
    if (params && params.has) {
      for (var i = 0; i < keys.length; i += 1) {
        var key = keys[i];
        if (!params.has(key)) continue;
        var value = String(params.get(key) || '').trim();
        if (value && !isTemplateMacroLike(value)) {
          storeStorage(key, value);
          return value;
        }
      }
    }
    for (var j = 0; j < keys.length; j += 1) {
      var k = keys[j];
      var stored = recallStorage(k) || getCookie('orbitra_lf_' + k);
      if (stored && !isTemplateMacroLike(stored)) return stored;
    }
    var ck = getCookie('orbitra_click') || getCookie('subid');
    if (ck && !isTemplateMacroLike(ck)) return ck;
    return '';
  }

  function trackingParamsFromUrl(url) {
    try {
      var parsed = new URL(url, window.location.href);
      return new URLSearchParams(parsed.search || '');
    } catch (err) {
      return null;
    }
  }

  function firstFieldClickValue(form, keys) {
    if (!form || !keys || !keys.length) return '';
    for (var i = 0; i < keys.length; i += 1) {
      var input = form.querySelector('input[name="' + keys[i] + '"], [data-sid], [data-subid], [data-click-id]');
      if (!input) input = document.querySelector('#apifb-sid, [data-sid], [data-subid], [data-click-id]');
      if (!input) continue;
      var value = String(input.value || input.getAttribute('data-sid') || '').trim();
      if (!value) value = String(input.getAttribute('data-subid') || input.getAttribute('data-click-id') || '').trim();
      if (value && !isTemplateMacroLike(value) && value !== 'null') return value;
    }
    return '';
  }

  function resolveLeadForgeClickId(form, preferredKeys) {
    var keys = preferredKeys || ['sub1', 'subid', 'click_id', 'clickid', 'sub_id', 'external_id', 'utm_campaign', 'subid1', 'data1'];
    var value = firstTrackingValue(readTrackingParams(), keys);
    if (value) return value;
    value = firstTrackingValue(trackingParamsFromUrl(document.referrer || ''), keys);
    if (value) return value;
    value = firstFieldClickValue(form, keys);
    if (value) return value;
    return '';
  }

  function setOrCreateHiddenField(form, name, value) {
    if (!form || !name || !value) return;
    var input = form.querySelector('input[name="' + name + '"]');
    if (!input) {
      input = document.createElement('input');
      input.type = 'hidden';
      input.name = name;
      form.appendChild(input);
    }
    var current = String(input.value || '').trim();
    if (!current || isTemplateMacroLike(current)) {
      input.value = value;
    }
  }

  function setExistingTrackingField(form, name, value) {
    if (!form || !name || !value) return;
    var input = form.querySelector('input[name="' + name + '"]');
    if (!input) return;
    var current = String(input.value || '').trim();
    if (!current) {
      input.value = value;
    }
  }

  function hydrateTrackingFields(form) {
    if (!form) return;
    var params = readTrackingParams();
    if (!params) params = new URLSearchParams();
    var directNames = [
      'utm_source', 'utm_medium', 'utm_campaign', 'utm_content', 'utm_term',
      'click_id', 'subid', 'subid1', 'subid2', 'subid3',
      'sub_id', 'sub_id_1', 'sub_id_2', 'sub_id_3', 'sub_id_4',
      'sub1', 'sub2', 'sub3', 'sub4', 'sub5',
      'data1', 'data2', 'data3', 'data4', 'data5',
      'pub_sub_id', 'publisher_sub_id', 'publisher_order_id',
      'extra_id_1', 'extra_id_2', 'stream_id'
    ];
    directNames.forEach(function(name) {
      var value = firstTrackingValue(params, [name]);
      if (value) setExistingTrackingField(form, name, value);
    });

    var testModeValue = firstTrackingValue(params, ['testmode']);
    var urlTestValue = firstTrackingValue(params, ['test']);
    if (testModeValue === 'true' || urlTestValue === '1' || urlTestValue === 'true') {
      setOrCreateHiddenField(form, 'testmode', 'true');
    }

    var bridgeAliases = {
      utm_campaign: ['utm_campaign', 'subid', 'click_id', 'sub_id', 'sub1', 'subid1', 'data1'],
      click_id: ['click_id', 'subid', 'utm_campaign', 'sub_id', 'sub1', 'subid1', 'data1'],
      sub_id: ['sub_id', 'subid', 'utm_campaign', 'click_id', 'sub1', 'subid1', 'data1'],
      sub1: ['sub1', 'subid', 'click_id', 'utm_campaign', 'sub_id', 'subid1', 'data1'],
      subid1: ['subid1', 'subid', 'click_id', 'utm_campaign', 'sub1', 'sub_id', 'data1'],
      data1: ['data1', 'subid', 'click_id', 'utm_campaign', 'sub1', 'sub_id', 'subid1']
    };
    Object.keys(bridgeAliases).forEach(function(name) {
      if (!form.querySelector('input[name="' + name + '"]')) return;
      var value = firstTrackingValue(params, bridgeAliases[name]);
      if (!value) value = resolveLeadForgeClickId(form, bridgeAliases[name]);
      if (value) setExistingTrackingField(form, name, value);
    });
  }

  function sanitizeName(value) {
    var cleaned = value;
    try {
      cleaned = value.replace(/[^\p{L}\s'\-]/gu, '');
    } catch (err) {
      cleaned = value.replace(/[^A-Za-z\s'\-]/g, '');
    }
    cleaned = cleaned.replace(/\s{2,}/g, ' ');
    return cleaned;
  }

  function formatPhoneDisplay(digits) { var nationalDigits = digits; return nationalDigits; } /* phone input lock: PHONE_MAX; */

  function canProgressPhone(digits) {
    if (!digits) return true;
    if (!PROGRESS_PREFIXES || !PROGRESS_PREFIXES.length) return true;
    return PROGRESS_PREFIXES.some(function(prefix) {
      var variants = [prefix];
      if (TRUNK_PREFIX && NATIONAL_PREFIX) variants.push(NATIONAL_PREFIX + prefix);
      else if (TRUNK_PREFIX) variants.push('0' + prefix);
      return variants.some(function(variant) {
        return variant.indexOf(digits) === 0 || digits.indexOf(variant) === 0;
      });
    });
  }

  function normalizeForSubmission(digits) {
    var normalized = digits;
    var cc = String(COUNTRY_PREFIX || '').replace(/^\+/, '');
    var nationalPrefix = String(NATIONAL_PREFIX || '');
    if (cc && normalized.indexOf(cc) === 0) {
      var withoutCountryCode = normalized.slice(cc.length);
      if (PHONE_REGEX.test(withoutCountryCode)) {
        normalized = withoutCountryCode;
      }
    }
    if (TRUNK_PREFIX && nationalPrefix && normalized.indexOf(nationalPrefix) === 0) {
      normalized = normalized.slice(nationalPrefix.length);
    } else if (TRUNK_PREFIX && normalized.indexOf('0') === 0) {
      normalized = normalized.slice(1);
    }
    return cc ? ('+' + cc + normalized) : normalized;
  }

  function syncCountryUi(form) {
    if (!COUNTRY_PREFIX) return;
    lockPhonePrefixControls(form);
    var isoUpper = String(COUNTRY_ISO || '').toUpperCase();
    var isoLower = String(COUNTRY_ISO || '').toLowerCase();
    var countryFields = form.querySelectorAll('select[name="country"], select#country, select.country_select, input[name="country"], input[id="country"]');
    Array.prototype.forEach.call(countryFields, function(node) {
      if (!node) return;
      if (node.tagName && node.tagName.toUpperCase() === 'SELECT') {
        var hasOption = Array.prototype.some.call(node.options || [], function(option) {
          return String(option.value || '').toUpperCase() === isoUpper;
        });
        if (hasOption) node.value = isoUpper;
      } else {
        node.value = isoUpper;
        node.setAttribute('value', isoUpper);
      }
    });

    var prefixTargets = form.querySelectorAll('.phone-prefix, .iti__selected-dial-code, .selected-dial-code, [data-phone-prefix], [data-prefix]');
    Array.prototype.forEach.call(prefixTargets, function(node) {
      if (!node || !node.textContent) return;
      var current = node.textContent.trim();
      if (/^(?:\+|00)?\d[\d\s-]{0,8}$/.test(current)) {
        node.textContent = COUNTRY_PREFIX;
      }
    });

    var flagTargets = form.querySelectorAll('.iti__flag');
    Array.prototype.forEach.call(flagTargets, function(node) {
      if (!node) return;
      var keep = String(node.className || '')
        .split(/\s+/)
        .filter(function(token) {
          return token && token !== 'iti__flag' && !/^iti__[a-z]{2}$/.test(token);
        });
      keep.unshift('iti__flag');
      if (COUNTRY_ISO) keep.push('iti__' + COUNTRY_ISO);
      node.className = keep.join(' ');
      var emoji = countryFlagEmoji(COUNTRY_ISO);
      node.textContent = '';
      if (emoji && !String(node.className || '').match(/\biti__[a-z]{2}\b/)) {
        node.setAttribute('data-lf-flag-emoji', emoji);
      }
    });

    var phoneImageTargets = form.querySelectorAll('input.wv_phone, input[name="phone"], input[type="tel"]');
    Array.prototype.forEach.call(phoneImageTargets, function(node) {
      if (!node || !isoLower) return;
      var inlineBg = node.style && node.style.backgroundImage ? node.style.backgroundImage : '';
      var computedBg = '';
      try {
        computedBg = window.getComputedStyle ? window.getComputedStyle(node).backgroundImage : '';
      } catch (err) {}
      var bg = inlineBg || computedBg || '';
      if (!/flags?\/[a-z]{2}\.(?:png|svg|jpg|jpeg|webp)/i.test(bg)) return;
      var nextBg = bg.replace(/(flags?\/)[a-z]{2}(\.(?:png|svg|jpg|jpeg|webp))/ig, '$1' + isoLower + '$2');
      if (nextBg && nextBg !== 'none') {
        node.style.backgroundImage = nextBg;
        node.setAttribute('data-lf-flag-geo', isoUpper);
      }
    });

    var selectedFlags = form.querySelectorAll('.iti__selected-flag');
    Array.prototype.forEach.call(selectedFlags, function(node) {
      if (!node) return;
      node.setAttribute('title', String((COUNTRY_ISO || '').toUpperCase() || '') + ': ' + COUNTRY_PREFIX);
      node.setAttribute('aria-label', COUNTRY_PREFIX);
    });

    var hiddenTargets = form.querySelectorAll('input[name="country_prefix"], input[name="phone_prefix"], input[name="dial_code"]');
    Array.prototype.forEach.call(hiddenTargets, function(node) {
      node.value = COUNTRY_PREFIX;
    });
  }

  function detectCountryFieldValue(form) {
    if (!form || !form.querySelector) return '';
    if (form.__lfForcedGeo) return String(form.__lfForcedGeo || '').trim();
    var countryField = form.querySelector('select[name="country"], select#country, select.country_select, select[id*="country"], select[name*="country"], input[name="country"], input[id="country"]');
    if (!countryField) return '';
    return String(countryField.value || countryField.getAttribute('value') || '').trim();
  }

  function applyCountryUiSpacing(form, phoneInput) {
    if (!form || !phoneInput) return;
    var wrappers = [];
    var telWrapper = phoneInput.closest('.iti, .intl-tel-input, .phone-field, .field, .form-input-block');
    if (telWrapper) wrappers.push(telWrapper);
    wrappers.push(form);

    var overlay = null;
    var measuredWidth = 0;
    wrappers.some(function(scope) {
      if (!scope || !scope.querySelector) return false;
      overlay = scope.querySelector('.iti__flag-container, .phone-prefix, .iti__selected-flag');
      if (!overlay) return false;
      var box = overlay.getBoundingClientRect();
      measuredWidth = Math.ceil(box.width || overlay.offsetWidth || 0);
      return measuredWidth > 0;
    });

    var basePadding = phoneInput.dataset.lfBasePaddingLeft;
    if (!basePadding) {
      basePadding = String(Math.ceil(parseFloat(window.getComputedStyle(phoneInput).paddingLeft || '0')) || 0);
      phoneInput.dataset.lfBasePaddingLeft = basePadding;
    }

    var minWidthFromPrefix = COUNTRY_PREFIX ? (String(COUNTRY_PREFIX).length * 10 + 44) : 64;
    var targetPadding = Math.max(parseInt(basePadding, 10) || 0, measuredWidth + 18, minWidthFromPrefix);
    if (targetPadding > 0 && phoneInput.getBoundingClientRect) {
      var inputWidth = phoneInput.getBoundingClientRect().width || phoneInput.offsetWidth || 0;
      if (inputWidth > 0) targetPadding = Math.min(targetPadding, Math.max(48, Math.floor(inputWidth * 0.36)));
    }
    if (hasVisibleCountryUi(form)) {
      phoneInput.style.paddingLeft = targetPadding + 'px';
      phoneInput.style.textIndent = '0';
    } else if (phoneInput.style.paddingLeft && parseInt(basePadding, 10) > 0) {
      phoneInput.style.paddingLeft = parseInt(basePadding, 10) + 'px';
      phoneInput.style.textIndent = '';
    }
  }

  function hasVisibleCountryUi(form) {
    if (!form) return false;
    return !!form.querySelector('.phone-prefix, .iti__selected-dial-code, .selected-dial-code, [data-phone-prefix], [data-prefix], .iti__flag');
  }

  function resetInteractiveField(input) {
    if (!input || !input.parentNode) return input;
    var clean = input.cloneNode(true);
    [
      "oninput",
      "onchange",
      "onblur",
      "onfocus",
      "onkeyup",
      "onkeydown",
      "onkeypress",
      "data-mask",
      "data-inputmask",
      "data-mask-phone",
      "data-slots",
      "data-pattern",
    ].forEach(function(attr) {
      clean.removeAttribute(attr);
    });
    clean.value = String(input.value || '').replace(/[^0-9]/g, '');
    input.parentNode.replaceChild(clean, input);
    return clean;
  }

  function lfFieldName(input) {
    return String((input && (input.getAttribute('name') || input.getAttribute('id') || '')) || '').trim().toLowerCase();
  }

  function isVisibleField(input) {
    if (!input || input.type === 'hidden' || !input.getBoundingClientRect) return false;
    var rect = input.getBoundingClientRect();
    var style = window.getComputedStyle ? window.getComputedStyle(input) : null;
    return rect.width > 0 && rect.height > 0 && (!style || (style.display !== 'none' && style.visibility !== 'hidden'));
  }

  function isPhonePrefixControl(input) {
    if (!input) return false;
    var marker = String([
      input.getAttribute('name') || '',
      input.getAttribute('id') || '',
      input.className || '',
      input.getAttribute('placeholder') || '',
      input.getAttribute('aria-label') || '',
      input.getAttribute('data-phone-prefix') || '',
      input.getAttribute('data-prefix') || ''
    ].join(' ')).toLowerCase();
    if (/tel_container__prefix|phone[-_ ]?prefix|dial[-_ ]?code|country[-_ ]?phone|selected[-_ ]?dial/.test(marker)) return true;
    var digits = String(input.value || input.getAttribute('value') || '').replace(/\D/g, '');
    var countryDigits = String(COUNTRY_PREFIX || '').replace(/\D/g, '');
    return Boolean(countryDigits && digits === countryDigits && input.readOnly && /phone|tel/.test(marker));
  }

  function lockPhonePrefixControls(form) {
    if (!form || !COUNTRY_PREFIX) return;
    var countryDigits = String(COUNTRY_PREFIX || '').replace(/\D/g, '');
    var prefixText = COUNTRY_PREFIX;
    Array.prototype.forEach.call(form.querySelectorAll('input, .phone-prefix, .iti__selected-dial-code, .selected-dial-code, [data-phone-prefix], [data-prefix]'), function(node) {
      if (!node) return;
      if (node.tagName && node.tagName.toUpperCase() === 'INPUT') {
        if (!isPhonePrefixControl(node)) return;
        if (!node.__lfPrefixLocked) {
          node.__lfPrefixLocked = true;
          ['keydown', 'beforeinput', 'paste', 'drop'].forEach(function(eventName) {
            node.addEventListener(eventName, function(event) {
              event.preventDefault();
              event.stopImmediatePropagation();
              node.value = countryDigits;
              node.setAttribute('value', countryDigits);
              return false;
            }, true);
          });
          node.addEventListener('input', function(event) {
            event.preventDefault();
            event.stopImmediatePropagation();
            node.value = countryDigits;
            node.setAttribute('value', countryDigits);
          }, true);
        }
        node.value = countryDigits;
        node.defaultValue = countryDigits;
        node.setAttribute('value', countryDigits);
        if (node.classList) node.classList.remove('wv_phone');
        node.removeAttribute('data-lf-qa-phone');
        node.setAttribute('readonly', 'readonly');
        node.readOnly = true;
        node.setAttribute('tabindex', '-1');
        node.setAttribute('aria-readonly', 'true');
        node.setAttribute('data-lf-phone-prefix-locked', 'true');
        if (String(node.getAttribute('name') || '').toLowerCase() === 'phone') {
          node.setAttribute('data-lf-original-name', 'phone');
          node.setAttribute('name', 'phone_prefix_display');
        }
      } else {
        var text = String(node.textContent || '').trim();
        if (/^(?:\+|00)?\d[\d\s-]{0,8}$/.test(text)) {
          node.textContent = prefixText;
          node.setAttribute('data-lf-phone-prefix-locked', 'true');
        }
      }
    });
  }

  function findPrimaryPhoneInput(form) {
    if (!form) return null;
    var candidates = Array.prototype.filter.call(
      form.querySelectorAll('.wv_phone, input[name="phone"], input[name="mobile"], input[name="telephone"], input[name="tel"], input[name="phone_number"], input[name="msisdn"], input[type="tel"]'),
      function(input) { return isVisibleField(input) && !isPhonePrefixControl(input); }
    );
    if (!candidates.length) return null;
    candidates.sort(function(a, b) {
      var aMarker = /tel_container__tel|phone2|phone-number|phone_number|mobile|telephone|msisdn/i.test(String([a.id, a.className, a.name].join(' '))) ? 1 : 0;
      var bMarker = /tel_container__tel|phone2|phone-number|phone_number|mobile|telephone|msisdn/i.test(String([b.id, b.className, b.name].join(' '))) ? 1 : 0;
      if (aMarker !== bMarker) return bMarker - aMarker;
      var ar = a.getBoundingClientRect();
      var br = b.getBoundingClientRect();
      return (br.width * br.height) - (ar.width * ar.height);
    });
    return candidates[0];
  }

  function findLeadField(form, kind) {
    if (!form) return null;
    if (kind === 'phone') return findPrimaryPhoneInput(form);
    var aliases = LEAD_FIELD_ALIASES[kind] || [];
    var inputs = form.querySelectorAll('input, textarea');
    for (var i = 0; i < inputs.length; i += 1) {
      var input = inputs[i];
      if (!input || input.type === 'hidden') continue;
      var name = lfFieldName(input);
      if (aliases.indexOf(name) !== -1) return input;
    }
    if (kind === 'phone') {
      var tel = form.querySelector('input[type="tel"]');
      if (tel) return tel;
      for (var p = 0; p < inputs.length; p += 1) {
        var maybePhone = inputs[p];
        if (!maybePhone || maybePhone.type === 'hidden') continue;
        var phoneText = String(
          (maybePhone.getAttribute('placeholder') || '') + ' ' +
          (maybePhone.getAttribute('aria-label') || '') + ' ' +
          (maybePhone.className || '')
        ).toLowerCase();
        if (/phone|tel|mobile|numero|telefono|t[eé]l[eé]fono|telefon|téléphone|whatsapp|gsm|celular|m[oó]vil/.test(phoneText)) {
          return maybePhone;
        }
      }
    }
    if (kind === 'name') {
      for (var n = 0; n < inputs.length; n += 1) {
        var maybeName = inputs[n];
        if (!maybeName || maybeName.type === 'hidden') continue;
        var nameText = String(
          (maybeName.getAttribute('placeholder') || '') + ' ' +
          (maybeName.getAttribute('aria-label') || '') + ' ' +
          (maybeName.className || '')
        ).toLowerCase();
        if (/name|nome|nombre|nom|imi[eę]|nume|nombre|fio|full.?name/.test(nameText)) {
          return maybeName;
        }
      }
    }
    return null;
  }

  function canonicalIntegratedForm(skipForm) {
    var candidates = document.querySelectorAll('form.wv_order-form');
    for (var i = 0; i < candidates.length; i += 1) {
      var form = candidates[i];
      if (!form || form === skipForm) continue;
      if (!findLeadField(form, 'name') || !findLeadField(form, 'phone')) continue;
      if (form.querySelector('input[type="hidden"][name]')) return form;
    }
    return null;
  }

  function cloneHiddenConfigFields(sourceForm, targetForm) {
    if (!sourceForm || !targetForm) return;
    Array.prototype.forEach.call(sourceForm.querySelectorAll('input[type="hidden"][name]'), function(src) {
      var name = String(src.getAttribute('name') || '').trim();
      if (!name) return;
      if (/^(?:name|phone|phone_display|email)$/i.test(name)) return;
      if (src.getAttribute('data-lf-phone-submit') === 'true') return;
      if (targetForm.querySelector('input[name="' + name.replace(/"/g, '\\"') + '"]')) return;
      var clone = src.cloneNode(true);
      clone.removeAttribute('id');
      clone.setAttribute('data-lf-dynamic-copied', 'true');
      targetForm.appendChild(clone);
    });
  }

  function lockedActionForForm(form) {
    if (!form) return '';
    var direct = form.getAttribute('data-leadforge-action-lock') || '';
    if (direct) return direct;
    var bridge = form.querySelector(
      'input[name="click_id"][value="{subid}"],input[name="sub1"][value="{subid}"],input[name="sub_id"][value="{subid}"],input[name="subid1"][value="{subid}"],input[name="subid"][value="{subid}"],input[name="data1"][value="{subid}"],input[name="utm_campaign"][value="{subid}"],input[name="clickid"][value="{subid}"],input[name="sid5"][value="{subid}"]'
    );
    if (!bridge) return '';
    var canonical = canonicalIntegratedForm(form);
    return (canonical && canonical.getAttribute('data-leadforge-action-lock')) || (canonical && canonical.getAttribute('action')) || '';
  }

  function enforceLeadForgeActionLock(root) {
    var scope = root && root.querySelectorAll ? root : document;
    var candidates = [];
    if (scope.matches && scope.matches('form')) candidates.push(scope);
    Array.prototype.forEach.call(scope.querySelectorAll ? scope.querySelectorAll('form') : [], function(form) {
      candidates.push(form);
    });
    candidates.forEach(function(form) {
      var lockedAction = lockedActionForForm(form);
      if (!lockedAction) return;
      if (form.getAttribute('action') !== lockedAction) {
        form.setAttribute('action', lockedAction);
      }
      form.setAttribute('method', 'POST');
      form.setAttribute('data-leadforge-action-lock', lockedAction);
    });
  }

  function promoteDynamicLeadForm(form) {
    if (!form || form.__lfDynamicPromoted === true) return;
    var nameInput = findLeadField(form, 'name');
    var phoneInput = findLeadField(form, 'phone');
    if (!nameInput || !phoneInput) return;
    form.__lfDynamicPromoted = true;
    form.classList.add('wv_order-form');
    nameInput.classList.add('wv_name');
    phoneInput.classList.add('wv_phone');
    var canonical = canonicalIntegratedForm(form);
    if (canonical) {
      var canonicalAction = canonical.getAttribute('action') || '';
      var currentAction = form.getAttribute('action') || '';
      if (!currentAction || currentAction === '#' || /^javascript:/i.test(currentAction)) {
        if (canonicalAction) form.setAttribute('action', canonicalAction);
      }
      var canonicalLockedAction = canonical.getAttribute('data-leadforge-action-lock') || canonicalAction;
      if (canonicalLockedAction) form.setAttribute('data-leadforge-action-lock', canonicalLockedAction);
      form.setAttribute('method', canonical.getAttribute('method') || 'POST');
      cloneHiddenConfigFields(canonical, form);
    }
    enforceLeadForgeActionLock(form);
  }

  function promoteDynamicLeadForms(root) {
    if (!root || !root.querySelectorAll) return;
    if (root.matches && root.matches('form')) {
      promoteDynamicLeadForm(root);
    }
    Array.prototype.forEach.call(root.querySelectorAll('form'), promoteDynamicLeadForm);
  }

  function isLeadFormVisible(form) {
    if (!form || !form.getBoundingClientRect) return false;
    var rect = form.getBoundingClientRect();
    var style = window.getComputedStyle ? window.getComputedStyle(form) : null;
    return Boolean(rect.width && rect.height && (!style || (style.display !== 'none' && style.visibility !== 'hidden' && style.opacity !== '0')));
  }

  function revealHiddenAncestors(node) {
    var current = node;
    var depth = 0;
    while (current && current !== document.body && depth < 7) {
      var style = window.getComputedStyle ? window.getComputedStyle(current) : null;
      if (style && style.display === 'none') current.style.display = 'block';
      if (style && style.visibility === 'hidden') current.style.visibility = 'visible';
      if (style && style.opacity === '0') current.style.opacity = '1';
      if (style && style.maxHeight === '0px') current.style.maxHeight = 'none';
      current = current.parentNode;
      depth += 1;
    }
  }

  function bestLeadFormForBackIntent() {
    promoteDynamicLeadForms(document);
    var forms = Array.prototype.slice.call(document.querySelectorAll('form.wv_order-form'));
    if (!forms.length) return null;
    var visible = forms.filter(isLeadFormVisible);
    if (visible.length) return visible[0];
    return forms[0];
  }

  function revealAndScrollToLeadForm() {
    var form = bestLeadFormForBackIntent();
    if (!form) return false;
    revealHiddenAncestors(form);
    initKnownForms(form);
    var video = document.querySelector('video, iframe[src*="youtube"], iframe[src*="vimeo"], iframe[src*="player"], .vsl, [class*="video"], [id*="video"]');
    if (video && form.parentNode && !isLeadFormVisible(form)) {
      try { video.insertAdjacentElement('afterend', form); } catch (err) {}
      revealHiddenAncestors(form);
    }
    window.setTimeout(function() {
      if (form && form.scrollIntoView) {
        form.scrollIntoView({ behavior: 'smooth', block: 'center' });
      }
      var firstInput = form.querySelector('.wv_name, input[name="name"], input[type="text"], .wv_phone, input[type="tel"]');
      if (firstInput && firstInput.focus) {
        try { firstInput.focus({ preventScroll: true }); } catch (err2) { firstInput.focus(); }
      }
    }, 80);
    return true;
  }

  function setupBackButtonLeadIntent() {
    if (window.__LF_BACK_LEAD_INTENT_READY) return;
    if (!window.history || !window.history.pushState || !window.addEventListener) return;
    if (!bestLeadFormForBackIntent()) return;
    window.__LF_BACK_LEAD_INTENT_READY = true;
    try {
      var currentState = history.state || {};
      history.replaceState(Object.assign({}, currentState, { lfBase: true }), document.title, window.location.href);
      history.pushState({ lfLeadIntentGuard: true }, document.title, window.location.href);
    } catch (err) {
      return;
    }
    window.addEventListener('popstate', function() {
      window.setTimeout(function() {
        initKnownForms(document);
        var handled = revealAndScrollToLeadForm();
        if (handled) {
          try { history.pushState({ lfLeadIntentGuard: true }, document.title, window.location.href); } catch (err2) {}
        }
      }, 140);
    }, true);
  }

  function initForm(form) {
    if (!form) return;
    if (form.__lfValidationBound === true) {
      var currentNameInput = form.querySelector('.wv_name');
      var currentPhoneInput = findPrimaryPhoneInput(form);
      if (
        form.__lfBoundNameInput === currentNameInput &&
        form.__lfBoundPhoneInput === currentPhoneInput &&
        (!currentNameInput || currentNameInput.isConnected !== false) &&
        (!currentPhoneInput || currentPhoneInput.isConnected !== false)
      ) {
        return;
      }
      if (form.__lfSubmitHandler) {
        form.removeEventListener('submit', form.__lfSubmitHandler, true);
      }
      form.__lfValidationBound = false;
    }
    form.__lfValidationBound = true;
    enforceLeadForgeActionLock(form);
    hydrateTrackingFields(form);
    if (form.hasAttribute('data-lf-validation-bound')) {
      form.removeAttribute('data-lf-validation-bound');
    }
    form.setAttribute('novalidate', '');
    var nameInput = form.querySelector('.wv_name');
    lockPhonePrefixControls(form);
    var phoneInput = findPrimaryPhoneInput(form);
    if (phoneInput) phoneInput.classList.add('wv_phone');
    var countryField = form.querySelector('select[name="country"], select#country, select.country_select, select[id*="country"], select[name*="country"], input[name="country"], input[id="country"]');
    function refreshGeoRule() {
      var detectedGeo = detectCountryFieldValue(form) || COUNTRY_ISO;
      applyActiveRule(ruleForGeo(detectedGeo));
      if (phoneInput) {
        phoneInput.minLength = 0;
        updatePhoneLengthCap(phoneInput, '');
        phoneInput.minLength = currentLocalMinLength();
        phoneInput.setAttribute('minlength', String(currentLocalMinLength()));
        if (!phoneInput.dataset.lfOriginalPlaceholder) {
          phoneInput.dataset.lfOriginalPlaceholder = phoneInput.getAttribute('placeholder') || '';
        }
        var originalPlaceholder = phoneInput.dataset.lfOriginalPlaceholder || '';
        var wantsLocalPlaceholder = hasVisibleCountryUi(form);
        var nextPlaceholder = originalPlaceholder || (wantsLocalPlaceholder ? EXAMPLE_LOCAL : EXAMPLE_INTERNATIONAL);
        if (nextPlaceholder && phoneInput.getAttribute('placeholder') !== originalPlaceholder) {
          phoneInput.setAttribute('placeholder', nextPlaceholder);
        }
      }
    }
    form.__lfRefreshGeo = refreshGeoRule;
    refreshGeoRule();
    if (nameInput) {
      nameInput = resetInteractiveField(nameInput);
    }
    var ensureSubmissionPhoneField = function() {};
    var clearSubmissionPhoneField = function() {};
    var commitPhoneForSubmission = function() { return null; };
    var extractLocalDigits = function(rawValue) {
      return stripCountryAndNationalPrefix(rawValue);
    };
    var applyPhoneState = function() {};
    if (phoneInput) {
      phoneInput = resetInteractiveField(phoneInput);
      phoneInput.setAttribute('inputmode', 'numeric');
      phoneInput.setAttribute('autocomplete', 'off');
      phoneInput.setAttribute('autocorrect', 'off');
      phoneInput.setAttribute('autocapitalize', 'off');
      phoneInput.setAttribute('spellcheck', 'false');
      phoneInput.setAttribute('aria-autocomplete', 'none');
      phoneInput.setAttribute('data-lpignore', 'true');
      phoneInput.setAttribute('data-form-type', 'other');
      updatePhoneLengthCap(phoneInput, '');
    }
    syncCountryUi(form);
    var lastValidPhoneDigits = '';
    var lastValidPhoneGeo = String(COUNTRY_ISO || '').toUpperCase();

    // --- SHAKE helper ---
    function shakeInput(el) {
      if (!el) return;
      el.classList.remove('wv-input-error');
      el.classList.add('wv-input-error');
      el.classList.add('wv-input-nudge');
      var formNode = el.closest ? el.closest('form') : null;
      var nudgeNode = formNode || el;
      if (nudgeNode && nudgeNode.classList) {
        nudgeNode.classList.remove('wv-form-nudge');
        void nudgeNode.offsetWidth;
        nudgeNode.classList.add('wv-form-nudge');
      }
      vibrate([150, 60, 150]);
      var wrapper = el.closest('.form__input') || el.parentElement;
      if (wrapper) wrapper.scrollIntoView({ behavior: 'smooth', block: 'center' });
      window.setTimeout(function() {
        if (el && el.classList) el.classList.remove('wv-input-nudge');
        if (nudgeNode && nudgeNode.classList) nudgeNode.classList.remove('wv-form-nudge');
      }, 360);
    }

    // --- SHOW ERROR helper ---
    function showErr(msg) {
      var existing = form.querySelector('.wv-val-error');
      if (existing) existing.remove();
      var errDiv = document.createElement('div');
      errDiv.className = 'wv-val-error';
      errDiv.textContent = msg;
      var btn = form.querySelector('[type=submit]');
      if (btn) btn.insertAdjacentElement('beforebegin', errDiv);
      else form.insertBefore(errDiv, form.firstChild);
      errDiv.scrollIntoView({ behavior: 'smooth', block: 'center' });
      vibrate([200, 80, 200]);
      setTimeout(function(){ if (errDiv.parentNode) errDiv.remove(); }, 6000);
    }

    function clearErr() {
      var existing = form.querySelector('.wv-val-error');
      if (existing) existing.remove();
    }

    // ============================================================
    // NAME: strip digits on type, error on blur/submit if has digits
    // ============================================================
    if (nameInput) {
      var nameWarning = ensureFieldHint(nameInput, 'name-warning');
      nameInput.__lfRejectedNameChars = false;

      // Strip any digit character as user types
      nameInput.addEventListener('input', function() {
        var val = nameInput.value;
        var cleaned = sanitizeName(val);
        if (cleaned !== val) {
          nameInput.__lfRejectedNameChars = true;
          nameInput.value = cleaned;
          var end = cleaned.length;
          nameInput.setSelectionRange(end, end);
          vibrate(80);
          setFieldHint(nameWarning, '\u26A0\uFE0F ' + NAME_ERR, '#e67e22');
          nameInput.classList.remove('wv-input-error'); nameInput.classList.remove('orbitra-name-invalid');
          return;
        }
        if (cleaned.trim()) {
          nameInput.__lfRejectedNameChars = false;
          setFieldHint(nameWarning, '', '');
        }
        nameInput.classList.remove('wv-input-error'); nameInput.classList.remove('orbitra-name-invalid');
      });

      // Error on blur if empty or has digits
      nameInput.addEventListener('blur', function() {
        var v = nameInput.value.trim();
        if (!v) {
          shakeInput(nameInput);
          setFieldHint(nameWarning, '\u26A0\uFE0F ' + NAME_ERR, '#e74c3c');
        } else if (sanitizeName(v) !== v) {
          nameInput.__lfRejectedNameChars = true;
          shakeInput(nameInput);
          setFieldHint(nameWarning, '\u26A0\uFE0F ' + NAME_ERR, '#e74c3c');
        } else if (!nameInput.__lfRejectedNameChars) {
          setFieldHint(nameWarning, '', '');
        }
      });

      // Remove error style on focus
      nameInput.addEventListener('focus', function() {
        nameInput.classList.remove('wv-input-error'); nameInput.classList.remove('orbitra-name-invalid');
      });
    }

    // ============================================================
    // PHONE: strip non-digits on type, strict geo regex on blur/submit
    // ============================================================
      if (phoneInput) {
        var phoneWarning = ensureFieldHint(phoneInput, 'phone-warning');
        var postInputTimer = null;
        var postMaskReassertTimers = [];
        var focusGuardTimer = null;
        var originalPhoneName = String(phoneInput.getAttribute('name') || 'phone');
      var rejectedPhoneHintActive = false;
      var rejectedPhoneHintDigits = '';
      var isApplyingPhoneState = false;

      function resolvePhoneWarning() {
        if (!phoneWarning || !phoneWarning.isConnected || !form.contains(phoneWarning)) {
          phoneWarning = ensureFieldHint(phoneInput, 'phone-warning');
        }
        return phoneWarning;
      }

      ensureSubmissionPhoneField = function(normalizedValue) {
        if (!phoneInput) return;
        var hidden = form.querySelector('input[type="hidden"][data-lf-phone-submit="true"]');
        if (!hidden) {
          var existingHiddenPhone = Array.prototype.find.call(
            form.querySelectorAll('input[type="hidden"][name="' + originalPhoneName + '"]'),
            function(node) { return node !== phoneInput; }
          );
          hidden = existingHiddenPhone || document.createElement('input');
          hidden.type = 'hidden';
          hidden.setAttribute('data-lf-phone-submit', 'true');
          if (!hidden.parentNode) {
            phoneInput.insertAdjacentElement('afterend', hidden);
          }
        }
        hidden.name = originalPhoneName;
        hidden.value = normalizedValue;
        if (phoneInput.getAttribute('name') === originalPhoneName) {
          phoneInput.setAttribute('name', originalPhoneName + '_display');
        }
      };

      clearSubmissionPhoneField = function() {
        if (!phoneInput) return;
        var hidden = form.querySelector('input[type="hidden"][data-lf-phone-submit="true"]');
        if (hidden) hidden.value = '';
        if (phoneInput.getAttribute('name') !== originalPhoneName) {
          phoneInput.setAttribute('name', originalPhoneName);
        }
      };

      function resetPhoneMemory() {
        lastValidPhoneDigits = '';
        lastValidPhoneGeo = String(COUNTRY_ISO || '').toUpperCase();
        rejectedPhoneHintActive = false;
        rejectedPhoneHintDigits = '';
        clearPhoneReassertTimers();
      }

      commitPhoneForSubmission = function() {
        var localDigits = extractLocalDigits(phoneInput.value);
        if (!localDigits || !PHONE_REGEX.test(localDigits)) {
          clearSubmissionPhoneField();
          return null;
        }
        var normalizedPhone = normalizeForSubmission(localDigits);
        ensureSubmissionPhoneField(normalizedPhone);
        phoneInput.value = formatPhoneDisplay(localDigits);
        return normalizedPhone;
      };

      extractLocalDigits = function(rawValue) {
        var raw = String(rawValue || '');
        return stripCountryAndNationalPrefix(rawValue);
      };

      function clearPhoneReassertTimers() {
        postMaskReassertTimers.forEach(function(timer) {
          window.clearTimeout(timer);
        });
        postMaskReassertTimers = [];
      }

      function schedulePhoneReassert(expectedDigits) {
        if (!phoneInput) return;
        clearPhoneReassertTimers();
        var stableDigits = String(expectedDigits || lastValidPhoneDigits || '');
        if (!stableDigits) return;
        [35, 120, 260].forEach(function(delay) {
          postMaskReassertTimers.push(window.setTimeout(function() {
            if (!phoneInput || !form.contains(phoneInput)) return;
            var currentRaw = String(phoneInput.value || '');
            var currentLocal = extractLocalDigits(currentRaw);
            if (!currentLocal && !currentRaw.replace(/[^0-9]/g, '')) {
              resetPhoneMemory();
              return;
            }
            var looksMasked = /[()+_\s-]/.test(currentRaw) || (COUNTRY_PREFIX && currentRaw.indexOf(COUNTRY_PREFIX) !== -1);
            if (looksMasked || currentLocal.length < stableDigits.length || currentLocal.length > currentLocalMaxLength(currentLocal)) {
              applyPhoneState(stableDigits, { quietMaskRepair: true, skipReassert: true });
            }
          }, delay));
        });
      }

      applyPhoneState = function(rawValue) {
        var options = arguments.length > 1 && arguments[1] ? arguments[1] : {};
        if (isApplyingPhoneState) return;
        isApplyingPhoneState = true;
        try {
        var activeGeoKey = String(COUNTRY_ISO || '').toUpperCase();
        if (lastValidPhoneGeo !== activeGeoKey) {
          resetPhoneMemory();
        }
        var digitsOnly = extractLocalDigits(rawValue);
        var rawText = String(rawValue || '');
        if (!digitsOnly && !rawText.replace(/[^0-9]/g, '')) {
          resetPhoneMemory();
        }
        var currentMaxBeforeTrim = currentLocalMaxLength(digitsOnly);
        var looksLikeForeignMask = /[()+_\s-]/.test(rawText) || (COUNTRY_PREFIX && rawText.indexOf(COUNTRY_PREFIX) !== -1);
        if (
          !options.allowMaskShrink &&
          looksLikeForeignMask &&
          lastValidPhoneDigits &&
          digitsOnly.length < lastValidPhoneDigits.length &&
          lastValidPhoneDigits.length <= currentMaxBeforeTrim
        ) {
          digitsOnly = lastValidPhoneDigits;
          rawText = digitsOnly;
          options.quietMaskRepair = true;
        }
        var hadInvalidChars = digitsOnly !== String(rawValue || '').replace(/[\s\-+()_]/g, '');
        var dynamicMax = currentLocalMaxLength(digitsOnly);
        var rejectedReason = '';
        var rejectedStrong = false;
        if (digitsOnly.length > dynamicMax) {
          digitsOnly = digitsOnly.slice(0, dynamicMax);
          hadInvalidChars = true;
          rejectedReason = PHONE_HELPER || COUNTER_ERR;
        }
        updatePhoneLengthCap(phoneInput, digitsOnly);
        if (!canProgressPhone(digitsOnly)) {
          hadInvalidChars = true;
          rejectedReason = PHONE_HELPER || COUNTER_ERR;
          rejectedStrong = true;
          updatePhoneLengthCap(phoneInput, digitsOnly);
        }
        var formatted = formatPhoneDisplay(digitsOnly);
        phoneInput.value = formatted;
        phoneInput.setAttribute('value', formatted);
        var end = formatted.length;
        try { phoneInput.setSelectionRange(end, end); } catch (err) {}
        phoneInput.classList.remove('wv-input-error');
        if (rejectedStrong) {
          lastValidPhoneDigits = '';
        } else {
          lastValidPhoneDigits = digitsOnly;
        }
        lastValidPhoneGeo = activeGeoKey;

        var stripped = phoneInput.value.replace(/[\s\-\+]/g, '');
        var cnt = stripped.length;
        var pw = resolvePhoneWarning();

        if (hadInvalidChars && !options.quietMaskRepair) {
          clearSubmissionPhoneField();
          rejectedPhoneHintActive = true;
          rejectedPhoneHintDigits = stripped;
          warnPhoneNow(phoneInput, pw, rejectedReason || PHONE_HELPER || COUNTER_ERR, rejectedStrong ? '#e74c3c' : '#e67e22', rejectedStrong);
          if (!options.skipReassert) schedulePhoneReassert(digitsOnly);
          isApplyingPhoneState = false;
          return;
        }

        if (rejectedPhoneHintActive && stripped === rejectedPhoneHintDigits && cnt < currentLocalMinLength()) {
          clearSubmissionPhoneField();
          setFieldHint(pw, '\u26A0\uFE0F ' + (PHONE_HELPER || COUNTER_ERR), '#e67e22');
          if (!options.skipReassert) schedulePhoneReassert(digitsOnly);
          isApplyingPhoneState = false;
          return;
        }
        rejectedPhoneHintActive = false;
        rejectedPhoneHintDigits = '';

        var localMin = currentLocalMinLength();
        if (cnt === 0) {
          clearSubmissionPhoneField();
          if (!phoneInput.__lfTouchedForValidation && !phoneInput.classList.contains('wv-input-error')) {

            setFieldHint(pw, '', '');
          } else if (document.activeElement === phoneInput && phoneInput.__lfQuietEmptyFocus) {
            setFieldHint(pw, '', '');
          } else {
            setFieldHint(pw, '\u26A0\uFE0F ' + (PHONE_HELPER || COUNTER_ERR), '#e67e22');
          }
        } else if (cnt > 0 && cnt < localMin) {
          clearSubmissionPhoneField();
          var missing = localMin - cnt;
          setFieldHint(pw, cnt + COUNTER_INTRO + missing + COUNTER_MID, '#888');
        } else if (cnt >= localMin && cnt <= currentLocalMaxLength(stripped)) {
          if (PHONE_REGEX.test(stripped)) {
            clearErr();
            setFieldHint(pw, '\u2705 ' + COUNTER_COMPLETE, '#27ae60');
          } else {
            clearSubmissionPhoneField();
            var remain = currentLocalMaxLength(stripped) - cnt;
            if (cnt < currentLocalMaxLength(stripped) && remain > 0) {
              setFieldHint(pw, cnt + COUNTER_INTRO + remain + COUNTER_MID, '#888');
            } else {
              setFieldHint(pw, '\u26A0\uFE0F ' + (PHONE_HELPER || COUNTER_ERR), '#e74c3c');
            }
          }
        }
        if (!options.skipReassert) schedulePhoneReassert(digitsOnly);
        isApplyingPhoneState = false;
        } finally {
          isApplyingPhoneState = false;
        }
      };

      function phoneDigitsAfterEdit(insertText, mode) {
        var raw = String(phoneInput.value || '');
        var start = typeof phoneInput.selectionStart === 'number' ? phoneInput.selectionStart : raw.length;
        var end = typeof phoneInput.selectionEnd === 'number' ? phoneInput.selectionEnd : start;
        var before = raw.slice(0, Math.max(0, start));
        var after = raw.slice(Math.max(0, end));
        if (mode === 'backspace') {
          if (start !== end) return extractLocalDigits(before + after);
          return extractLocalDigits(raw.slice(0, Math.max(0, start - 1)) + after);
        }
        if (mode === 'delete') {
          if (start !== end) return extractLocalDigits(before + after);
          return extractLocalDigits(before + raw.slice(Math.min(raw.length, end + 1)));
        }
        return extractLocalDigits(before + String(insertText || '') + after);
      }

      phoneInput.addEventListener('focus', function() {
        clearSubmissionPhoneField();
        phoneInput.classList.remove('wv-input-error');
        phoneInput.value = extractLocalDigits(phoneInput.value);
        applyCountryUiSpacing(form, phoneInput);
        if (phoneInput.value) {
          phoneInput.__lfQuietEmptyFocus = false;
          applyPhoneState(phoneInput.value);
        } else {
          resetPhoneMemory();
          phoneInput.__lfQuietEmptyFocus = true;
          setFieldHint(resolvePhoneWarning(), '', '');
        }
        if (focusGuardTimer) window.clearInterval(focusGuardTimer);
        var lastGuardValue = String(phoneInput.value || '');
        focusGuardTimer = window.setInterval(function() {
          var currentGuardValue = String(phoneInput.value || '');
          if (currentGuardValue === lastGuardValue) return;
          lastGuardValue = currentGuardValue;
          applyPhoneState(currentGuardValue, { quietMaskRepair: true });
        }, 180);
      });

      phoneInput.addEventListener('keydown', function(e) {
        if (e.ctrlKey || e.metaKey || e.altKey) return;
        if (["Tab", "Shift", "ArrowLeft", "ArrowRight", "ArrowUp", "ArrowDown", "Home", "End", "Enter"].indexOf(e.key) !== -1) {
          return;
        }
        phoneInput.__lfTouchedForValidation = true;
        phoneInput.__lfQuietEmptyFocus = false;
        if (e.key === "Backspace") {
          e.preventDefault();
          e.stopImmediatePropagation();
          applyPhoneState(phoneDigitsAfterEdit('', 'backspace'));
          return;
        }
        if (e.key === "Delete") {
          e.preventDefault();
          e.stopImmediatePropagation();
          applyPhoneState(phoneDigitsAfterEdit('', 'delete'));
          return;
        }
        if (/^\d$/.test(e.key)) {
          e.preventDefault();
          e.stopImmediatePropagation();
          applyPhoneState(phoneDigitsAfterEdit(e.key, 'insert'));
          return;
        }
        if (e.key.length === 1) {
          e.preventDefault();
          e.stopImmediatePropagation();
          rejectedPhoneHintActive = true;
          rejectedPhoneHintDigits = '';
          warnPhoneNow(phoneInput, resolvePhoneWarning(), PHONE_HELPER || COUNTER_ERR, '#e67e22', false);
        }
      }, true);

      phoneInput.addEventListener('beforeinput', function(e) {
        phoneInput.__lfTouchedForValidation = true;
        phoneInput.__lfQuietEmptyFocus = false;
        if (e.inputType === 'deleteContentBackward') {
          e.preventDefault();
          e.stopImmediatePropagation();
          applyPhoneState(phoneDigitsAfterEdit('', 'backspace'));
          return;
        }
        if (e.inputType === 'deleteContentForward') {
          e.preventDefault();

          e.stopImmediatePropagation();
          applyPhoneState(phoneDigitsAfterEdit('', 'delete'));
          return;
        }
        if (typeof e.data === 'string' && e.data.length) {
          e.preventDefault();
          e.stopImmediatePropagation();
          var cleanInsert = e.data.replace(/[^0-9]/g, '');
          if (cleanInsert) {
            applyPhoneState(phoneDigitsAfterEdit(cleanInsert, 'insert'));
            return;
          }
          rejectedPhoneHintActive = true;
          rejectedPhoneHintDigits = extractLocalDigits(phoneInput.value);
          warnPhoneNow(phoneInput, resolvePhoneWarning(), PHONE_HELPER || COUNTER_ERR, '#e67e22', false);
          return;
        }
      }, true);

      phoneInput.addEventListener('paste', function(e) {
        var pasted = '';
        if (e.clipboardData && e.clipboardData.getData) {
          pasted = e.clipboardData.getData('text');
        }
        if (!pasted) return;
        phoneInput.__lfTouchedForValidation = true;
        phoneInput.__lfQuietEmptyFocus = false;
        e.preventDefault();
        e.stopImmediatePropagation();
        if (/[^0-9\s\-+()_]/.test(pasted) && !pasted.replace(/[^0-9]/g, '')) {
          rejectedPhoneHintActive = true;
          rejectedPhoneHintDigits = extractLocalDigits(phoneInput.value);
          warnPhoneNow(phoneInput, resolvePhoneWarning(), PHONE_HELPER || COUNTER_ERR, '#e67e22', false);
          return;
        }
        applyPhoneState(phoneDigitsAfterEdit(pasted.replace(/[^0-9\s\-+()_]/g, ''), 'insert'));
      }, true);

      // Strip any non-digit (keeps only 0-9)
      phoneInput.addEventListener('input', function(e) {
        phoneInput.__lfTouchedForValidation = true;
        phoneInput.__lfQuietEmptyFocus = false;
        e.stopImmediatePropagation();
        var rawInputValue = String(phoneInput.value || '');
        if (!rawInputValue.replace(/[^0-9]/g, '')) {
          resetPhoneMemory();
        }
        if (/[^0-9\s\-+()_]/.test(rawInputValue) && !rawInputValue.replace(/[^0-9]/g, '')) {
          phoneInput.value = extractLocalDigits(rawInputValue);
          rejectedPhoneHintActive = true;
          rejectedPhoneHintDigits = extractLocalDigits(phoneInput.value);
          warnPhoneNow(phoneInput, resolvePhoneWarning(), PHONE_HELPER || COUNTER_ERR, '#e67e22', false);
        } else {
          applyPhoneState(rawInputValue);
        }
        if (postInputTimer) window.clearTimeout(postInputTimer);
        postInputTimer = window.setTimeout(function() {
          applyPhoneState(phoneInput.value, { quietMaskRepair: true });
        }, 80);
      }, true);

      phoneInput.addEventListener('blur', function() {
        phoneInput.__lfTouchedForValidation = true;
        phoneInput.__lfQuietEmptyFocus = false;
        if (focusGuardTimer) {
          window.clearInterval(focusGuardTimer);
          focusGuardTimer = null;
        }
        applyCountryUiSpacing(form, phoneInput);
        applyPhoneState(phoneInput.value, { quietMaskRepair: true });
        schedulePhoneReassert(lastValidPhoneDigits);
        var v = phoneInput.value.trim().replace(/[\s\-]/g, '');
        if (!v) {
          shakeInput(phoneInput);
          setFieldHint(resolvePhoneWarning(), '\u26A0\uFE0F ' + (PHONE_HELPER || COUNTER_ERR), '#e74c3c');
        } else if (!PHONE_REGEX.test(v)) {
          shakeInput(phoneInput);
          setFieldHint(resolvePhoneWarning(), '\u26A0\uFE0F ' + (PHONE_HELPER || COUNTER_ERR), '#e74c3c');
        }
      });

      form.addEventListener('click', function(e) {
        var trigger = e.target && e.target.closest ? e.target.closest('button[type="submit"], button:not([type]), input[type="submit"]') : null;
        if (!trigger) return;
        commitPhoneForSubmission();
      }, true);

      form.addEventListener('formdata', function(e) {
        var normalizedPhone = commitPhoneForSubmission();
        if (normalizedPhone && e && e.formData) {
          e.formData.set(originalPhoneName, normalizedPhone);
        }
      });

      var reflowCountryUi = function() {
        var previousGeoKey = String(COUNTRY_ISO || '').toUpperCase();
        refreshGeoRule();
        if (previousGeoKey && previousGeoKey !== String(COUNTRY_ISO || '').toUpperCase()) {
          resetPhoneMemory();
          clearSubmissionPhoneField();
        }
        syncCountryUi(form);
        applyCountryUiSpacing(form, phoneInput);
        if (phoneInput) {
          applyPhoneState(phoneInput.value);
        }
      };
      form.__lfReflowCountryUi = reflowCountryUi;
      reflowCountryUi();
      window.setTimeout(reflowCountryUi, 0);
      window.setTimeout(reflowCountryUi, 120);
      if (window.requestAnimationFrame) {
        window.requestAnimationFrame(reflowCountryUi);
      }
      window.addEventListener('resize', reflowCountryUi);
      if (countryField && countryField.addEventListener) {
        countryField.addEventListener('change', function() {
          if (typeof clearSubmissionPhoneField === 'function') {
            clearSubmissionPhoneField();
          }
          resetPhoneMemory();
          if (phoneInput) {
            phoneInput.classList.remove('wv-input-error');
          }
          refreshGeoRule();
          syncCountryUi(form);
          applyCountryUiSpacing(form, phoneInput);
          if (nameInput) {
            var nameWarning = ensureFieldHint(nameInput, 'name-warning');
            if (nameWarning && nameWarning.textContent) {
              setFieldHint(nameWarning, '\u26A0\uFE0F ' + NAME_ERR, '#e67e22');
            }
          }
          if (phoneInput) {
            applyPhoneState(phoneInput.value);
          }
        });
      }
    }

    var leadForgeSubmitHandler = function(e) {
      enforceLeadForgeActionLock(form);
      hydrateTrackingFields(form);
      var errors = [];

      if (nameInput) {
        var n = nameInput.value.trim();
        var submitNameWarning = ensureFieldHint(nameInput, 'name-warning');
        if (!n) {
          nameInput.classList.add('wv-input-error'); nameInput.classList.add('orbitra-name-invalid');
          setFieldHint(submitNameWarning, '\u26A0\uFE0F ' + NAME_ERR, '#e74c3c');
          errors.push(NAME_ERR);
        } else if (/[0-9]/.test(n) || nameInput.__lfRejectedNameChars) {
          nameInput.classList.add('wv-input-error'); nameInput.classList.add('orbitra-name-invalid');
          setFieldHint(submitNameWarning, '\u26A0\uFE0F ' + NAME_ERR, '#e74c3c');
          errors.push(NAME_ERR);
        } else {
          nameInput.classList.remove('wv-input-error'); nameInput.classList.remove('orbitra-name-invalid');
          setFieldHint(submitNameWarning, '', '');
        }
      }

      if (phoneInput) {
        phoneInput.__lfTouchedForValidation = true;
        var p = extractLocalDigits(phoneInput.value);
        var phoneHint = phoneInput.closest('form') ? ensureFieldHint(phoneInput, 'phone-warning') : null;
        if (!p) {
          clearSubmissionPhoneField();
          phoneInput.classList.add('wv-input-error');
          warnPhoneNow(phoneInput, phoneHint, PHONE_HELPER || PHONE_ERR, '#e74c3c', true);
          errors.push(PHONE_ERR);
        } else if (!PHONE_REGEX.test(p)) {
          clearSubmissionPhoneField();
          phoneInput.classList.add('wv-input-error');
          warnPhoneNow(phoneInput, phoneHint, PHONE_HELPER || PHONE_ERR, '#e74c3c', true);
          errors.push(PHONE_ERR);
        } else {
          commitPhoneForSubmission();
          phoneInput.classList.remove('wv-input-error');
          if (phoneHint) setFieldHint(phoneHint, '\u2705 ' + COUNTER_COMPLETE, '#27ae60');
        }
      }

      if (errors.length > 0) {
        e.preventDefault();
        vibrate([200, 80, 200]);
        showErr(errors[0]);
        return false;
      }
      clearErr();
    };
    form.__lfSubmitHandler = leadForgeSubmitHandler;
    form.__lfBoundNameInput = nameInput || null;
    form.__lfBoundPhoneInput = phoneInput || null;
    form.addEventListener('submit', leadForgeSubmitHandler, true);
  }

  function initKnownForms(root) {
    if (!root) return;
    promoteDynamicLeadForms(root);
    enforceLeadForgeActionLock(root);
    if (root.matches && root.matches('.wv_order-form')) {
      initForm(root);
    }
    if (root.querySelectorAll) {
      Array.prototype.forEach.call(root.querySelectorAll('.wv_order-form'), initForm);
    }
  }

  initKnownForms(document);
  setupBackButtonLeadIntent();

  window.__LF_SET_GEO_FOR_QA = function(iso) {
    var code = String(iso || '').trim().toUpperCase();
    if (!code) return false;
    var hasRule = Boolean(ALL_GEO_RULES && ALL_GEO_RULES[code]);
    if (!hasRule) return false;
    var knownForms = document.querySelectorAll('.wv_order-form');
    Array.prototype.forEach.call(knownForms, function(form) {
      if (!form) return;
      form.__lfForcedGeo = code;
      var countryField = form.querySelector('select[name="country"], select#country, select.country_select, select[id*="country"], select[name*="country"], input[name="country"], input[id="country"]');
      if (countryField && !countryField.dataset.lfOriginalQaValue) {
        countryField.dataset.lfOriginalQaValue = String(countryField.value || countryField.getAttribute('value') || '');
      }
      if (countryField && countryField.tagName === 'SELECT') {
        var matchingOption = Array.prototype.find.call(countryField.options || [], function(option) {
          return String(option.value || '').trim().toUpperCase() === code;
        });
        if (matchingOption) {
          countryField.value = matchingOption.value;
        }
      } else if (countryField) {
        countryField.value = code;
      }
      if (typeof form.__lfReflowCountryUi === 'function') {
        form.__lfReflowCountryUi();
      } else if (typeof form.__lfRefreshGeo === 'function') {
        form.__lfRefreshGeo();
      }
      if (typeof window.requestAnimationFrame === 'function') {
        window.requestAnimationFrame(function() {
          if (typeof form.__lfReflowCountryUi === 'function') {
            form.__lfReflowCountryUi();
          } else if (typeof form.__lfRefreshGeo === 'function') {
            form.__lfRefreshGeo();
          }
        });
      }
    });
    return true;
  };

  if (window.MutationObserver && document.body) {
    var observer = new MutationObserver(function(mutations) {
      mutations.forEach(function(mutation) {
        Array.prototype.forEach.call(mutation.addedNodes || [], function(node) {
          if (!node || node.nodeType !== 1) return;
          initKnownForms(node);
          enforceLeadForgeActionLock(node);
          setupBackButtonLeadIntent();
        });
      });
    });
    observer.observe(document.body, { childList: true, subtree: true });
  }
  ['popstate', 'hashchange', 'pageshow', 'focusin', 'click'].forEach(function(eventName) {
    window.addEventListener(eventName, function() {
      window.setTimeout(function() {
        initKnownForms(document);
        enforceLeadForgeActionLock(document);
        setupBackButtonLeadIntent();
      }, 60);
    }, true);
  });
  window.setInterval(function() {
    enforceLeadForgeActionLock(document);
  }, 500);
})();
