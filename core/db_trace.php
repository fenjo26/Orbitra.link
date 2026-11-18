<?php
// core/db_trace.php
//
// ORBITRA_DB_TRACE — off-by-default diagnostics for the load investigation
// (ТЗ 1.9.7: find the long database reads that keep the WAL from restarting).
// config.php swaps the plain PDO for OrbitraTracePDO ONLY when the env var is
// set; with it unset this file is never loaded and the request path pays one
// getenv() — nothing else. Never enable it in production: it writes a JSONL
// line per statement and exists to answer, with numbers, "who holds a read,
// for how long, from which query".
//
// What it measures. In WAL mode an open SELECT statement is an open read
// snapshot: pdo_sqlite acquires it on the first step and releases it on
// sqlite3_reset — which PDO runs when the cursor is exhausted (the last
// fetch), on closeCursor(), or when the statement object is destroyed. A
// `fetch()`/`fetchColumn()` that FOUND its row (LIMIT 1 hits do) leaves the
// statement open until one of those three happens, so the interesting number
// is not the query time but the HOLD time: first execute → close. Under load
// that is exactly what a checkpoint RESTART waits on.
//
// Output: one JSON object per line, appended to a per-process file under the
// trace directory (default var/diag/dbtrace/), name {sapi}-{pid}.jsonl.
//   q   — a statement span, logged when the statement closes:
//         {ev:"q", rid, sql, held_us, exec_us, by, leak}
//         by = fetch_done | closeCursor | destruct; leak = destruct with
//         held_us over 250 ms (the statement outlived its usage — the shape
//         that pins a reader far past the code that ran the query).
//   txn — BEGIN→COMMIT/ROLLBACK span; while it is open, every statement
//         inside shares its snapshot, so the transaction is the holder:
//         {ev:"txn", rid, sql:"txn", held_us, end:"commit"|"rollback"}
//   x   — a slow PDO::exec()/query() wall call (threshold applies): for
//         PRAGMAs and DDL that never produce a statement span.
//
// Knobs (env):
//   ORBITRA_DB_TRACE=1             mode 1: production-shaped tracing — the
//                                  handle keeps PDO::ATTR_PERSISTENT exactly
//                                  as config.php set it, so connection-level
//                                  events (query/prepare/exec wall time,
//                                  BEGIN→COMMIT spans) are logged, but NOT
//                                  statement hold spans: PHP forbids
//                                  ATTR_STATEMENT_CLASS on persistent handles
//                                  ("PDO::ATTR_STATEMENT_CLASS cannot be used
//                                  with persistent PDO instances").
//   ORBITRA_DB_TRACE=2             mode 2 (attribution runs): the handle is
//                                  forced NON-persistent so statements can be
//                                  decorated and every hold span is logged.
//                                  A request's statement lifetimes do not
//                                  depend on handle persistence (statements
//                                  die at request end either way), so the
//                                  per-query hold distribution measured here
//                                  is valid for the production shape; only
//                                  connection setup/teardown differs.
//   ORBITRA_DB_TRACE=/path/dir     mode 1 with files under that directory
//   ORBITRA_DB_TRACE_MIN_MS=1      only log q/x records held ≥ this (txn
//                                  always logged); 0 logs everything
//
// CLI processes are non-persistent by construction, so statements are always
// decorated there regardless of the mode.

/**
 * Statement decorator produced by OrbitraTracePDO via ATTR_STATEMENT_CLASS.
 * Tracks the hold span (first execute → close) of one prepared statement.
 */
class OrbitraTraceStmt extends PDOStatement
{
    /** PDO demands a declared constructor on a user statement class — without
     * one, the first query()/prepare() fatals with "User-supplied statement
     * does not accept constructor arguments" (the php.net pattern). */
    protected function __construct()
    {
    }

    /** Normalized SQL tag, filled by the connection on prepare/query. */
    public string $traceSql = '';

    /** Caller file:line, filled by the connection on prepare/query. */
    public string $traceSrc = '';

    /** @var float|null hrtime at first execute (µs precision), null = never executed */
    private ?float $traceOpenedAt = null;

    /** @var float|null how long the last execute() call itself took */
    private ?float $traceExecUs = null;

    private bool $traceClosed = false;
    private ?float $traceTxnStartedAt = null;

    private function orbitraTraceTag(string $sql): string
    {
        $tag = trim((string) preg_replace('/\s+/', ' ', $sql));
        return strlen($tag) > 160 ? substr($tag, 0, 157) . '...' : $tag;
    }

