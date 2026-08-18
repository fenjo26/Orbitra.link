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
 *   - the feature is on by default (since v1.0.4) and an admin can turn it off;
 *   - only signed-in users can upload a landing at all;
 *   - the real boundary is php.ini's disable_functions and open_basedir, which
 *     the deployment controls, not this file.
 *
 * Keitaro documents the same class of forbidden calls with the same caveat. The
 * difference: where Keitaro rejects an archive over any listed call, calls that
 * merely lift the tracker's runtime limits (see SANITIZED_FUNCTIONS) are stripped
 * at upload time instead — real-world order forms use them as boilerplate, and
 * the stripped file keeps the guarantee the rejection was protecting.
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
    ];

    /**
     * Calls that only undo the tracker's own runtime limits. Unlike the list
     * above these are a staple of real-world order forms — third-party
     * templates open with ini_set('display_errors', ...) and set_time_limit()
     * almost universally — so an archive is not rejected over them: the calls
     * are stripped from the code at upload time (see sanitize()) and reported
     * back to the operator. The execution budget the runner installs before
     * including the page survives; the template itself keeps working.
     */
    public const SANITIZED_FUNCTIONS = [
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
            // T_NAME_FULLY_QUALIFIED is PHP 8's token for "\func(...)" — the
            // explicitly global call, which is exactly the one a source-level
            // check must not miss. Qualified "Foo\func" and "namespace\func"
            // stay out: those names belong to the page's own namespace.
            if ($token[0] !== T_STRING && $token[0] !== T_NAME_FULLY_QUALIFIED) {
                continue;
            }
            $name = strtolower(ltrim($token[1], '\\'));
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

    /**
     * Strip the SANITIZED_FUNCTIONS calls out of PHP source.
     *
     * The exact call expression — from the function name (plus a leading
     * backslash when fully qualified) through its matching closing paren — is
     * replaced with `null`, which is syntactically valid everywhere a call is:
     * as a statement (`null;`), in an assignment, or inside another call. The
     * tokenizer sees real calls only, so mentions inside strings and comments
     * survive untouched, and the paren walk is immune to parentheses inside
     * string literals because a literal is a single token.
     *
     * @return array{source: string, names: string[]}|null the rewritten source
     *   plus the names that were stripped; null when there was nothing to do
     */
    public static function sanitize(string $source): ?array
    {
        if (strpos($source, '<?') === false) {
            return null;
        }
        try {
            $tokens = PhpToken::tokenize($source, TOKEN_PARSE);
        } catch (\ParseError $e) {
            // scanDirectory() already refuses files that do not parse; if one
            // still arrives here, leave it untouched rather than half-rewrite.
            return null;
        }

        $soft = array_flip(self::SANITIZED_FUNCTIONS);
        $spans = [];  // [start, end) byte ranges to replace
        $found = [];
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $tok = $tokens[$i];
            // T_NAME_FULLY_QUALIFIED carries the leading backslash inside the
            // token text, so a "\set_time_limit(...)" span already includes it.
            if ($tok->id !== T_STRING && $tok->id !== T_NAME_FULLY_QUALIFIED) {
                continue;
            }
            $name = strtolower(ltrim($tok->text, '\\'));
            if (!isset($soft[$name])) {
                continue;
            }
            // Only an actual call to the global function: skip "->ini_set",
            // "Foo::ini_set", "function ini_set" and the qualified
            // "Foo\ini_set" — that is somebody's own code, not the setting.
            $prev = self::meaningfulBefore($tokens, $i);
            if ($prev !== null && in_array($prev->id, [
                T_OBJECT_OPERATOR, T_NULLSAFE_OBJECT_OPERATOR, T_DOUBLE_COLON,
                T_FUNCTION, T_FN, T_NEW, T_STRING,
            ], true)) {
                continue;
            }
            $startTok = $tok;

            $j = $i + 1;
            while ($j < $count && $tokens[$j]->id === T_WHITESPACE) {
                $j++;
            }
            if ($j >= $count || $tokens[$j]->text !== '(') {
                continue;
            }
            $depth = 0;
            for ($k = $j; $k < $count; $k++) {
                $text = $tokens[$k]->text;
                if ($text === '(') {
                    $depth++;
                } elseif ($text === ')') {
                    $depth--;
                    if ($depth === 0) {
                        $spans[] = [$startTok->pos, $tokens[$k]->pos + strlen($text)];
                        $found[$name] = true;
                        $i = $k; // resume the outer walk after this call
                        break;
                    }
                }
            }
        }

        if (!$spans) {
            return null;
        }
        // Apply from the end so earlier byte offsets stay valid.
        usort($spans, function ($a, $b) { return $b[0] - $a[0]; });
        foreach ($spans as [$start, $end]) {
            $source = substr($source, 0, $start) . 'null' . substr($source, $end);
        }
        return ['source' => $source, 'names' => array_keys($found)];
    }

    /** The token before $i that is neither whitespace nor a comment. */
    private static function meaningfulBefore(array $tokens, int $i): ?\PhpToken
    {
        for ($k = $i - 1; $k >= 0; $k--) {
            if ($tokens[$k]->id === T_WHITESPACE || $tokens[$k]->id === T_COMMENT || $tokens[$k]->id === T_DOC_COMMENT) {
                continue;
            }
            return $tokens[$k];
        }
        return null;
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
        $base = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;
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

    /**
     * Strip the soft-tier calls from every .php file in a directory, rewriting
     * the files in place. @return array<string,string[]> path => stripped names,
     * empty when nothing needed rewriting. Paths are relative to $dir so the
     * report can go straight into the upload response.
     */
    public static function sanitizeDirectory(string $dir): array
    {
        $stripped = [];
        if (!is_dir($dir)) {
            return $stripped;
        }
        // Literal $dir, not realpath(): the iterator builds pathnames from the
        // path it was given, and on boxes where that path goes through a
        // symlink (macOS /var -> /private/var) a realpath'ed prefix never
        // matches, silently degrading every report to a bare basename.
        $base = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR;
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($iterator as $file) {
            if (!$file->isFile() || strtolower($file->getExtension()) !== 'php') {
                continue;
            }
            $result = self::sanitize((string) file_get_contents($file->getPathname()));
            if ($result === null) {
                continue;
            }
            file_put_contents($file->getPathname(), $result['source']);
            $path = $file->getPathname();
            $stripped[strpos($path, $base) === 0 ? substr($path, strlen($base)) : basename($path)] = $result['names'];
        }
        return $stripped;
    }

    /** Is the feature switched on for this instance? */
    public static function enabled(PDO $pdo): bool
    {
        try {
            $value = $pdo->query("SELECT value FROM settings WHERE key = 'allow_php_landings' LIMIT 1")->fetchColumn();
            // On by default since v1.0.4 (LeadForge bundles are unusable without
            // it), so a missing row means enabled — only an explicit '0' is off.
            return $value !== '0';
        } catch (\Throwable $e) {
            return true;
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
