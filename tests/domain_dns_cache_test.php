<?php
/**
 * Unit tests for DNS cache age (UTC timestamp parsing) and TTL selection.
 *
 * Run: php tests/domain_dns_cache_test.php
 */

require_once __DIR__ . '/../core/DomainDnsResolver.php';

$passed = 0;
$failed = 0;

function check(string $label, bool $cond, string $detail = ''): void
{
    global $passed, $failed;
    if ($cond) {
        $passed++;
        echo "  PASS  $label\n";
    } else {
        $failed++;
        echo "  FAIL  $label" . ($detail !== '' ? " ($detail)" : '') . "\n";
    }
}

$now = strtotime('2026-11-24 12:00:00 UTC');
$fiveMinAgoUtc = '2026-11-24 11:55:00'; // SQLite CURRENT_TIMESTAMP format (always UTC, no offset)

$origTz = date_default_timezone_get();

// 1. SQLite UTC timestamp must yield 300s age regardless of PHP's configured timezone.
foreach (['UTC', 'America/New_York', 'Europe/Kyiv', 'Asia/Tokyo'] as $tz) {
    date_default_timezone_set($tz);
    $age = orbitraDnsCheckedAtAge($fiveMinAgoUtc, $now);
    check("orbitraDnsCheckedAtAge in $tz parses SQLite CURRENT_TIMESTAMP as UTC", $age === 300, "got $age");
}

// 2. Explicit timezone offset is still respected.
date_default_timezone_set('America/New_York');
$ageExplicit = orbitraDnsCheckedAtAge('2026-11-24T13:55:00+02:00', $now);
check('orbitraDnsCheckedAtAge respects explicit offset', $ageExplicit === 300, "got $ageExplicit");

// 3. Empty / null / invalid values return 0.
check('orbitraDnsCheckedAtAge handles null', orbitraDnsCheckedAtAge(null, $now) === 0);
check('orbitraDnsCheckedAtAge handles empty string', orbitraDnsCheckedAtAge('', $now) === 0);
check('orbitraDnsCheckedAtAge handles garbage', orbitraDnsCheckedAtAge('not-a-date', $now) === 0);

// 4. TTL policy: active=1800s, fresh pending (<2h)=60s, old pending (>=2h)=1800s.
date_default_timezone_set('America/New_York');
$tenMinAgoUtc = '2026-11-24 11:50:00';
$threeHoursAgoUtc = '2026-11-24 09:00:00';

check('active domain TTL is 1800s (fresh)', orbitraDnsCacheTtlForStatus('active', $tenMinAgoUtc, $now) === 1800);
check('active domain TTL is 1800s (old)', orbitraDnsCacheTtlForStatus('active', $threeHoursAgoUtc, $now) === 1800);
check('fresh pending domain TTL is 60s', orbitraDnsCacheTtlForStatus('pending', $tenMinAgoUtc, $now) === 60);
check('fresh unknown domain TTL is 60s', orbitraDnsCacheTtlForStatus('unknown', $tenMinAgoUtc, $now) === 60);
check('old pending domain TTL falls back to 1800s', orbitraDnsCacheTtlForStatus('pending', $threeHoursAgoUtc, $now) === 1800);
check('pending domain without created_at falls back to 1800s', orbitraDnsCacheTtlForStatus('pending', null, $now) === 1800);

date_default_timezone_set($origTz);

echo "\nPassed: $passed, Failed: $failed\n";
exit($failed > 0 ? 1 : 0);
