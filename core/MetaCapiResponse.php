<?php
// Shared response handling for queued and manual Meta Conversions API requests.

class MetaCapiResponse
{
    public static function isEventRequest(string $url, string $method): bool
    {
        $parts = parse_url($url);
        return $method === 'POST'
            && strtolower((string) ($parts['host'] ?? '')) === 'graph.facebook.com'
            && preg_match('#^/(?:v\d+\.\d+/)?[^/]+/events/?$#', (string) ($parts['path'] ?? '')) === 1;
    }

    /**
     * A successful HTTP request is not necessarily an accepted event batch.
     * Only a bounded, redacted diagnostic summary is returned for storage/UI use.
     *
     * @return array{success:bool,retryable:bool,message:string,response:array,response_json:string}
     */
    public static function evaluate(int $httpCode, $body, string $transportError = '', int $expectedEvents = 1, array $requestContext = []): array
    {
        $decoded = is_string($body) ? json_decode($body, true) : null;
        $isObject = is_array($decoded) && is_string($body) && substr(ltrim($body), 0, 1) === '{';
        $secrets = self::sensitiveValues($requestContext);
        $summary = [];
        if ($isObject) {
            if (isset($decoded['events_received']) && is_int($decoded['events_received'])) {
                $summary['events_received'] = $decoded['events_received'];
            }
            if (isset($decoded['fbtrace_id']) && is_string($decoded['fbtrace_id'])) {
                $summary['fbtrace_id'] = self::redact($decoded['fbtrace_id'], $secrets, 200);
            }
            if (isset($decoded['messages']) && is_array($decoded['messages'])) {
                $summary['messages'] = [];
                foreach (array_slice($decoded['messages'], 0, 10) as $warning) {
                    if (is_string($warning)) {
                        $summary['messages'][] = self::redact($warning, $secrets);
                    } elseif (is_array($warning)) {
                        $summary['messages'][] = self::diagnosticFields($warning, $secrets);
                    }
                }
            }
            if (array_key_exists('error', $decoded)) {
                $summary['error'] = is_array($decoded['error'])
                    ? self::diagnosticFields($decoded['error'], $secrets)
                    : ['message' => 'Unexpected Meta error response.'];
            }
        }

        $hasError = $isObject && array_key_exists('error', $decoded);
        $received = $summary['events_received'] ?? null;
        $success = $transportError === '' && $httpCode >= 200 && $httpCode < 300
            && $isObject && !$hasError && $expectedEvents > 0 && $received === $expectedEvents;
        $retryable = false;
        if ($success) {
            $message = "Meta accepted the event batch (events_received: $received).";
        } elseif ($transportError !== '') {
            $message = 'Transport error: ' . self::redact($transportError, $secrets);
            $retryable = true;
        } elseif ($expectedEvents <= 0) {
            $message = 'Meta CAPI request contains no events.';
        } else {
            $error = $hasError && is_array($decoded['error']) ? $decoded['error'] : [];
            $errorCode = is_int($error['code'] ?? null) ? $error['code'] : null;
            $retryable = $httpCode === 0 || $httpCode === 408 || $httpCode === 429 || $httpCode >= 500
                || ($error['is_transient'] ?? false) === true
                || in_array($errorCode, [1, 2, 4, 17, 32, 341, 613], true);
            if ($hasError) {
                $message = 'Meta CAPI error' . ($errorCode !== null ? ' ' . $errorCode : '') . " (HTTP $httpCode)";
                if (!empty($summary['error']['message'])) {
                    $message .= ': ' . $summary['error']['message'];
                }
                if (!empty($summary['error']['error_user_msg'])) {
                    $message .= ' — ' . $summary['error']['error_user_msg'];
                }
            } elseif ($httpCode < 200 || $httpCode >= 300) {
                $message = "Meta CAPI HTTP $httpCode";
            } elseif (!$isObject) {
                $message = 'Meta CAPI returned an invalid JSON object.';
                $retryable = true;
            } else {
                $message = 'Meta CAPI did not confirm the full batch (events_received: '
                    . ($received === null ? 'missing or invalid' : $received) . "; expected: $expectedEvents).";
                $retryable = true;
            }
        }
        if (!$success) {
            $summary['delivery_error'] = $message;
        }
        return [
            'success' => $success,
            'retryable' => $retryable,
            'message' => $message,
            'response' => $summary,
            'response_json' => (string) json_encode($summary, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE),
        ];
    }

