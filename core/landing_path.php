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
