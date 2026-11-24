<?php
// core/archive_download.php
//
// A local landing's or offer's files, handed back to the operator as one ZIP.
//
// The panel takes an archive in (upload_landing / upload_offer) and edits the
// files one by one, but could never give the whole thing back: the only way to
// a backup, a copy on another install or a look at the real tree was a file
// manager on the server. landing_download and offer_download stream the folder.
//
// The ZIP is written by hand rather than with ZipArchive, on purpose:
//   - nothing is written to disk — the bytes go straight to the client, so a
//     full or read-only temp directory cannot break it;
//   - it works without ext/zip (the upload insists on it; a download need not);
//   - memory stays flat: a file is deflated only while it fits the budget,
//     larger ones are stored as they are and copied in chunks;
//   - symlinks are never followed — a link planted in a landing folder must
//     not hand out a file from elsewhere on the server.
//
// Only the classic ZIP format is produced (4 GiB and 65 535 entries at most);
// orbitraArchiveCollectFiles() refuses a folder beyond that before the first
// byte leaves, while the HTTP status can still say so.

if (!class_exists('OrbitraArchiveDownloadError')) {
    /**
     * A refusal the caller can still report as an HTTP status: a code for the
     * panel to translate, and the status to answer with.
     */
    final class OrbitraArchiveDownloadError extends RuntimeException
    {
        private string $errorCode;
        private int $status;

        public function __construct(string $errorCode, int $status = 404)
        {
            parent::__construct($errorCode);
            $this->errorCode = $errorCode;
            $this->status = $status;
        }

        public function errorCode(): string
        {
            return $this->errorCode;
        }

        public function status(): int
        {
            return $this->status;
        }
    }
}

if (!defined('ORBITRA_ZIP_DEFLATE_BUDGET')) {
    /** Files up to this size are deflated (in memory); larger ones are stored. */
    define('ORBITRA_ZIP_DEFLATE_BUDGET', 16 * 1024 * 1024);
}
if (!defined('ORBITRA_ZIP_ENTRY_LIMIT')) {
    /** The classic ZIP format counts entries on two bytes. */
    define('ORBITRA_ZIP_ENTRY_LIMIT', 65535);
}

if (!function_exists('orbitraArchiveCollectFiles')) {
    /**
     * Every regular file under $dir, as the archive will carry it, sorted by
     * name. Symlinks are left out whether they point at a file or a folder;
     * a subdirectory that cannot be opened is skipped, not fatal.
     *
     * @return array{ok: bool, error?: string, status?: int, files: array<int, array{path: string, name: string, size: int}>, bytes: int}
     */
    function orbitraArchiveCollectFiles(string $dir): array
    {
        $empty = ['ok' => false, 'error' => 'archive_no_files', 'status' => 404, 'files' => [], 'bytes' => 0];
        $dir = rtrim($dir, '/');
        if ($dir === '' || !is_dir($dir)) {
            return $empty;
        }

        $it = new RecursiveIteratorIterator(
            new RecursiveCallbackFilterIterator(
                new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
                // A symlink is neither entered nor read, wherever it points.
                static function ($current): bool {
                    return !$current->isLink();
                }
            ),
            RecursiveIteratorIterator::SELF_FIRST,
            RecursiveIteratorIterator::CATCH_GET_CHILD
        );

        $prefix = $dir . '/';
        $files = [];
        $bytes = 0;
        foreach ($it as $entry) {
            // isFile() follows links: the filter above already kept them out,
            // this is the second lock on the same door.
            if ($entry->isLink() || !$entry->isFile() || !$entry->isReadable()) {
                continue;
            }
            $path = $entry->getPathname();
            $name = substr($path, strlen($prefix));
            $size = filesize($path);
            // An entry point on the server itself, or a size that cannot be
            // read, is not worth an archive.
            if ($name === '' || strpos($name, "\0") !== false || $size === false || !is_int($size)) {
                continue;
            }
            $files[] = ['path' => $path, 'name' => str_replace('\\', '/', $name), 'size' => $size];
            $bytes += $size;
        }
        if ($files === []) {
            return $empty;
        }
        usort($files, static fn (array $a, array $b): int => strcmp($a['name'], $b['name']));

        // The archive is a stream, so its size has to be bounded before the
        // first byte leaves: deflating never makes a file longer here (such a
        // file is stored instead), so the stored size is the ceiling.
        $overhead = 22;
        foreach ($files as $file) {
            $overhead += 76 + 2 * strlen($file['name']);
        }
        if (count($files) > ORBITRA_ZIP_ENTRY_LIMIT || $bytes + $overhead >= 0xFFFFFFFF) {
            return ['ok' => false, 'error' => 'archive_too_large', 'status' => 413, 'files' => [], 'bytes' => $bytes];
        }
        return ['ok' => true, 'files' => $files, 'bytes' => $bytes];
    }
}