    private static function diagnosticFields(array $data, array $secrets): array
    {
        $result = [];
        foreach (['code', 'error_subcode'] as $key) {
            if (isset($data[$key]) && is_int($data[$key])) {
                $result[$key] = $data[$key];
            }
        }
        if (isset($data['is_transient']) && is_bool($data['is_transient'])) {
            $result['is_transient'] = $data['is_transient'];
        }
        foreach (['type', 'message', 'error_user_title', 'error_user_msg', 'fbtrace_id'] as $key) {
            if (isset($data[$key]) && is_string($data[$key])) {
                $result[$key] = self::redact($data[$key], $secrets);
            }
        }
        return $result;
    }

    private static function sensitiveValues(array $context): array
    {
        $values = [];
        $collect = static function ($value) use (&$values, &$collect): void {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $collect($item);
                }
            } elseif (is_string($value) && $value !== '') {
                $values[] = $value;
                $values[] = rawurlencode($value);
                $values[] = urlencode($value);
                // Meta errors may echo only the click ID portion of an fbc value.
                if (preg_match('/^fb\.\d+\.\d+\.(.+)$/', $value, $match)) {
                    $values[] = $match[1];
                    $values[] = explode('.', $match[1])[0];
                }
            }
        };
        $payload = $context['payload'] ?? json_decode((string) ($context['payload_json'] ?? ''), true);
        if (is_array($payload)) {
            $collect($payload['access_token'] ?? '');
            $events = is_array($payload['data'] ?? null) ? $payload['data'] : [];
            foreach ($events as $event) {
                if (is_array($event)) {
                    $collect($event['user_data'] ?? []);
                    $collect($event['event_source_url'] ?? '');
                    $collect($event['referrer_url'] ?? '');
                }
            }
        }
        foreach (['url', 'proxy_url'] as $key) {
            $parts = parse_url((string) ($context[$key] ?? ''));
            if (!is_array($parts)) {
                continue;
            }
            $collect(isset($parts['user']) ? rawurldecode($parts['user']) : '');
            $collect(isset($parts['pass']) ? rawurldecode($parts['pass']) : '');
            parse_str($parts['query'] ?? '', $query);
            foreach ($query as $name => $value) {
                if (preg_match('/token|secret|password|authorization|api.?key/i', (string) $name)) {
                    $collect($value);
                }
            }
        }
        $headers = $context['headers'] ?? json_decode((string) ($context['headers_json'] ?? ''), true);
        if (is_array($headers)) {
            foreach ($headers as $name => $value) {
                if (preg_match('/token|secret|authorization|api.?key/i', (string) $name) && is_string($value)) {
                    $collect($value);
                    $collect(preg_replace('/^(?:Bearer|Basic)\s+/i', '', $value));
                }
            }
        }
        $values = array_values(array_unique($values));
        usort($values, static fn($a, $b) => strlen($b) <=> strlen($a));
        return $values;
    }

    private static function redact(string $text, array $secrets, int $limit = 1024): string
    {
        $text = strtr($text, array_fill_keys($secrets, '[redacted]'));
        // Drop URLs and common identifiers even when a provider echoes data not
        // present in this request. Never persist arbitrary response fields/bodies.
        $text = preg_replace('~https?://[^\s<>"\']+~i', '[redacted URL]', $text);
        $text = preg_replace('/\b(access_token|authorization|api[_-]?key|password)\s*[:=]\s*["\']?[^\s,"\']+/i', '$1=[redacted]', $text);
        $text = preg_replace('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', '[redacted email]', $text);
        $text = preg_replace('/\b(?:\d{1,3}\.){3}\d{1,3}\b/', '[redacted IP]', $text);
        $text = preg_replace('/(?<![a-z0-9:])(?:[a-f0-9]{0,4}:){2,}[a-f0-9]{0,4}(?![a-z0-9:])/i', '[redacted IP]', $text);
        $text = preg_replace('/\b\d{7,}\b/', '[redacted identifier]', $text);
        $text = preg_replace('/[\x00-\x1f\x7f]+/', ' ', $text);
        return substr((string) $text, 0, $limit);
    }
}
