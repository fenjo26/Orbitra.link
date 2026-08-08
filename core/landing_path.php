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
 * Turn a free-form name into a filesystem-safe slug.
 *
 * Lowercase, ASCII letters/digits/dashes/underscores only; everything else
 * collapses to a single dash. A name with no ASCII (e.g. all-Cyrillic) becomes
 * empty, which the caller turns into 'landing-<id>'.
 */
function orbitraSlugify(string $name): string
{
    // Transliterate where possible so 'café' -> 'cafe' rather than 'caf'.
    $translit = @transliterator_transliterate('Any-Latin; Latin-ASCII; Lower()', $name);
    if ($translit !== false && $translit !== '') {
        $name = $translit;
    } else {
        $name = strtolower($name);
    }

    $slug = preg_replace('/[^a-z0-9_-]+/', '-', strtolower($name));
    $slug = trim($slug, '-_');

    return $slug;
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
