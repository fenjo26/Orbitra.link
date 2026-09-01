<?php
// tests/media_core_test.php
//
// Media library core (docs/media-core-v1.md, migration 44):
//   - orbitraMediaAllowedExtensions()  — hard extension whitelist, SVG banned;
//   - orbitraMediaUrl()/orbitraMediaRow() — the ONE public row shape the
//     gallery page and MediaPicker both consume ({id, url, width...});
//   - orbitraMediaStoreUpload()        — rejection paths that run before the
//     filesystem move (upload error, 10 MB cap, extension). The happy path
//     needs a real multipart upload (is_uploaded_file/move_uploaded_file are
//     CLI-hostile), so it is covered by the manual HTTP smoke, not here;
//   - the SQL semantics the media_op / media_folder_op cases rely on, against
//     the same DDL as migration 44: soft delete/restore, folder deletion
//     dropping files to the root, and the owner guard that lets a non-admin
//     mutate only their own rows.
//
// The functions are extracted from api.php, not copied — api.php cannot be
// required standalone (it is the API switch). Bodies are indented, so "\n}"
// only ends a top-level function.
//
// Run: php tests/media_core_test.php

$repoRoot = dirname(__DIR__);
$src = file_get_contents($repoRoot . '/api.php');
foreach (['orbitraMediaAllowedExtensions', 'orbitraMediaUrl', 'orbitraMediaRow', 'orbitraMediaStoreUpload'] as $fn) {
    if (!preg_match('/^function ' . preg_quote($fn, '/') . '\(.*?\n\}/ms', $src, $m)) {
        fwrite(STDERR, "could not extract function {$fn} from api.php\n");
        exit(1);
    }
    eval($m[0] . ';');
}

$assert = function (string $label, $got, $expected) {
    if ($got !== $expected) {
        echo "FAIL $label: got " . var_export($got, true) . ", expected " . var_export($expected, true) . "\n";
        exit(1);
    }
    echo "ok  $label\n";
};

// ---------- extension whitelist ----------
$ext = orbitraMediaAllowedExtensions();
$assert('webp allowed', in_array('webp', $ext, true), true);
$assert('jpg allowed', in_array('jpg', $ext, true), true);
$assert('png allowed', in_array('png', $ext, true), true);
$assert('gif allowed', in_array('gif', $ext, true), true);
$assert('svg banned (active content)', in_array('svg', $ext, true), false);
$assert('php banned', in_array('php', $ext, true), false);

// ---------- URL + row shape ----------
$assert('url base', orbitraMediaUrl('ab/abc123def456-9a8b7c6d.png'), '/uploads/media/ab/abc123def456-9a8b7c6d.png');
$assert('url tolerates leading slash', orbitraMediaUrl('/ab/x.png'), '/uploads/media/ab/x.png');

$row = [
    'id' => '7', 'orig_name' => 'creative.png', 'stored_name' => 'ab/hash-1.png',
    'mime' => 'image/png', 'size' => '1234', 'width' => '512', 'height' => '512',
    'folder_id' => null, 'owner_user_id' => '3', 'is_active' => '1',
    'created_at' => '2026-09-01 10:00:00',
];
$mapped = orbitraMediaRow($row, [3 => 'designer']);
$assert('row id cast', $mapped['id'], 7);
$assert('row url', $mapped['url'], '/uploads/media/ab/hash-1.png');
$assert('row folder null', $mapped['folder_id'], null);
$assert('row owner name lookup', $mapped['owner_name'], 'designer');
$assert('row width cast', $mapped['width'], 512);
$assert('row is_active', $mapped['is_active'], true);
$assert('row keys stable', implode(',', array_keys($mapped)), implode(',', [
    'id', 'orig_name', 'url', 'mime', 'size', 'width', 'height',
    'folder_id', 'owner_user_id', 'owner_name', 'is_active', 'created_at',
]));

// ---------- upload rejection paths (pre-filesystem) ----------
[$r, $err] = orbitraMediaStoreUpload(['name' => 'x.png', 'error' => UPLOAD_ERR_INI_SIZE, 'size' => 1, 'tmp_name' => '/nonexistent'], 1, null);
$assert('upload error rejected', $err, 'media.err_upload');

[$r, $err] = orbitraMediaStoreUpload(['name' => 'big.png', 'error' => UPLOAD_ERR_OK, 'size' => 11 * 1024 * 1024, 'tmp_name' => '/nonexistent'], 1, null);
$assert('oversize rejected with media.err_too_large', $err, 'media.err_too_large');

[$r, $err] = orbitraMediaStoreUpload(['name' => 'shell.php', 'error' => UPLOAD_ERR_OK, 'size' => 10, 'tmp_name' => '/nonexistent'], 1, null);
$assert('php extension rejected', $err, 'media.err_extension');