if (!function_exists('orbitraArchiveLe32')) {
    /** A 32-bit value as four bytes, least significant first. */
    function orbitraArchiveLe32(int $value): string
    {
        return chr($value & 0xFF) . chr(($value >> 8) & 0xFF) . chr(($value >> 16) & 0xFF) . chr(($value >> 24) & 0xFF);
    }
}

if (!function_exists('orbitraArchiveDosDateTime')) {
    /** The ZIP (MS-DOS) time and date of a timestamp, in UTC; before 1980 it is "now". */
    function orbitraArchiveDosDateTime(int $timestamp): array
    {
        $year = (int) gmdate('Y', $timestamp);
        if ($year < 1980) {
            $timestamp = time();
            $year = (int) gmdate('Y', $timestamp);
        }
        $time = ((int) gmdate('G', $timestamp) << 11) | ((int) gmdate('i', $timestamp) << 5) | ((int) gmdate('s', $timestamp) >> 1);
        $date = (($year - 1980) << 9) | ((int) gmdate('n', $timestamp) << 5) | (int) gmdate('j', $timestamp);
        return [$time, $date];
    }
}

if (!function_exists('orbitraArchiveAlreadyCompressed')) {
    /** Extensions whose bytes deflate to nothing worth the CPU. */
    function orbitraArchiveAlreadyCompressed(string $name): bool
    {
        $ext = strtolower(pathinfo($name, PATHINFO_EXTENSION));
        return in_array($ext, ['zip', 'gz', 'tgz', 'bz2', 'xz', '7z', 'rar', 'png', 'jpg', 'jpeg', 'webp', 'avif', 'gif',
            'mp3', 'mp4', 'webm', 'ogg', 'woff', 'woff2', 'pdf', 'ico'], true);
    }
}

if (!function_exists('orbitraArchiveWriteZip')) {
    /**
     * Write $files (from orbitraArchiveCollectFiles) to $stream as a ZIP archive.
     *
     * @param resource $stream   opened for writing, binary
     * @param array $options     deflate_budget — the largest file to deflate, bytes (tests lower it)
     * @return int               bytes written
     * @throws RuntimeException  a file could not be read or the client stream failed
     */
    function orbitraArchiveWriteZip($stream, array $files, array $options = []): int
    {
        $deflateBudget = max(0, (int) ($options['deflate_budget'] ?? ORBITRA_ZIP_DEFLATE_BUDGET));
        $canDeflate = function_exists('gzdeflate');
        $chunkBytes = 1024 * 1024;

        $put = static function (string $bytes) use ($stream): void {
            if ($bytes !== '' && fwrite($stream, $bytes) !== strlen($bytes)) {
                throw new RuntimeException('archive_write_failed');
            }
        };

        $central = '';
        $offset = 0;
        foreach ($files as $file) {
            $name = $file['name'];
            $size = $file['size'];
            // CRC-32 as the ZIP needs it (crc32b), taken as bytes so a 32-bit
            // build cannot turn the high bit into a sign.
            $hash = @hash_file('crc32b', $file['path']);
            if (!is_string($hash) || strlen($hash) !== 8) {
                throw new RuntimeException('archive_file_unreadable');
            }
            $crc = strrev(hex2bin($hash));
            [$dosTime, $dosDate] = orbitraArchiveDosDateTime(@filemtime($file['path']) ?: time());

            $method = 0;
            $data = null;
            if ($canDeflate && $size > 0 && $size <= $deflateBudget && !orbitraArchiveAlreadyCompressed($name)) {
                $raw = @file_get_contents($file['path']);
                // A file that changed under us is stored as it is, not half-zipped.
                if (is_string($raw) && strlen($raw) === $size) {
                    $deflated = @gzdeflate($raw, 6);
                    unset($raw);
                    if (is_string($deflated) && strlen($deflated) < $size) {
                        $method = 8;
                        $data = $deflated;
                    }
                }
            }
            $compressedSize = $data !== null ? strlen($data) : $size;

            // Bit 11: the name is UTF-8 (set whenever it has a non-ASCII byte,
            // so the two headers agree).
            $flags = preg_match('/[\x80-\xFF]/', $name) === 1 ? 0x0800 : 0;
            $versionNeeded = $method === 8 ? 20 : 10;

            $local = pack('Vvvvvv', 0x04034b50, $versionNeeded, $flags, $method, $dosTime, $dosDate)
                . $crc . orbitraArchiveLe32($compressedSize) . orbitraArchiveLe32($size)
                . pack('vv', strlen($name), 0)
                . $name;
            $put($local);
            if ($data !== null) {
                $put($data);
            } elseif ($size > 0) {
                $fh = @fopen($file['path'], 'rb');
                if ($fh === false) {
                    throw new RuntimeException('archive_file_unreadable');
                }
                try {
                    while (!feof($fh)) {
                        $piece = fread($fh, $chunkBytes);
                        if ($piece === false) {
                            throw new RuntimeException('archive_file_unreadable');
                        }
                        $put($piece);
                    }
                } finally {
                    fclose($fh);
                }
            }

            // "version made by" 3.0 (Unix) with mode 0100644: the files come
            // back as plain readable files on whatever extracts them.
            $central .= pack('Vvvvvvv', 0x02014b50, (3 << 8) | 20, $versionNeeded, $flags, $method, $dosTime, $dosDate)
                . $crc . orbitraArchiveLe32($compressedSize) . orbitraArchiveLe32($size)
                . pack('vvvvv', strlen($name), 0, 0, 0, 0)
                . "\x00\x00\xA4\x81" . orbitraArchiveLe32($offset)
                . $name;
            $offset += strlen($local) + $compressedSize;
        }

        $put($central);
        $put(pack('VvvvvVVv', 0x06054b50, 0, 0, count($files), count($files), strlen($central), $offset, 0));
        return $offset + strlen($central) + 22;
    }
}

