<?php
/**
 * Landing slugs and on-disk path resolution.
 *
 * A local landing's files used to live in landings/<id>/ — functional but
 * opaque, and unlike the /lander/<name> layout operators expect from Keitaro.
 * Landings now carry a slug, and the physical directory is landings/<slug>/.
 *
 * The slug is the single source of truth for where a landing's files are, but
 * every caller still passes the landing's numeric id (taken from the cookie, a
 * POST field, the clicks row). The id is what the visitor and the editor are
 * allowed to influence; the slug is looked up from the database, so a request
 * can never point the resolver at an arbitrary directory.
 *
 * Landings created before the slug column exist have it backfilled on migrate;
 * anything still empty falls back to landings/<id>/ so nothing ever breaks.
 */

require_once __DIR__ . '/../core/admin_path.php';

/**
 * Cyrillic -> Latin, used when the intl extension is not installed.
 *
 * Deliberately covers only Russian and Ukrainian: those are the alphabets a
 * landing name actually arrives in here, and a partial table is better than a
 * fatal. Anything outside it still collapses to dashes, which is the same
 * result the old code produced for unmapped characters.
 */
function orbitraTransliterationMap(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [
        'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd', 'е' => 'e',
        'ё' => 'e', 'ж' => 'zh', 'з' => 'z', 'и' => 'i', 'й' => 'y', 'к' => 'k',
        'л' => 'l', 'м' => 'm', 'н' => 'n', 'о' => 'o', 'п' => 'p', 'р' => 'r',
        'с' => 's', 'т' => 't', 'у' => 'u', 'ф' => 'f', 'х' => 'h', 'ц' => 'ts',
        'ч' => 'ch', 'ш' => 'sh', 'щ' => 'sch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
        'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        // Ukrainian letters absent from the Russian alphabet.
        'і' => 'i', 'ї' => 'yi', 'є' => 'ye', 'ґ' => 'g',
        // Common Latin diacritics, so 'café' -> 'cafe' without intl.
        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ó' => 'o', 'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ñ' => 'n', 'ç' => 'c', 'ý' => 'y', 'ÿ' => 'y', 'ß' => 'ss', 'æ' => 'ae',
    ];
    return $map;
}

/**
 * Turn a free-form name into a filesystem-safe slug.
 *
 * Lowercase, ASCII letters/digits/dashes/underscores only; everything else
 * collapses to a single dash. A name with no ASCII (e.g. all-Cyrillic) becomes
 * empty, which the caller turns into 'landing-<id>'.
 *
 * Transliteration is best-effort and must never be fatal. intl is not in the
 * install script's package list, and on PHP 8 calling a function that does not
 * exist raises an Error that @ does not suppress — which turned "create a
 * landing with an auto-generated folder" into a 500 the panel reported as a
 * network error. The intl path is therefore guarded and backed by a plain
 * character map, so the same slug comes out either way for the alphabets that
 * matter here.
 */
function orbitraSlugify(string $name): string
{
    $name = function_exists('mb_strtolower') ? mb_strtolower($name, 'UTF-8') : strtolower($name);

    // Preferred: intl, which handles every script rather than a fixed table.
    if (function_exists('transliterator_transliterate')) {
        $translit = @transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $name);
        if (is_string($translit) && $translit !== '') {
            $name = $translit;
        }
    } else {
        $name = strtr($name, orbitraTransliterationMap());
        // Whatever the table missed (other scripts, stray accents) gets one more
        // pass through iconv when it is available; failure here is fine, the
        // regex below drops anything still non-ASCII.
        if (function_exists('iconv')) {
            $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
            if (is_string($ascii) && $ascii !== '') {
                $name = $ascii;
            }
        }
    }

    $slug = preg_replace('/[^a-z0-9_-]+/', '-', strtolower($name));
    $slug = trim((string) $slug, '-_');

    // Collapse runs left behind by dropped characters: 'a--b' is a valid slug,
    // but 'a-b' is the one a human would have typed.
    $slug = preg_replace('/-{2,}/', '-', $slug);

    // The validator caps a slug at 64 characters; cut here so a long name still
    // produces a usable folder instead of being rejected.
    if (strlen($slug) > 64) {
        $slug = rtrim(substr($slug, 0, 64), '-_');
    }

    return (string) $slug;
}

/**
 * Reserved path segments a slug must never collide with.
 * These would shadow real application routes (api.php, the admin path, etc.).
 */
function orbitraLandingSlugReserved(): array
{
    static $reserved = null;
    if ($reserved !== null) {
        return $reserved;
    }
    // Reuse the admin-path reserved list and add landing-specific concerns.
    $reserved = array_merge(ORBITRA_ADMIN_PATH_RESERVED, ['lander']);
    return array_unique($reserved);
}

/**
 * Validate a candidate slug.
 *
 * @return array{ok: bool, value: string, error: string}
 *   value is the normalised slug on success, or '' when empty is allowed.
 */