[$r, $err] = orbitraMediaStoreUpload(['name' => 'vector.svg', 'error' => UPLOAD_ERR_OK, 'size' => 10, 'tmp_name' => '/nonexistent'], 1, null);
$assert('svg extension rejected', $err, 'media.err_extension');

[$r, $err] = orbitraMediaStoreUpload(['name' => 'ok.png', 'error' => UPLOAD_ERR_OK, 'size' => 10, 'tmp_name' => '/nonexistent'], 1, null);
$assert('row is null on rejection', $r, null);

// ---------- SQL semantics of media_op / media_folder_op ----------
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("CREATE TABLE media_folders (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    name TEXT NOT NULL,
    owner_user_id INTEGER,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
)");
$pdo->exec("CREATE TABLE media_assets (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    owner_user_id INTEGER,
    folder_id INTEGER,
    orig_name TEXT NOT NULL DEFAULT '',
    stored_name TEXT NOT NULL,
    sha256 TEXT NOT NULL DEFAULT '',
    mime TEXT NOT NULL DEFAULT '',
    size INTEGER NOT NULL DEFAULT 0,
    width INTEGER,
    height INTEGER,
    is_active INTEGER NOT NULL DEFAULT 1,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    deleted_at DATETIME
)");

$ins = $pdo->prepare("INSERT INTO media_assets (owner_user_id, folder_id, orig_name, stored_name, mime, size, width, height)
                      VALUES (?, ?, ?, ?, 'image/png', 10, 8, 8)");
$asset = function (int $owner, $folder, string $name = 'img.png') use ($ins, $pdo) {
    $ins->execute([$owner, $folder, $name, 'ab/h-' . $name]);
    return (int) $pdo->lastInsertId();
};

$a1 = $asset(1, null, 'mine-1.png');
$a2 = $asset(1, null, 'mine-2.png');
$a3 = $asset(2, null, 'theirs.png');

// Soft delete: is_active flips, the row (and file reference) stays.
$pdo->prepare("UPDATE media_assets SET is_active = 0, deleted_at = CURRENT_TIMESTAMP
               WHERE id IN (?, ?) AND is_active = 1 AND owner_user_id = ?")
    ->execute([$a1, $a3, 1]);
$assert('soft delete only touched own row', (int) $pdo->query("SELECT COUNT(*) FROM media_assets WHERE is_active = 0")->fetchColumn(), 1);
$assert('other user row untouched', (int) $pdo->query("SELECT COUNT(*) FROM media_assets WHERE id = $a3 AND is_active = 1")->fetchColumn(), 1);

// Restore is the exact inverse.
$pdo->prepare("UPDATE media_assets SET is_active = 1, deleted_at = NULL
               WHERE id IN (?) AND is_active = 0 AND owner_user_id = ?")
    ->execute([$a1, 1]);
$assert('restore flips back', (int) $pdo->query("SELECT COUNT(*) FROM media_assets WHERE is_active = 1")->fetchColumn(), 3);

// Folder lifecycle: create, move two in, delete → files fall back to root.
$pdo->exec("INSERT INTO media_folders (name, owner_user_id) VALUES ('Creatives', 1)");
$folderId = (int) $pdo->lastInsertId();
$pdo->prepare("UPDATE media_assets SET folder_id = ? WHERE id IN (?, ?)")->execute([$folderId, $a1, $a2]);
$inFolder = $pdo->query("SELECT COUNT(*) FROM media_assets WHERE folder_id = $folderId")->fetchColumn();
$assert('move to folder', (int) $inFolder, 2);

$pdo->prepare("UPDATE media_assets SET folder_id = NULL WHERE folder_id = ?")->execute([$folderId]);
$pdo->prepare("DELETE FROM media_folders WHERE id = ?")->execute([$folderId]);
$assert('folder gone', (int) $pdo->query("SELECT COUNT(*) FROM media_folders")->fetchColumn(), 0);
$assert('files survived at root', (int) $pdo->query("SELECT COUNT(*) FROM media_assets WHERE folder_id IS NULL")->fetchColumn(), 3);

// Admin path has no owner guard: same UPDATE shape without the AND clause.
$pdo->prepare("UPDATE media_assets SET is_active = 0, deleted_at = CURRENT_TIMESTAMP
               WHERE id IN (?, ?, ?) AND is_active = 1")->execute([$a1, $a2, $a3]);
$assert('admin deletes across owners', (int) $pdo->query("SELECT COUNT(*) FROM media_assets WHERE is_active = 0")->fetchColumn(), 3);

echo "media_core_test: all passed\n";
