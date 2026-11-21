<?php
// core/settings_guard.php
//
// A safety net for the keyed settings load (ТЗ 1.9.8: index.php and
// core/click_api.php read an explicit key list instead of SELECT *). A key
// that the click path reads but the list forgot is a SILENTLY WRONG
// decision — the browser-check outage of 2026-11-21 was exactly this:
// orbitraBcMode() read $settings['browser_check_mode'], the key was absent
// from the narrowed list, `?? null` degraded to 'off', and every browser
// check quietly disabled itself. A load test cannot catch this class of bug
// (nothing errors — a feature just stops working), so the guard makes the
// missing read VISIBLE.
//
// Enabled by ORBITRA_SETTINGS_GUARD=1 (dev/test; default off — zero cost in
// production: index.php only constructs it when the env var is set, and
// then wraps the loaded settings in this ArrayAccess). Semantics match a
// plain array with one addition:
//
//   $settings['missing']        → warning logged (file + line of the
//                                  reader, the key), returns null — the
//                                  bare-read case, always a bug;
//   $settings['missing'] ?? $d  → NO warning: offsetExists answers false
//                                  and the default applies — the LEGITIMATE
//                                  optional-key pattern. But note: `??` on
//                                  a key that HAS a database value the
//                                  narrowed list forgot is still silently
//                                  wrong; the parity test covers that by
//                                  replaying the real flows with the guard
//                                  on and asserting an empty log.
//
// Warnings go to error_log() and to var/logs/settings_guard.log (append).

/**
 * @implements \ArrayAccess<string, ?string>
 */
class OrbitraSettingsGuard implements \ArrayAccess, \Countable, \IteratorAggregate
{
    /** @var array<string, string> */
    private array $data;

    /** @var array<string, int> key => times read while absent */
    private array $missingReads = [];

    /** @param array<string, string> $data */
    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[(string) $offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        $key = (string) $offset;
        if (!\array_key_exists($key, $this->data)) {
            $this->missingReads[$key] = ($this->missingReads[$key] ?? 0) + 1;
            $where = '';
            foreach (debug_backtrace(\DEBUG_BACKTRACE_IGNORE_ARGS, 4) as $frame) {
                if (!empty($frame['file']) && strpos((string) $frame['file'], 'settings_guard.php') === false) {
                    $where = basename((string) $frame['file']) . ':' . ($frame['line'] ?? 0);
                    break;
                }
            }
            $line = sprintf(
                'Orbitra settings guard: key "%s" read from the NARROWED settings map but not loaded — add it to the key list (read at %s)',
                $key,
                $where
            );
            error_log($line);
            $logFile = __DIR__ . '/../var/logs/settings_guard.log';
            @mkdir(\dirname($logFile), 0770, true);
            @file_put_contents($logFile, date('Y-m-d H:i:s ') . $line . "\n", \FILE_APPEND);
            return null;
        }
        return $this->data[$key];
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        // Settings are read-only on the click path.
    }

    public function offsetUnset(mixed $offset): void
    {
        // Settings are read-only on the click path.
    }

    public function count(): int
    {
        return \count($this->data);
    }

    /** @return \ArrayIterator<string, string> */
    public function getIterator(): \Traversable
    {
        return new \ArrayIterator($this->data);
    }

    /** @return array<string, int> every missing-key read so far, for tests */
    public function orbitraMissingReads(): array
    {
        return $this->missingReads;
    }
}
