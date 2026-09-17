<?php
// Loaded by the worker subprocess after PHP disables the native transport calls.
// No DNS or HTTP request can leave this fixture; all data is synthetic.

if (function_exists('curl_exec') || function_exists('gethostbyname')) {
    throw new RuntimeException('Native DNS and cURL functions must be disabled for this fixture.');
} else {

function gethostbyname(string $host): string
{
    if ($host === 'dns-failure.example') {
        return $host;
    }
    return $host === '127.0.0.1' ? $host : '8.8.8.8';
}

function curl_init(?string $url = null)
{
    return (object) ['url' => $url, 'options' => [], 'fixture' => []];
}

function curl_setopt($handle, int $option, $value): bool
{
    $handle->options[$option] = $value;
    return true;
}

function curl_exec($handle)
{
    parse_str((string) parse_url($handle->url, PHP_URL_QUERY), $query);
    $fixtures = json_decode(file_get_contents(__DIR__ . '/transport_cases.json'), true);
    $name = (string) ($GLOBALS['metaTransportCase'] ?? $query['fixture'] ?? '');
    if (!isset($fixtures[$name])) {
        throw new RuntimeException('Unexpected transport request: ' . $name);
    }
    $handle->fixture = $fixtures[$name];
    file_put_contents(__DIR__ . '/transport_calls.jsonl', json_encode([
        'fixture' => $name,
        'body' => $handle->options[CURLOPT_POSTFIELDS] ?? null,
        'headers' => $handle->options[CURLOPT_HTTPHEADER] ?? [],
        'post' => $handle->options[CURLOPT_POST] ?? false,
    ]) . "\n", FILE_APPEND);
    return $handle->fixture['body'];
}

function curl_getinfo($handle, int $option = 0)
{
    return $handle->fixture['http'];
}

function curl_error($handle): string
{
    return $handle->fixture['error'] ?? '';
}

function curl_close($handle): void
{
}

}