    private function orbitraTraceClose(string $by): void
    {
        if ($this->traceClosed || $this->traceOpenedAt === null) {
            return;
        }
        $this->traceClosed = true;
        $heldUs = (microtime(true) - $this->traceOpenedAt) * 1000000.0;
        OrbitraTraceLog::write([
            'ev' => 'q',
            'rid' => OrbitraTraceLog::rid(),
            'sql' => $this->traceSql,
            'src' => $this->traceSrc,
            'held_us' => (int) round($heldUs),
            'exec_us' => $this->traceExecUs === null ? null : (int) round($this->traceExecUs),
            'by' => $by,
            'leak' => ($by === 'destruct' && $heldUs > 250000.0) ? 1 : 0,
        ], 'q');
    }

    /** Called by OrbitraTracePDO::query(): PDO already executed the statement
     * on its internal path, so open the span here instead of a second run. */
    public function orbitraTraceMarkExecuted(float $t0, float $execUs): void
    {
        if ($this->traceClosed || $this->traceOpenedAt !== null) {
            $this->orbitraTraceClose('reexecute');
            $this->traceClosed = false;
        }
        $this->traceOpenedAt = $t0;
        $this->traceExecUs = $execUs;
    }

    public function execute(?array $params = null): bool
    {
        // Re-executing a closed statement starts a NEW hold span.
        if ($this->traceClosed || $this->traceOpenedAt !== null) {
            $this->orbitraTraceClose('reexecute');
            $this->traceClosed = false;
        }
        $t0 = microtime(true);
        $ok = parent::execute($params);
        $this->traceExecUs = (microtime(true) - $t0) * 1000000.0;
        $this->traceOpenedAt = $t0;
        // A statement that finishes with SQLITE_DONE inside execute — every
        // DML/DDL/PRAGMA in autocommit mode — is already reset when execute()
        // returns: sqlite3_reset ran on the DONE path and all its locks are
        // gone. Its "hold" is exactly its exec time; letting the span run to
        // destruct would overcount it.
        if (preg_match('/^\s*(INSERT|UPDATE|DELETE|REPLACE|CREATE|DROP|ALTER|BEGIN|COMMIT|ROLLBACK|PRAGMA|VACUUM|ANALYZE|ATTACH|DETACH|SET|EXPLAIN\s+QUERY\s+PLAN)/i', $this->traceSql) === 1) {
            $this->orbitraTraceClose('exec_done');
        }
        return $ok;
    }

    public function fetch(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): mixed
    {
        $r = parent::fetch($mode, ...$args);
        if ($r === false) {
            // Cursor hit its end: pdo_sqlite already reset the statement and
            // released the read snapshot — the hold ends HERE, not at destruct.
            $this->orbitraTraceClose('fetch_done');
        }
        return $r;
    }

    public function fetchAll(int $mode = PDO::FETCH_DEFAULT, mixed ...$args): array
    {
        $t0 = microtime(true);
        // Same argument-count discipline as query(): an explicit FETCH_DEFAULT
        // must not be forwarded as a mode change, and extra args belong to the
        // fetch-mode constructors, not to this statement.
        $r = $args === []
            ? ($mode === PDO::FETCH_DEFAULT ? parent::fetchAll() : parent::fetchAll($mode))
            : parent::fetchAll($mode, ...$args);
        // fetchAll drains the cursor to its end by construction.
        if ($this->traceOpenedAt === null) {
            $this->traceOpenedAt = $t0;
            $this->traceExecUs = 0.0;
        }
        $this->orbitraTraceClose('fetch_done');
        return $r;
    }

    public function fetchColumn(int $column = 0): mixed
    {
        $r = parent::fetchColumn($column);
        if ($r === false) {
            $this->orbitraTraceClose('fetch_done');
        }
        return $r;
    }

    public function closeCursor(): bool
    {
        $this->orbitraTraceClose('closeCursor');
        return parent::closeCursor();
    }

    public function __destruct()
    {
        $this->orbitraTraceClose('destruct');
        // PHP runs the PDOStatement destructor on its own afterwards.
    }
}

/**
 * Static sink shared by the connection and its statements: one append-only
 * file per process (single writer, no locking), one fwrite per record.
 */
class OrbitraTraceLog
{
    private static ?string $dir = null;
    /** @var resource|null */
    private static $fh = null;
    private static ?string $rid = null;
    private static ?string $ridKey = null;
    private static float $minUs = 1000.0;
    private static bool $configured = false;
    private static bool $fullMode = false;