function orbitraValidateLandingSlug(PDO $pdo, $raw, $landingId = null): array
{
    $value = strtolower(trim((string) $raw, "/ \t\n\r\0\x0B"));

    // An empty slug is allowed: it means "fall back to landings/<id>/".
    if ($value === '') {
        return ['ok' => true, 'value' => '', 'error' => ''];
    }

    if (!preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $value)) {
        return ['ok' => false, 'value' => '', 'error' => 'landing_slug_invalid'];
    }

    if (in_array($value, orbitraLandingSlugReserved(), true)) {
        return ['ok' => false, 'value' => '', 'error' => 'landing_slug_reserved'];
    }

    // Uniqueness: two landings cannot share a folder. Exclude the landing being
    // edited so renaming to itself (or fixing its own case) is allowed.
    try {
        if ($landingId !== null) {
            $stmt = $pdo->prepare("SELECT 1 FROM landings WHERE slug = ? AND id != ? LIMIT 1");
            $stmt->execute([$value, (int) $landingId]);
        } else {
            $stmt = $pdo->prepare("SELECT 1 FROM landings WHERE slug = ? LIMIT 1");
            $stmt->execute([$value]);
        }
        if ($stmt->fetchColumn()) {
            return ['ok' => false, 'value' => '', 'error' => 'landing_slug_taken'];
        }
    } catch (\Throwable $e) {
        // If the check cannot run, refuse — a duplicate folder would silently
        // merge two landings' files, which is worse than blocking the edit.
        return ['ok' => false, 'value' => '', 'error' => 'landing_slug_check_failed'];
    }

    return ['ok' => true, 'value' => $value, 'error' => ''];
}

/**
 * The on-disk directory for a landing's files.
 *
 * Reads the slug from the database; a valid slug gives landings/<slug>/, an
 * absent or empty slug falls back to landings/<id>/ so pre-migration landings
 * keep working. Never returns null: the path may not exist yet (the landing was
 * created but no archive uploaded), but it is always a string inside landings/.
 */
function orbitraLandingDir(PDO $pdo, $id): string
{
    $id = (int) $id;
    $base = dirname(__DIR__) . '/landings';

    if ($id <= 0) {
        return $base . '/0';
    }

    $slug = '';
    try {
        $stmt = $pdo->prepare("SELECT slug FROM landings WHERE id = ? LIMIT 1");
        $stmt->execute([$id]);
        $row = $stmt->fetchColumn();
        if (is_string($row)) {
            $slug = trim($row);
        }
    } catch (\Throwable $e) {
        $slug = '';
    }

    // A slug read from the DB is still re-validated before it touches a path —
    // defence in depth against a corrupt or hand-edited row.
    if ($slug !== '' && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug) && !in_array($slug, orbitraLandingSlugReserved(), true)) {
        return $base . '/' . $slug;
    }

    return $base . '/' . $id;
}

/**
 * The directory for a landing that is about to be created (no id yet).
 *
 * Used only to compute where a future upload would land, so validation is the
 * gatekeeper rather than a DB lookup. Returns landings/<slug> or landings/0
 * (a sentinel the INSERT will not actually use).
 */
function orbitraLandingDirForSlug(string $slug): string
{
    $base = dirname(__DIR__) . '/landings';
    if ($slug !== '' && preg_match('/^[a-z0-9][a-z0-9_-]{0,63}$/', $slug) && !in_array($slug, orbitraLandingSlugReserved(), true)) {
        return $base . '/' . $slug;
    }
    return $base . '/0';
}

/**
 * Lift a "single nested folder" archive to the target root.
 *
 * Archives zipped from a directory ("Compress folder", `zip -r landing.zip
 * landing/`, macOS Finder) put every real file one level down, while the
 * click router only serves index.php/index.html from the root — the landing
 * answers "files not found" forever. When the only non-junk entry is one
 * directory, its contents move up to the root. __MACOSX resource forks and
 * dotfiles count as junk, not content.
 *
 * Lives here (not api.php) so the upload handler and the tests share one
 * implementation; api.php cannot be required standalone.
 */