if (!function_exists('orbitraArchiveFileName')) {
    /**
     * The download's file name in two spellings: an ASCII one for the plain
     * filename= parameter and the entity's own name for filename* (UTF-8).
     *
     * @return array{ascii: string, utf8: string}
     */
    function orbitraArchiveFileName(string $prefix, string $entityName, string $fallback): array
    {
        require_once __DIR__ . '/landing_path.php';
        $ascii = orbitraSlugify($entityName);
        $ascii = $prefix . '-' . ($ascii !== '' ? $ascii : $fallback) . '.zip';

        $utf8 = trim((string) preg_replace('/[^\p{L}\p{N}._-]+/u', '-', $entityName), '-');
        $utf8 = $prefix . '-' . ($utf8 !== '' ? $utf8 : $fallback) . '.zip';
        return ['ascii' => $ascii, 'utf8' => $utf8];
    }
}

if (!function_exists('orbitraArchiveSendZip')) {
    /**
     * Send $files as a ZIP download and end the request.
     *
     * The archive takes as long as the files take to read, so the session lock
     * is released first (the panel stays usable in another tab) and no output
     * buffer is left to hold the whole archive in memory.
     *
     * @param array $files      from orbitraArchiveCollectFiles()
     * @param string $prefix    'landing' | 'offer' — the first word of the file name
     * @param string $entityName  the landing's or offer's name, as the operator sees it
     * @param string $fallback   what to call the file when the name has no usable characters
     */
    function orbitraArchiveSendZip(array $files, string $prefix, string $entityName, string $fallback = 'archive'): void
    {
        if (function_exists('session_status') && session_status() === PHP_SESSION_ACTIVE) {
            @session_write_close();
        }
        @set_time_limit(300);
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        $name = orbitraArchiveFileName($prefix, $entityName, $fallback);
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $name['ascii'] . '"; filename*=UTF-8\'\'' . rawurlencode($name['utf8']));
        header('Cache-Control: no-store, max-age=0');
        header('X-Content-Type-Options: nosniff');

        $stream = fopen('php://output', 'wb');
        if ($stream === false) {
            exit;
        }
        try {
            orbitraArchiveWriteZip($stream, $files);
        } catch (RuntimeException $e) {
            // Headers are gone; the only honest end is to stop, which leaves
            // the client with a truncated archive its unzip tool reports.
            error_log('archive download failed: ' . $e->getMessage());
        }
        exit;
    }
}