    public static function configure(): void
    {
        if (self::$configured) {
            return;
        }
        self::$configured = true;
        $target = getenv('ORBITRA_DB_TRACE');
        self::$fullMode = $target === '2';
        $dirEnv = getenv('ORBITRA_DB_TRACE_DIR');
        $dir = is_string($dirEnv) && $dirEnv !== ''
            ? $dirEnv
            : (($target === '1' || $target === '2' || $target === '' || $target === false)
                ? __DIR__ . '/../var/diag/dbtrace'
                : (string) $target);
        if (!is_dir($dir)) {
            @mkdir($dir, 0770, true);
        }
        self::$dir = $dir;
        $minMs = getenv('ORBITRA_DB_TRACE_MIN_MS');
        self::$minUs = is_numeric($minMs) ? max(0.0, (float) $minMs) * 1000.0 : 1000.0;
    }

    /** Mode 2 (ORBITRA_DB_TRACE=2): force the handle non-persistent so the
     * statement class is legal, and decorate statements. */
    public static function fullMode(): bool
    {
        self::configure();
        return self::$fullMode;
    }

    public static function rid(): string
    {
        // Per-request id for web SAPIs. REQUEST_TIME_FLOAT changes on every
        // request a worker serves, including on persistent connections — the
        // static cache must key on the value itself, or every request after
        // the first would inherit the first request's id (a 10 ms bucket was
        // also merging distinct requests; full precision keeps them apart).
        $base = $_SERVER['REQUEST_TIME_FLOAT'] ?? null;
        if ($base === null) {
            return self::$rid ??= ('p' . getmypid() . '-' . substr((string) ($_SERVER['REQUEST_TIME'] ?? time()), 0, 12));
        }
        $key = sprintf('%.4F', (float) $base);
        if (self::$rid === null || self::$ridKey !== $key) {
            self::$ridKey = $key;
            self::$rid = 'r' . getmypid() . '-' . $key;
        }
        return self::$rid;
    }

    /** @param array<string,mixed> $rec */
    public static function write(array $rec, ?string $thresholdKind = null): void
    {
        self::configure();
        if ($thresholdKind !== null && self::$minUs > 0.0 && ($rec['held_us'] ?? 0) < self::$minUs) {
            return;
        }
        if (self::$fh === null && self::$dir !== null) {
            $path = self::$dir . '/' . PHP_SAPI . '-' . getmypid() . '.jsonl';
            self::$fh = @fopen($path, 'ab');
        }
        if (self::$fh) {
            $rec['t'] = round(microtime(true), 4);
            @fwrite(self::$fh, json_encode($rec, JSON_UNESCAPED_SLASHES) . "\n");
        }
    }
}

/**
 * PDO decorator wired in by config.php when ORBITRA_DB_TRACE is set. Times
 * exec()/query() wall calls and tracks BEGIN→COMMIT spans; every statement it
 * produces is an OrbitraTraceStmt that measures the read-hold span.
 */
class OrbitraTracePDO extends PDO
{
    private bool $traceInTxn = false;
    private ?float $traceTxnStartedAt = null;

    public function __construct(string $dsn, ?string $username = null, ?string $password = null, array $options = [])
    {
        OrbitraTraceLog::configure();
        $persistent = !empty($options[PDO::ATTR_PERSISTENT]);
        // PHP rejects ATTR_STATEMENT_CLASS on persistent handles, so full
        // statement decoration only happens when the handle is (or is forced)
        // non-persistent: CLI by construction, web in mode 2.
        if ($persistent && OrbitraTraceLog::fullMode()) {
            unset($options[PDO::ATTR_PERSISTENT]);
            $persistent = false;
        }
        parent::__construct($dsn, $username, $password, $options);
        if (!$persistent) {
            // Statements created by this handle become decorated automatically.
            $this->setAttribute(PDO::ATTR_STATEMENT_CLASS, ['OrbitraTraceStmt', []]);
        }
    }

    private function orbitraTraceTag(string $sql): string
    {
        $tag = trim((string) preg_replace('/\s+/', ' ', $sql));
        return strlen($tag) > 160 ? substr($tag, 0, 157) . '...' : $tag;
    }

