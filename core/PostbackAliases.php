<?php
/**
 * Postback parameter aliases.
 *
 * postback.php reads four parameters by name — subid, status, payout, tid —
 * and networks name them differently (clickid, revenue, ...). The built-in
 * aliases below are always accepted. The `postback_aliases` setting (a JSON
 * object, alias => parameter) holds the operator's own additions and is
 * edited on the Postback page.
 *
 * Resolution order for one parameter: its own name, then the built-in
 * aliases, then the configured ones. A configured alias is the last resort,
 * so adding one never changes how a postback that already resolved reads.
 */
final class PostbackAliases
{
    /** The parameters a postback carries, by the tracker's own names. */
    public const CANONICAL = ['subid', 'status', 'payout', 'tid'];

    /** alias => parameter: exactly what postback.php accepted before the setting was read. */
    public const BUILTIN = [
        'clickid' => 'subid',
        'click_id' => 'subid',
        'sub_id' => 'subid',
        'type' => 'status',
        'revenue' => 'payout',
        'profit' => 'payout',
    ];

    /**
     * The row config.php seeds on a fresh install. It was never read, so its
     * one entry beyond the built-ins (transaction_id => tid) never switched
     * tid on for anybody — and tid changes what a postback means (one
     * conversion per transaction id). An untouched default therefore stays
     * inert; the operator opts in by adding the alias on the Postback page.
     */
    private const UNTOUCHED_DEFAULT = [
        'clickid' => 'subid',
        'transaction_id' => 'tid',
        'revenue' => 'payout',
        'profit' => 'payout',
        'type' => 'status',
    ];

    /** The configured aliases (alias => parameter), normalised. A missing or unreadable row is none. */
    public static function load(PDO $pdo): array
    {
        try {
            $raw = $pdo->query("SELECT value FROM settings WHERE key = 'postback_aliases' LIMIT 1")->fetchColumn();
        } catch (\Throwable $e) {
            return [];
        }
        return self::normalize($raw);
    }

    /**
     * Validate and clean up a stored or posted alias map.
     *
     * Entries that only repeat a built-in alias are dropped (they work either
     * way). Entries that cannot work go to $rejected (alias => reason) and are
     * left out of the result:
     *   map      — the value is not a JSON object at all
     *   target   — not one of the four parameters
     *   name     — not 1..64 Latin letters, digits or underscores
     *   self     — the alias is the parameter's own name
     *   builtin  — a built-in alias of ANOTHER parameter; it would shadow it
     */
    public static function normalize($raw, array &$rejected = []): array
    {
        $rejected = [];
        $map = is_string($raw) ? json_decode($raw, true) : $raw;
        if ($map === null || $map === '' || $map === false) {
            return [];
        }
        if (!is_array($map)) {
            $rejected[''] = 'map';
            return [];
        }
        if ($map == self::UNTOUCHED_DEFAULT) {
            return [];
        }

        $clean = [];
        foreach ($map as $alias => $target) {
            $alias = (string) $alias;
            if (!is_string($target) || !in_array($target, self::CANONICAL, true)) {
                $rejected[$alias] = 'target';
                continue;
            }
            if (preg_match('/^[A-Za-z0-9_]{1,64}$/', $alias) !== 1) {
                $rejected[$alias] = 'name';
                continue;
            }
            if ($alias === $target || in_array($alias, self::CANONICAL, true)) {
                $rejected[$alias] = 'self';
                continue;
            }
            $builtinTarget = self::BUILTIN[$alias] ?? null;
            if ($builtinTarget !== null) {
                if ($builtinTarget !== $target) {
                    $rejected[$alias] = 'builtin';
                }
                continue;
            }
            $clean[$alias] = $target;
        }
        return $clean;
    }

    /** The value a parameter arrives under, or null when neither its name nor any alias is present. */
    public static function param(array $params, array $aliases, string $param)
    {
        foreach (self::names($param, $aliases) as $name) {
            if (isset($params[$name])) {
                return $params[$name];
            }
        }
        return null;
    }

    /** The names a parameter may arrive under, in resolution order. */
    private static function names(string $param, array $aliases): array
    {
        $names = [$param];
        foreach (self::BUILTIN as $alias => $target) {
            if ($target === $param) {
                $names[] = $alias;
            }
        }
        foreach ($aliases as $alias => $target) {
            if ($target === $param) {
                $names[] = (string) $alias;
            }
        }
        return $names;
    }

    /** The built-in aliases grouped by parameter, the shape the Postback page renders. */
    public static function builtinByParam(): array
    {
        $byParam = [];
        foreach (self::CANONICAL as $param) {
            $byParam[$param] = [];
        }
        foreach (self::BUILTIN as $alias => $target) {
            $byParam[$target][] = $alias;
        }
        return $byParam;
    }
}
