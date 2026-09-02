<?php
// tests/push_macros_test.php
//
// Phase-4 suite for core/PushMacros.php (macro expansion at send time) and
// the push segment SQL in core/PushQueue.php (reg/dep buckets computed with
// one JOIN over the shared conversion aggregate). Fully offline: everything
// runs on an in-memory SQLite with the minimal columns the SQL touches.
//
// Run: php tests/push_macros_test.php

require_once __DIR__ . '/../core/ReportMetrics.php';
require_once __DIR__ . '/../core/PushMacros.php';
require_once __DIR__ . '/../core/PushQueue.php';

$testPassed = true;
function assertTrue($condition, string $message): bool {
    global $testPassed;
    if (!$condition) { fwrite(STDERR, "FAILED: $message\n"); $testPassed = false; }
    else { echo "✓ $message\n"; }
    return (bool) $condition;
}

// ----------------------------------------------------------------------
// Macros.
// ----------------------------------------------------------------------
assertTrue(in_array(PushMacros::expand('Hi {Vasya|Petya}!', 'c1'), ['Hi Vasya!', 'Hi Petya!'], true),
    'choice macro picks one of its options');
$r = (int) PushMacros::expand('{Random=(5,10)}');
assertTrue($r >= 5 && $r <= 10, '{Random=(X,Y)} returns an integer within the range');
assertTrue(PushMacros::expand('{Random=(-3,3)}') !== '', '{Random handles negative bounds');
assertTrue(PushMacros::expand('/go?subid={subid}', 'click-9') === '/go?subid=click-9', '{subid} expands to the click_id');
assertTrue(PushMacros::expand('{subid}', '') === '', 'empty click_id yields an empty {subid}');
$nested = PushMacros::expand('{A|{B|C}}', '');
assertTrue(in_array($nested, ['A', 'B', 'C'], true), 'nested choice resolves the inner group first');
// Ten ALTERNATIVE levels ({a|{b|…{j|k}}}): the innermost peels one pass at a
// time, so exactly 10 passes (MAX_DEPTH) resolve the whole thing.
$deep10 = 'k';
for ($i = 0; $i < 10; $i++) {
    $letter = chr(ord('j') - $i);
    $deep10 = '{' . $letter . '|' . $deep10 . '}';
}
assertTrue(in_array(PushMacros::expand($deep10, ''), ['a', 'b', 'c', 'd', 'e', 'f', 'g', 'h', 'i', 'j', 'k'], true),
    'nesting up to 10 levels resolves');
// Eleven levels need an 11th pass — the outermost stays verbatim (as-is).
$deep11 = '{x|' . $deep10 . '}';
$deep11Result = PushMacros::expand($deep11, '');
assertTrue(strpos($deep11Result, '{') !== false, 'deeper than 10 levels stays as-is (braces remain)');
assertTrue(PushMacros::expand('{offer.url}', 'c1') === '{offer.url}', 'unknown macro stays verbatim');
assertTrue(PushMacros::expand('{}', '') === '{}', 'empty braces stay verbatim');
assertTrue(PushMacros::expand('{unclosed', '') === '{unclosed', 'unbalanced brace stays verbatim');
assertTrue(PushMacros::expand('no braces at all', 'c1') === 'no braces at all', 'plain text passes through');
assertTrue(PushMacros::expand('{Random=(3,3)}') === '3', 'Random with equal bounds is deterministic');
$many = PushMacros::expand('{a|b} {a|b} {a|b} {a|b} {a|b} {a|b} {a|b} {a|b}', '');
assertTrue(preg_match('/^([ab] ){7}[ab]$/', $many) === 1, 'many independent choices resolve in one text');