    /** Caller file:line outside core/db_trace.php — per-query attribution. */
    private function orbitraTraceSrc(): string
    {
        foreach (debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 6) as $frame) {
            $file = $frame['file'] ?? '';
            if ($file !== '' && strpos($file, 'core/db_trace.php') === false) {
                return basename(dirname($file)) . '/' . basename($file) . ':' . ($frame['line'] ?? 0);
            }
        }
        return '';
    }

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $t0 = microtime(true);
        $src = $this->orbitraTraceSrc();
        $stmt = parent::prepare($query, $options);
        if ($stmt instanceof OrbitraTraceStmt) {
            $stmt->traceSql = $this->orbitraTraceTag($query);
            $stmt->traceSrc = $src;
        } else {
            // Persistent handle (mode 1): statements cannot be decorated, so
            // only the prepare call itself is visible. Execute/hold spans come
            // from mode-2 runs.
            OrbitraTraceLog::write([
                'ev' => 'p', 'rid' => OrbitraTraceLog::rid(), 'sql' => $this->orbitraTraceTag($query),
                'held_us' => (int) round((microtime(true) - $t0) * 1000000.0),
            ], 'q');
        }
        return $stmt;
    }

    public function query(string $sql, ?int $fetchMode = null, mixed ...$fetchModeArgs): PDOStatement|false
    {
        $t0 = microtime(true);
        // Forward EXACTLY the argument count the caller gave: PDO's query()
        // with ≥2 args enters the set-fetch-mode path and tries to hand the
        // extras to the statement class constructor — a user PDOStatement
        // subclass without a matching constructor fatals right there
        // ("User-supplied statement does not accept constructor arguments").
        // Plain parent::query($sql) avoids the whole path for the common case.
        if ($fetchMode === null && $fetchModeArgs === []) {
            $stmt = parent::query($sql);
        } elseif ($fetchModeArgs === []) {
            $stmt = parent::query($sql, $fetchMode);
        } else {
            $stmt = parent::query($sql, $fetchMode, ...$fetchModeArgs);
        }
        $execUs = (microtime(true) - $t0) * 1000000.0;
        if ($stmt instanceof OrbitraTraceStmt) {
            $stmt->traceSql = $this->orbitraTraceTag($sql);
            $stmt->traceSrc = $this->orbitraTraceSrc();
            // parent::query() already ran the statement through PDO's internal
            // path (execute() was not called on the decorator), so the hold
            // span opens here, from the query() call.
            $stmt->orbitraTraceMarkExecuted($t0, $execUs);
        } else {
            OrbitraTraceLog::write([
                'ev' => 'x', 'rid' => OrbitraTraceLog::rid(), 'sql' => $this->orbitraTraceTag($sql),
                'held_us' => (int) round($execUs),
            ], 'q');
        }
        return $stmt;
    }

    public function exec(string $statement): int|false
    {
        $t0 = microtime(true);
        $r = parent::exec($statement);
        OrbitraTraceLog::write([
            'ev' => 'x', 'rid' => OrbitraTraceLog::rid(), 'sql' => $this->orbitraTraceTag($statement),
            'held_us' => (int) round((microtime(true) - $t0) * 1000000.0),
        ], 'q');
        return $r;
    }

    public function beginTransaction(): bool
    {
        $r = parent::beginTransaction();
        if ($r) {
            $this->traceInTxn = true;
            $this->traceTxnStartedAt = microtime(true);
        }
        return $r;
    }

    public function commit(): bool
    {
        $t0 = microtime(true);
        $r = parent::commit();
        $this->orbitraTraceTxnEnd($r ? 'commit' : 'commit_failed', $t0);
        return $r;
    }

    public function rollBack(): bool
    {
        $t0 = microtime(true);
        $r = parent::rollBack();
        $this->orbitraTraceTxnEnd($r ? 'rollback' : 'rollback_failed', $t0);
        return $r;
    }

    private function orbitraTraceTxnEnd(string $end, float $t0): void
    {
        if (!$this->traceInTxn || $this->traceTxnStartedAt === null) {
            return;
        }
        $this->traceInTxn = false;
        $heldUs = (microtime(true) - ($this->traceTxnStartedAt ?? $t0)) * 1000000.0;
        $this->traceTxnStartedAt = null;
        OrbitraTraceLog::write([
            'ev' => 'txn', 'rid' => OrbitraTraceLog::rid(), 'sql' => 'txn',
            'held_us' => (int) round($heldUs), 'end' => $end,
        ]);
    }

    public function __destruct()
    {
        // A transaction still open at destruct is the longest possible hold:
        // the snapshot lived until the process (or request) ended.
        if ($this->traceInTxn && $this->traceTxnStartedAt !== null) {
            $this->orbitraTraceTxnEnd('destruct', microtime(true));
        }
    }
}
