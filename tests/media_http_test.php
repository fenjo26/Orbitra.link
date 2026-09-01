<?php
// tests/media_http_test.php
//
// End-to-end pass over the media library API (docs/media-core-v1.md):
// migration 44 stamping, real multipart upload (getimagesize gate), public
// serving of the stored file, list filters, soft delete/restore, the
// owner-or-admin write guard, folder lifecycle and the media resource RBAC
// matrix ('none' blocks reads, 'read' blocks writes).
//
// Runs on the standard sandboxed harness: a /tmp copy of the repo + a scratch
// SQLite database on its own port — the working orbitra_db.sqlite is never
// touched.
//
// Run: php tests/media_http_test.php

$repoRoot = dirname(__DIR__);
require_once $repoRoot . '/tests/lib/http.php';

$failures = [];
$checks = 0;

$check = static function (string $name, $expected, $actual) use (&$failures, &$checks) {
    $checks++;
    if ($expected !== $actual) {
        $failures[] = "$name: expected " . var_export($expected, true) . ', got ' . var_export($actual, true);
    }
};

$harness = new OrbitraTestHarness($repoRoot);
$harness->useProductionRouter();
$harness->start();

try {
    $pdo = $harness->getPdo();

    // --- fixtures ----------------------------------------------------------
    $insertUser = static function (string $username, string $role, ?array $permissions) use ($pdo) {
        $pdo->prepare("INSERT INTO users (username, password, role, is_active, permissions_json) VALUES (?, ?, ?, 1, ?)")
            ->execute([
                $username,
                password_hash('pass123', PASSWORD_DEFAULT),
                $role,
                $permissions === null ? '{}' : json_encode($permissions),
            ]);
        return (int) $pdo->lastInsertId();
    };
    $insertUser('media_admin', 'admin', null);
    $insertUser('media_user', 'user', null);
    $insertUser('media_none', 'user', ['media' => ['access' => 'none']]);
    $insertUser('media_read', 'user', ['media' => ['access' => 'read']]);

    // 1×1 transparent PNG — a real image for getimagesize(), 70 bytes.
    $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');
    // Plain text renamed to .png — no image signature at all, so getimagesize
    // must reject it. (A file wearing a valid GIF header but garbage after it
    // would pass — getimagesize only reads the header — but such a file still
    // serves as inert image/gif, it cannot execute.)
    $fakePng = "just a text file, honestly" . str_repeat('x', 32);

    $multipart = static function (array $fields, array $files) {
        $boundary = 'orbitra' . bin2hex(random_bytes(12));
        $body = '';
        foreach ($fields as $name => $value) {
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"$name\"\r\n\r\n$value\r\n";
        }
        foreach ($files as $f) {
            $body .= "--$boundary\r\nContent-Disposition: form-data; name=\"{$f['field']}\"; filename=\"{$f['name']}\"\r\n"
                . "Content-Type: {$f['type']}\r\n\r\n{$f['data']}\r\n";
        }
        $body .= "--$boundary--\r\n";
        return [$body, "multipart/form-data; boundary=$boundary"];
    };

    $login = static function (string $username) use ($harness) {
        try {
            $harness->getPdo()->exec('DELETE FROM rate_limits');
        } catch (\Throwable $e) {
        }
        $resp = $harness->postWithHeaders(
            '/api.php?action=login',
            json_encode(['username' => $username, 'password' => 'pass123']),
            ['Content-Type: application/json']
        );
        $body = json_decode($resp['body'], true);
        if (($body['status'] ?? '') !== 'success') {
            fwrite(STDERR, "login failed for $username: " . $resp['body'] . "\n");
            exit(1);
        }
        preg_match('/ORBITRASESSID=([^;]+)/', $resp['headers']['Set-Cookie'] ?? '', $m);
        return [
            'cookie' => 'ORBITRASESSID=' . $m[1],
            'csrf' => $body['data']['csrf_token'] ?? '',
        ];
    };

    $get = static function (string $query, array $ctx) use ($harness) {
        return $harness->getWithHeaders("/api.php?$query", ['Cookie: ' . $ctx['cookie']]);
    };

    $postJson = static function (string $action, array $ctx, array $payload) use ($harness) {
        return $harness->postWithHeaders(
            "/api.php?action=$action",
            json_encode($payload),
            ['Cookie: ' . $ctx['cookie'], 'X-CSRF-TOKEN: ' . $ctx['csrf'], 'Content-Type: application/json']
        );
    };

    $postFiles = static function (string $action, array $ctx, array $fields, array $files) use ($harness, $multipart) {
        [$body, $type] = $multipart($fields, $files);
        return $harness->postWithHeaders(
            "/api.php?action=$action",
            $body,
            ['Cookie: ' . $ctx['cookie'], 'X-CSRF-TOKEN: ' . $ctx['csrf'], 'Content-Type: ' . $type]
        );
    };

    // --- migration stamp ----------------------------------------------------
    $admin = $login('media_admin');
    $user = $login('media_user');
    $check('migration stamps schema 44', '44', (string) $pdo->query('PRAGMA user_version')->fetchColumn());

    // --- upload: happy path (admin) ------------------------------------------
    $resp = $postFiles('media_upload', $admin, [], [
        ['field' => 'files[]', 'name' => 'hero.png', 'type' => 'image/png', 'data' => $png],
    ]);
    $body = json_decode($resp['body'], true);
    $check('upload: http 200', 200, $resp['code']);
    $check('upload: status success', 'success', $body['status'] ?? '');
    $check('upload: one item', 1, count($body['data']['items'] ?? []));
    $item = $body['data']['items'][0] ?? [];
    $check('upload: dimensions from getimagesize', [1, 1], [$item['width'] ?? null, $item['height'] ?? null]);
    $check('upload: orig name kept', 'hero.png', $item['orig_name'] ?? '');
    $check('upload: url shape', 1, (int) preg_match('#^/uploads/media/[0-9a-f]{2}/[0-9a-f]{12}-[0-9a-f]{8}\.png$#', $item['url'] ?? ''));
    $check('upload: mime', 'image/png', $item['mime'] ?? '');
    $adminAssetId = $item['id'] ?? 0;

    // --- serving: the stored file is publicly fetchable, byte-for-byte --------
    $served = $harness->get($item['url']);
    $check('serve: http 200', 200, $served['code']);
    $check('serve: bytes identical', bin2hex($png), bin2hex((string) $served['body']));

    // --- upload: rejection paths ---------------------------------------------
    $resp = $postFiles('media_upload', $user, [], [
        ['field' => 'files[]', 'name' => 'shell.php', 'type' => 'application/octet-stream', 'data' => '<?php echo 1;'],
    ]);
    $body = json_decode($resp['body'], true);
    $check('upload: php rejected via failed[]', 'media.err_extension', $body['data']['failed'][0]['reason'] ?? null);

    $resp = $postFiles('media_upload', $user, [], [
        ['field' => 'files[]', 'name' => 'fake.png', 'type' => 'image/png', 'data' => $fakePng],
    ]);
    $body = json_decode($resp['body'], true);
    $check('upload: text-as-png rejected (getimagesize gate)', 'media.err_not_image', $body['data']['failed'][0]['reason'] ?? null);

    // --- folders + scoped upload (user) ---------------------------------------
    $resp = $postJson('media_folder_op', $user, ['op' => 'create', 'name' => 'Creatives']);
    $folderId = json_decode($resp['body'], true)['data']['id'] ?? 0;
    $check('folder: created with id', true, $folderId > 0);

    $resp = $postJson('media_folder_op', $user, ['op' => 'create', 'name' => 'Creatives']);
    $check('folder: duplicate rejected', 'media.err_folder_exists', json_decode($resp['body'], true)['message'] ?? null);

    $png2 = $png;
    $png2[20] = chr(ord($png2[20]) ^ 0xFF); // different bytes → different hash
    $resp = $postFiles('media_upload', $user, ['folder_id' => (string) $folderId], [
        ['field' => 'files[]', 'name' => 'banner.png', 'type' => 'image/png', 'data' => $png2],
    ]);
    $body = json_decode($resp['body'], true);
    $userAssetId = $body['data']['items'][0]['id'] ?? 0;
    $check('upload: folder scoping', $folderId, $body['data']['items'][0]['folder_id'] ?? null);
    $check('upload: owner is the uploader', true, ($body['data']['items'][0]['owner_user_id'] ?? 0) > 0);

    // --- list: filters + admin-only users index --------------------------------
    $body = json_decode($get('action=media_list', $admin)['body'], true);
    $check('list: admin sees both assets', 2, count($body['data']['items']));
    $check('list: admin gets users index', true, isset($body['data']['users']));

    $body = json_decode($get('action=media_list', $user)['body'], true);
    $check('list: shared library for user too', 2, count($body['data']['items']));
    $check('list: no users index for non-admin', false, isset($body['data']['users']));

    $body = json_decode($get('action=media_list&q=banner', $user)['body'], true);
    $check('list: q filter', ['banner.png'], array_column($body['data']['items'], 'orig_name'));

    $body = json_decode($get("action=media_list&folder_id=$folderId", $user)['body'], true);
    $check('list: folder filter', ['banner.png'], array_column($body['data']['items'], 'orig_name'));

    $body = json_decode($get('action=media_folders', $user)['body'], true);
    $check('folders: listed with counts', [['id' => $folderId, 'name' => 'Creatives', 'asset_count' => 1]],
        array_map(fn($r) => ['id' => $r['id'], 'name' => $r['name'], 'asset_count' => $r['asset_count']], $body['data']['items']));

    // --- owner guard: user cannot delete the admin's asset ---------------------
    $body = json_decode($postJson('media_op', $user, ['op' => 'delete', 'ids' => [$adminAssetId]])['body'], true);
    $check('guard: foreign delete denied', ['updated' => 0, 'denied' => 1], $body['data']);

    $body = json_decode($postJson('media_op', $user, ['op' => 'delete', 'ids' => [$userAssetId]])['body'], true);
    $check('guard: own delete works', ['updated' => 1, 'denied' => 0], $body['data']);

    $body = json_decode($get('action=media_list&status=inactive', $user)['body'], true);
    $check('archive: deleted visible as inactive', ['banner.png'], array_column($body['data']['items'], 'orig_name'));

    $body = json_decode($postJson('media_op', $user, ['op' => 'restore', 'ids' => [$userAssetId]])['body'], true);
    $check('restore: works', 1, $body['data']['updated'] ?? 0);

    // Admin mutates across owners.
    $body = json_decode($postJson('media_op', $admin, ['op' => 'move', 'ids' => [$adminAssetId], 'folder_id' => $folderId])['body'], true);
    $check('admin: move foreign asset', 1, $body['data']['updated'] ?? 0);

    // --- folder deletion keeps files (root fallback) ----------------------------
    $postJson('media_folder_op', $admin, ['op' => 'delete', 'id' => $folderId]);
    $body = json_decode($get('action=media_folders', $admin)['body'], true);
    $check('folder: deleted', 0, count($body['data']['items']));
    $row = $pdo->query("SELECT folder_id FROM media_assets WHERE id = $adminAssetId")->fetch(PDO::FETCH_ASSOC);
    $check('folder: files fell back to root', null, $row['folder_id']);

    // --- RBAC matrix for the media resource --------------------------------------
    $none = $login('media_none');
    $read = $login('media_read');
    $check('rbac: none blocks media_list', 403, $get('action=media_list', $none)['code']);
    $check('rbac: none blocks media_upload', 403, $postFiles('media_upload', $none, [], [
        ['field' => 'files[]', 'name' => 'x.png', 'type' => 'image/png', 'data' => $png],
    ])['code']);
    $check('rbac: none blocks media_folder_op', 403, $postJson('media_folder_op', $none, ['op' => 'create', 'name' => 'x'])['code']);
    $check('rbac: read allows media_list', 200, $get('action=media_list', $read)['code']);
    $check('rbac: read allows media_folders', 200, $get('action=media_folders', $read)['code']);
    $check('rbac: read blocks media_upload', 403, $postFiles('media_upload', $read, [], [
        ['field' => 'files[]', 'name' => 'x.png', 'type' => 'image/png', 'data' => $png],
    ])['code']);
    $check('rbac: read blocks media_op', 403, $postJson('media_op', $read, ['op' => 'delete', 'ids' => [$userAssetId]])['code']);

    // --- unsandboxed leftovers ---------------------------------------------------
    // (none: uploads went to the /tmp working dir; the repo uploads/ stays empty)

} finally {
    $harness->stop();
}

if ($failures) {
    echo "media_http_test: " . count($failures) . " FAILURE(S) of $checks\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "media_http_test: all $checks checks passed\n";