// ----------------------------------------------------------------------
// Segment SQL on an in-memory schema with only the touched columns.
// ----------------------------------------------------------------------
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec("
    CREATE TABLE clicks (id TEXT PRIMARY KEY, campaign_id INTEGER NOT NULL DEFAULT 0);
    CREATE TABLE push_subscriptions (
        id       INTEGER PRIMARY KEY AUTOINCREMENT,
        click_id TEXT,
        endpoint TEXT NOT NULL DEFAULT '',
        is_active INTEGER NOT NULL DEFAULT 1
    );
    CREATE TABLE conversions (
        id      INTEGER PRIMARY KEY AUTOINCREMENT,
        click_id TEXT NOT NULL,
        status  TEXT NOT NULL
    );
    CREATE TABLE push_messages (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        title TEXT NOT NULL DEFAULT '',
        text TEXT NOT NULL DEFAULT '',
        icon_url TEXT,
        link_url TEXT,
        kind TEXT NOT NULL DEFAULT 'manual',
        event TEXT,
        delay_seconds INTEGER NOT NULL DEFAULT 0,
        segment TEXT NOT NULL DEFAULT 'all',
        active INTEGER NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    );
    CREATE TABLE push_queue (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        message_id INTEGER NOT NULL,
        subscription_id INTEGER NOT NULL,
        run_at DATETIME,
        status TEXT NOT NULL DEFAULT 'pending',
        attempts INTEGER NOT NULL DEFAULT 0,
        last_code INTEGER
    );
");

$addSub = static function (string $clickId, int $active = 1) use ($pdo): int {
    $pdo->prepare("INSERT INTO push_subscriptions (click_id, endpoint, is_active) VALUES (?, ?, ?)")
        ->execute([$clickId, 'https://ep/' . $clickId, $active]);
    return (int) $pdo->lastInsertId();
};
$addConv = static function (string $clickId, string $status) use ($pdo): void {
    $pdo->prepare("INSERT INTO conversions (click_id, status) VALUES (?, ?)")->execute([$clickId, $status]);
};
$addMessage = static function (array $over = []) use ($pdo): array {
    $row = array_merge([
        'title' => 'T', 'text' => 'B', 'icon_url' => '', 'link_url' => '',
        'kind' => 'manual', 'event' => null, 'delay_seconds' => 0,
        'segment' => 'all', 'active' => 1,
    ], $over);
    $pdo->prepare("INSERT INTO push_messages (title, text, icon_url, link_url, kind, event, delay_seconds, segment, active)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$row['title'], $row['text'], $row['icon_url'], $row['link_url'], $row['kind'], $row['event'], $row['delay_seconds'], $row['segment'], $row['active']]);
    $row['id'] = (int) $pdo->lastInsertId();
    return $row;
};

// Conversion fixtures:
//   clickA: a hold-group conversion ('lead')            → reg1, no deposit
//   clickB: registration + sale                         → reg1dep1
//   clickC: no conversions at all                       → reg0
//   clickD: a bare 'sale' (deposit group, no reg)       → reg0 by definition
foreach (['A', 'B', 'C', 'D'] as $c) {
    $pdo->prepare("INSERT INTO clicks (id) VALUES (?)")->execute(['click' . $c]);
}
$addConv('clickA', 'lead');
$addConv('clickB', 'registration');
$addConv('clickB', 'sale');
$addConv('clickD', 'sale');
$subA = $addSub('clickA');
$subB = $addSub('clickB');
$subC = $addSub('clickC');
$subD = $addSub('clickD');
$subBDead = $addSub('clickB', 0); // dead endpoint — never enqueued

$queued = static function (int $messageId) use ($pdo): array {
    $stmt = $pdo->prepare("SELECT subscription_id FROM push_queue WHERE message_id = ? ORDER BY subscription_id");
    $stmt->execute([$messageId]);
    return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
};

// Segment condition fragment sanity.
assertTrue(orbitraPushSegmentConditionSql('all') === '1', 'segment all is an unconditional 1');
assertTrue(strpos(orbitraPushSegmentConditionSql('reg0'), 'NOT') !== false, 'segment reg0 negates the reg bucket');

// all → every ACTIVE subscription.
$msgAll = $addMessage(['segment' => 'all']);
assertTrue(orbitraPushEnqueueMessage($pdo, $msgAll) === 4, 'segment all queues every active subscriber (4)');
assertTrue($queued($msgAll['id']) === [$subA, $subB, $subC, $subD], 'segment all picked the right subscriptions');
assertTrue(!in_array($subBDead, $queued($msgAll['id']), true), 'dead subscription never enqueued');

// Dedup: the (message, subscription) pair is queued only once.
assertTrue(orbitraPushEnqueueMessage($pdo, $msgAll) === 0, 'second enqueue of the same message dedups to 0');

// reg0 → registrations-and-hold bucket is empty.
$msgReg0 = $addMessage(['segment' => 'reg0']);
assertTrue(orbitraPushEnqueueMessage($pdo, $msgReg0) === 2, 'segment reg0 queues 2 subscribers');
assertTrue($queued($msgReg0['id']) === [$subC, $subD], 'reg0 = no registration/hold conversions (bare sale stays reg0)');

// reg1dep0 → registered, not deposited.
$msgReg1Dep0 = $addMessage(['segment' => 'reg1dep0']);
assertTrue(orbitraPushEnqueueMessage($pdo, $msgReg1Dep0) === 1, 'segment reg1dep0 queues 1 subscriber');
assertTrue($queued($msgReg1Dep0['id']) === [$subA], 'reg1dep0 = the hold-only click');

// reg1dep1 → registered AND deposited.
$msgReg1Dep1 = $addMessage(['segment' => 'reg1dep1']);
assertTrue(orbitraPushEnqueueMessage($pdo, $msgReg1Dep1) === 1, 'segment reg1dep1 queues 1 subscriber');
assertTrue($queued($msgReg1Dep1['id']) === [$subB], 'reg1dep1 = the registration+sale click');

// Unknown segment falls back to all.
$msgWeird = $addMessage(['segment' => 'not-a-segment']);
orbitraPushEnqueueMessage($pdo, $msgWeird, 'also-bad');
assertTrue(count($queued($msgWeird['id'])) === 4, 'unknown segment falls back to all');

// Explicit override: queue reg1dep1 content for the reg0 audience.
$msgOverride = $addMessage(['segment' => 'reg1dep1']);
orbitraPushEnqueueMessage($pdo, $msgOverride, 'reg0');
assertTrue($queued($msgOverride['id']) === [$subC, $subD], 'segment override is honored at enqueue');

// Explicit subscription set (event-trigger path): honors segment + dedup.
$msgEvent = $addMessage(['segment' => 'reg1dep0', 'kind' => 'event', 'event' => 'lead', 'delay_seconds' => 600]);
assertTrue(orbitraPushEnqueueForSubscriptions($pdo, $msgEvent, [$subA, $subC, $subB]) === 1,
    'explicit set filtered by the message segment');
assertTrue($queued($msgEvent['id']) === [$subA], 'event trigger kept only the segment-matching subscription');
assertTrue(orbitraPushEnqueueForSubscriptions($pdo, $msgEvent, [$subA]) === 0, 're-trigger dedups');
$runAt = (string) $pdo->query("SELECT run_at FROM push_queue WHERE message_id = {$msgEvent['id']}")->fetchColumn();
assertTrue($runAt > date('Y-m-d H:i:s', time() + 540), 'delay_seconds lands in run_at (~now+600s)');
$inactive = $addMessage(['active' => 0]);
assertTrue(orbitraPushEnqueueForSubscriptions($pdo, $inactive, [$subA, $subB]) === 0,
    'inactive message is never enqueued by the trigger path');
assertTrue(orbitraPushEnqueueForSubscriptions($pdo, $msgEvent, []) === 0, 'empty subscription set enqueues nothing');

// Event message lookup.
assertTrue(count(orbitraPushEventMessages($pdo, 'lead')) === 1, 'orbitraPushEventMessages finds the lead event message');
assertTrue(count(orbitraPushEventMessages($pdo, 'sale')) === 0, 'no sale messages wired in this fixture');

echo $testPassed ? "\nALL TESTS PASSED\n" : "\nSOME TESTS FAILED\n";
exit($testPassed ? 0 : 1);