function orbitraFlattenSingleNestedDir(string $dir): void
{
    $junk = ['.', '..', '__MACOSX'];
    $entries = [];
    foreach ((array) scandir($dir) as $e) {
        if (in_array($e, $junk, true) || $e[0] === '.') {
            continue;
        }
        $entries[] = $e;
    }
    if (count($entries) !== 1 || !is_dir($dir . '/' . $entries[0])) {
        return;
    }
    $nested = $dir . '/' . $entries[0];
    foreach ((array) scandir($nested) as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        @rename($nested . '/' . $file, $dir . '/' . $file);
    }
    @rmdir($nested);

    // Whatever the layout, Finder's __MACOSX tree is never wanted.
    $macosx = $dir . '/__MACOSX';
    if (is_dir($macosx)) {
        $it = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($macosx, RecursiveDirectoryIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($it as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($macosx);
    }

    @chmod($dir, 0755);
    clearstatcache(true, $dir);
}

/**
 * Delete a directory tree. Best-effort like every other removal here: a stuck
 * file answers with a false from the caller that needed it gone, not a crash.
 */
function orbitraRmdirTree(string $dir): bool
{
    if (!is_dir($dir)) {
        return true;
    }
    $ok = true;
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $entry) {
        $entry->isDir() ? ($ok = @rmdir($entry->getPathname()) && $ok) : ($ok = @unlink($entry->getPathname()) && $ok);
    }
    return @rmdir($dir) && $ok;
}

/**
 * Extract an opened upload archive into $destDir by way of a staging sibling.
 *
 * Extracting straight into the destination merges the new archive with whatever
 * the previous upload left behind — a replaced template kept serving its old
 * index next to the new files. The stage-then-swap also means a rejected
 * archive (PHP scan) leaves the previously uploaded files intact, where the
 * old code deleted the merged directory on failure.
 *
 * After extraction the archive is flattened, scanned for forbidden PHP
 * ( PhpLanding::scanDirectory — hard tier only) and sanitized of the calls that
 * would lift the runtime limits, so the swap installs a checked tree.
 *
 * @param ZipArchive $zip opened for reading; closed here in every path
 * @param PDO $pdo tracker handle, for the PhpLanding settings
 * @return array{ok: bool, error?: array{message: string, detail?: array}, sanitized?: array<string,string[]>}
 */
function orbitraExtractArchiveSwap(ZipArchive $zip, string $destDir, PDO $pdo): array
{
    $stage = $destDir . '.stage-' . bin2hex(random_bytes(4));
    if (!@mkdir($stage, 0775, true) && !is_dir($stage)) {
        $zip->close();
        return ['ok' => false, 'error' => ['message' => 'stage_dir_not_created', 'detail' => ['path' => $stage]]];
    }

    if (!$zip->extractTo($stage)) {
        // A ZIP can be readable and still be unextractable: the "maximum
        // compression" preset in 7-Zip and WinRAR writes LZMA, BZip2 or PPMd
        // entries, and libzip is normally built with Store and Deflate only.
        // The archive opens, the file list reads fine, and extraction just
        // fails — so check the methods before blaming permissions.
        $badMethods = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $stat = $zip->statIndex($i);
            $method = $stat['comp_method'] ?? 8;
            if (!in_array((int) $method, [0, 8], true)) {
                $badMethods[(int) $method] = true;
            }
        }
        $zip->close();
        orbitraRmdirTree($stage);
        if ($badMethods) {
            $methodNames = [9 => 'Deflate64', 12 => 'BZip2', 14 => 'LZMA', 93 => 'Zstandard', 95 => 'XZ', 98 => 'PPMd'];
            $named = [];
            foreach (array_keys($badMethods) as $m) {
                $named[] = $methodNames[$m] ?? ('метод ' . $m);
            }
            return ['ok' => false, 'error' => ['message' => 'zip_unsupported_compression', 'detail' => ['methods' => $named]]];
        }
        return ['ok' => false, 'error' => ['message' => 'zip_extract_failed', 'detail' => ['path' => $destDir]]];
    }
    $zip->close();

    orbitraFlattenSingleNestedDir($stage);

    require_once __DIR__ . '/PhpLanding.php';
    $phpProblems = PhpLanding::scanDirectory($stage);
    if ($phpProblems) {
        $lines = [];
        foreach ($phpProblems as $file => $names) {
            $lines[] = $file . ': ' . implode(', ', $names);
        }
        orbitraRmdirTree($stage);
        return ['ok' => false, 'error' => ['message' => 'php_scan_failed', 'detail' => ['files' => $lines]]];
    }

    $sanitized = PhpLanding::sanitizeDirectory($stage);

    if (is_dir($destDir) && !orbitraRmdirTree($destDir)) {
        orbitraRmdirTree($stage);
        return ['ok' => false, 'error' => ['message' => 'dest_dir_not_cleared', 'detail' => ['path' => $destDir]]];
    }
    if (!@rename($stage, $destDir)) {
        orbitraRmdirTree($stage);
        return ['ok' => false, 'error' => ['message' => 'dest_dir_swap_failed', 'detail' => ['path' => $destDir]]];
    }

    return ['ok' => true, 'sanitized' => $sanitized];
}

/**
 * Root of a local landing's/offer's files, seeing through single-nested-folder
 * archives. New uploads are flattened on the way in (orbitraFlattenSingleNestedDir),
 * but archives uploaded before that keep everything one level down — resolve the
 * subdirectory holding the index instead of answering "files not found".
 *
 * clearstatcache() first: PHP caches stat results for the request's lifetime,
 * and the click that tests the campaign link can arrive moments after the
 * upload request created these paths on a box where the same FPM worker
 * served both. Lives here so index.php and the tests share one implementation.
 */
function orbitraLandingContentDir($dir)
{
    clearstatcache(true, $dir);
    foreach (['index.php', 'index.html'] as $entry) {
        if (is_file($dir . '/' . $entry)) {
            return $dir;
        }
    }
    foreach ((array) glob($dir . '/*', GLOB_ONLYDIR) as $sub) {
        $base = basename($sub);
        if ($base === '__MACOSX' || $base[0] === '.') {
            continue;
        }
        if (is_file($sub . '/index.php') || is_file($sub . '/index.html')) {
            return $sub;
        }
    }
    return $dir;
}
