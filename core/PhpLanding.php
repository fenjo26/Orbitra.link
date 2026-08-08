<?php
/**
 * Support for landings written in PHP.
 *
 * A landing uploaded as PHP runs inside the tracker's own process, in the web
 * root. There is no way to make that genuinely safe from inside PHP itself, so be
 * clear about what this is: the scan below raises the cost of an accident and
 * blocks the obvious dangerous calls, but it is NOT a sandbox. Source-level
 * checks can be worked around by anyone who is trying — `$f = 'sys' . 'tem'; $f();`
 * defeats every one of them.
 *
 * What actually contains the risk:
 *   - the feature is off unless an admin turns it on;
 *   - only signed-in users can upload a landing at all;
 *   - the real boundary is php.ini's disable_functions and open_basedir, which
 *     the deployment controls, not this file.
 *
 * Keitaro documents the same list of forbidden calls with the same caveat.
 */
class PhpLanding
{
    /** Calls that hand a landing the shell, the filesystem beyond itself, or the parser. */
    public const FORBIDDEN_FUNCTIONS = [
        // Shell and process control — the whole game, if you get one of these.
        'exec', 'system', 'shell_exec', 'passthru', 'proc_open', 'popen',
        'pcntl_exec', 'pcntl_fork', 'dl',
        // Turning data into code.
        'assert', 'create_function',
        // Escaping the landing folder that the asset server is careful to contain.
        'symlink', 'link',
        // Undoing the execution budget, which is what keeps one landing from
        // taking the site down with it.
        'set_time_limit', 'ini_set', 'ini_alter',
    ];

    /** Language constructs, which are tokens rather than function calls. */
    public const FORBIDDEN_CONSTRUCTS = [
        T_EVAL => 'eval',
    ];

    /**
     * Look for forbidden calls in PHP source.
     *
     * Uses the tokenizer rather than a regular expression: a regex matches
     * "system" inside a comment or a string and misses `System(`, while tokens
     * see actual calls. Still defeated by dynamic names — see the class note.
     *
     * @return string[] names found, empty when the file looks acceptable
     */
    public static function scan(string $source): array
    {
        $found = [];
        try {
            $tokens = token_get_all($source, TOKEN_PARSE);
        } catch (\ParseError $e) {
            return ['(the file does not parse: ' . $e->getMessage() . ')'];
        }

        $forbidden = array_flip(self::FORBIDDEN_FUNCTIONS);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (!is_array($token)) {
                continue;
            }
            if (isset(self::FORBIDDEN_CONSTRUCTS[$token[0]])) {
                $found[self::FORBIDDEN_CONSTRUCTS[$token[0]]] = true;
                continue;
            }
            if ($token[0] !== T_STRING) {
                continue;
            }
            $name = strtolower($token[1]);
            if (!isset($forbidden[$name])) {
                continue;
            }
            // Only count it when it is actually being called: "->system",
            // "Foo::system" and "function system" are somebody's own code. Walk
            // back past whitespace and comments — the token immediately before is
            // almost always a space, so checking only that one misses every case.
            $prevMeaningful = null;
            for ($k = $i - 1; $k >= 0; $k--) {
                if (is_array($tokens[$k]) && in_array($tokens[$k][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $prevMeaningful = $tokens[$k];
                break;
            }
            if (is_array($prevMeaningful)
                && in_array($prevMeaningful[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION, T_NEW], true)) {
                continue;
            }
            for ($j = $i + 1; $j < $count; $j++) {
                if (is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
                    continue;
                }
                if ($tokens[$j] === '(') {
                    $found[$name] = true;
                }
                break;
            }
        }

        return array_keys($found);
    }

    /** Scan every .php file in a directory. @return array<string,string[]> path => names */
    public static function scanDirectory(string $dir): array
    {
        $problems = [];
        if (!is_dir($dir)) {
            return $problems;
        }
        // Report paths relative to the landing: the message reaches the panel, and
        // the server's absolute layout is not the operator's business.
        $base = rtrim(realpath($dir) ?: $dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $hits = self::scan((string) file_get_contents($file->getPathname()));
            if ($hits) {
                $path = $file->getPathname();
                $problems[strpos($path, $base) === 0 ? substr($path, strlen($base)) : basename($path)] = $hits;
            }
        }
        return $problems;
    }

    /** Is the feature switched on for this instance? */
    public static function enabled(PDO $pdo): bool
    {
        try {
            $value = $pdo->query("SELECT value FROM settings WHERE key = 'allow_php_landings' LIMIT 1")->fetchColumn();
            return $value === '1';
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Execution budget in seconds, clamped the way Keitaro clamps it. */
    public static function timeout(PDO $pdo): int
    {
        try {
            $value = (int) $pdo->query("SELECT value FROM settings WHERE key = 'php_landing_timeout' LIMIT 1")->fetchColumn();
        } catch (\Throwable $e) {
            $value = 3;
        }
        return max(1, min($value ?: 3, 9));
    }
}

/**
 * The click, as a landing sees it: $rawClick->get('sub_id_1').
 *
 * Read-only by design — a landing reporting a conversion goes through the
 * postback endpoint, not by writing to the click row underneath it.
 */
class OrbitraRawClick
{
    private array $data;

    public function __construct(array $data)
    {
        $this->data = $data;
    }

    public function get(string $key, $default = null)
    {
        return $this->data[$key] ?? $default;
    }

    public function all(): array
    {
        return $this->data;
    }
}
