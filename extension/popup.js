const DEFAULT_SETTINGS = {
  trackerUrl: '',
  apiKey: '',
  template: 'cpa',
  pollingInterval: 30
};

const fields = {
  trackerUrl: document.getElementById('trackerUrl'),
  apiKey: document.getElementById('apiKey'),
  template: document.getElementById('template'),
  pollingInterval: document.getElementById('pollingInterval')
};
const statusNode = document.getElementById('status');
const saveButton = document.getElementById('save');
const testButton = document.getElementById('test');

function showStatus(message, type = '') {
  statusNode.textContent = message;
  statusNode.className = type;
}

function normalizeTrackerUrl(value) {
  let input = String(value || '').trim();
  if (!input) return '';

  if (!/^https?:\/\//i.test(input)) {
    input = `http://${input}`;
  }

  const url = new URL(input);
  if (!['http:', 'https:'].includes(url.protocol)) throw new Error('Use an http:// or https:// tracker URL.');
  url.hash = '';
  url.search = '';

  let pathname = url.pathname.replace(/\/+$/, '');
  pathname = pathname.replace(/\/admin\.php(?:\/api\.php)?$/i, '');
  if (!/\/api\.php$/i.test(pathname)) {
    pathname = `${pathname}/api.php`;
  } else {
    pathname = pathname.replace(/\/api\.php$/i, '/api.php');
  }
  url.pathname = pathname;

  return url.toString().replace(/\/$/, '');
}

function permissionOrigin(trackerUrl) {
  const url = new URL(trackerUrl);
  return `${url.origin}/*`;
}

async function load() {
  const settings = await chrome.storage.sync.get(DEFAULT_SETTINGS);
  fields.trackerUrl.value = settings.trackerUrl;
  fields.apiKey.value = settings.apiKey;
  fields.template.value = settings.template;
  fields.pollingInterval.value = String(settings.pollingInterval);
}

async function save() {
  const trackerUrl = normalizeTrackerUrl(fields.trackerUrl.value);
  if (!trackerUrl) throw new Error('Paste the Orbitra tracker URL.');
  fields.trackerUrl.value = trackerUrl;

  const apiKey = fields.apiKey.value.trim();
  if (!apiKey) throw new Error('Paste a read API key from Orbitra.');

  const granted = await chrome.permissions.request({ origins: [permissionOrigin(trackerUrl)] });
  if (!granted) throw new Error('Tracker host permission was not granted.');

  await chrome.storage.sync.set({
    trackerUrl,
    apiKey,
    template: fields.template.value === 'cod' ? 'cod' : 'cpa',
    pollingInterval: Number(fields.pollingInterval.value) || 30
  });
  fields.trackerUrl.value = trackerUrl;
}

saveButton.addEventListener('click', async () => {
  saveButton.disabled = true;
  showStatus('Saving…');
  try {
    await save();
    showStatus('Settings saved. Open or refresh Facebook Ads Manager.', 'ok');
  } catch (error) {
    showStatus(error.message || String(error), 'error');
  } finally {
    saveButton.disabled = false;
  }
});

testButton.addEventListener('click', async () => {
  testButton.disabled = true;
  showStatus('Connecting…');
  try {
    await save();
    const response = await chrome.runtime.sendMessage({ type: 'ORBITRA_TEST_CONNECTION' });
    if (!response?.ok) throw new Error(response?.error || 'Connection failed.');
    showStatus('Connected. Orbitra accepted the read API key.', 'ok');
  } catch (error) {
    showStatus(error.message || String(error), 'error');
  } finally {
    testButton.disabled = false;
  }
});

document.getElementById('toggleKey').addEventListener('click', event => {
  const reveal = fields.apiKey.type === 'password';
  fields.apiKey.type = reveal ? 'text' : 'password';
  event.currentTarget.textContent = reveal ? 'Hide' : 'Show';
  event.currentTarget.setAttribute('aria-label', reveal ? 'Hide API key' : 'Show API key');
});

load().catch(error => showStatus(error.message || String(error), 'error'));
