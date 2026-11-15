<?php
/**
 * Read-only readers for database files in Keitaro's own formats, for an
 * operator who uploads such files himself (Settings → Geo databases). Orbitra
 * ships none of these files and downloads none of them.
 *
 * Two containers exist; both answer "which record covers this IP" with a
 * bucket index — the address space is cut into equal steps, each bucket lists
 * the ranges that touch it, and a table at the end of the file says where
 * every bucket starts. One lookup is two short seeks and one small read.
 *
 * GDBC — geo (country, region, city, ISP), IPv4 and IPv6:
 *   0   "GDBC"
 *   4   u32 version, u32 ?
 *   12  u32 v4 records, u32 v6 records, u32 header size (records start here)
 *   24  u32 regions offset, u32 cities offset, u32 ISP offset, u32 v4 index offset
 *   40  u32 v4 min, u32 v4 max, u32 v4 step, u32 v6 index offset
 *   56  16 bytes v6 min, 16 bytes v6 max, 16 bytes v6 step (big-endian)
 *   record (14 bytes): 2 bytes country, u32 region ptr, u32 city ptr, u32 ISP ptr
 *     — pointers are relative to their section; a string is 1 length byte + bytes
 *   index bucket: u32 byte length, then entries
 *     v4 entry (12): u32 start, u32 end, u32 record ptr (relative to header size)
 *     v6 entry (36): 16 bytes start, 16 bytes end, u32 record ptr
 *   file tail: u32 × v4 buckets (relative to the v4 index), then
 *              u32 × v6 buckets (relative to the v6 index)
 *
 * DBCA — a list of IPv4 ranges with a label (mobile operators: "megafon_ru";
 * bots: "<category>,<source>"):
 *   0   "DBCA", u32 version, u32 ?, u32 build time, u32 label count
 *   20  labels: u32 length + bytes each
 *   then buckets: u32 entry count, entries (12): u32 start, u32 end, u32 label ptr (absolute)
 *   then u32 × buckets (absolute offsets), then u32 min, u32 max, u32 step
 *
 * All integers little-endian unless noted. Every read is bounds-checked: a
 * truncated or foreign file yields "not found", never a warning on the click path.
 */
final class KeitaroDb
{
    /** @var array<string, array|null> parsed headers + open handles, per path */
    private static $open = [];

    /** 'geo' | 'list' | null — by the first four bytes, whatever the file is called. */
    public static function detect(string $path): ?string
    {
        if (!is_file($path)) {
            return null;
        }
        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            return null;
        }
        $magic = (string) fread($fp, 4);
        fclose($fp);
        if ($magic === 'GDBC') {
            return 'geo';
        }
        if ($magic === 'DBCA') {
            return 'list';
        }
        return null;
    }

    /**
     * What a DBCA file holds: 'bots' when its labels look like "<digit>,<source>",
     * otherwise 'carriers'. Null when the file is not a readable DBCA list.
     */
    public static function listKind(string $path): ?string
    {
        $db = self::openList($path);
        if ($db === null || $db['labels'] < 1) {
            return null;
        }
        $fp = $db['fp'];
        $offset = 20;
        $botLike = 0;
        $sampled = 0;
        for ($i = 0; $i < min(50, $db['labels']); $i++) {
            $label = self::readLabel($fp, $offset, $db['size']);
            if ($label === null) {
                return null;
            }
            $offset += 4 + strlen($label);
            $sampled++;
            if (preg_match('/^\d+,[A-Za-z0-9_.-]+$/', $label)) {
                $botLike++;
            }
        }
        return $botLike * 2 > $sampled ? 'bots' : 'carriers';
    }

    /**
     * @return array{country_code:string,region:string,city:string,isp:string}|null
     */
    public static function geoLookup(string $path, string $ip): ?array
    {
        $db = self::openGeo($path);
        if ($db === null) {
            return null;
        }
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        // IPv4-mapped IPv6 (::ffff:a.b.c.d) is an IPv4 visitor.
        if (strlen($packed) === 16 && strncmp($packed, "\0\0\0\0\0\0\0\0\0\0\xff\xff", 12) === 0) {
            $packed = substr($packed, 12);
        }
        $pointer = strlen($packed) === 4 ? self::geoPointerV4($db, $packed) : self::geoPointerV6($db, $packed);
        if ($pointer === null) {
            return null;
        }
        $record = self::read($db['fp'], $db['records'] + $pointer, 14, $db['size']);
        if ($record === null) {
            return null;
        }
        $ptr = unpack('Vregion/Vcity/Visp', substr($record, 2));
        $country = strtoupper(substr($record, 0, 2));
        return [
            'country_code' => preg_match('/^[A-Z]{2}$/', $country) ? $country : '',
            'region' => self::geoString($db, $db['regions'], $ptr['region'], $db['cities']),
            'city' => self::geoString($db, $db['cities'], $ptr['city'], $db['isps']),
            'isp' => self::geoString($db, $db['isps'], $ptr['isp'], $db['index4']),
        ];
    }

    /** The label of the range covering this IPv4 address, or null. */
    public static function listLookup(string $path, string $ip): ?string
    {
        $db = self::openList($path);
        if ($db === null) {
            return null;
        }
        $packed = @inet_pton($ip);
        if ($packed === false) {
            return null;
        }
        if (strlen($packed) === 16 && strncmp($packed, "\0\0\0\0\0\0\0\0\0\0\xff\xff", 12) === 0) {
            $packed = substr($packed, 12);
        }
        if (strlen($packed) !== 4) {
            return null; // the list formats carry IPv4 only
        }
        $addr = unpack('N', $packed)[1];
        if ($addr < $db['min'] || $addr > $db['max'] || $db['step'] < 1) {
            return null;
        }
        $bucket = intdiv($addr - $db['min'], $db['step']);
        if ($bucket >= $db['buckets']) {
            return null;
        }
        $raw = self::read($db['fp'], $db['table'] + 4 * $bucket, 4, $db['size']);
        if ($raw === null) {
            return null;
        }
        $offset = unpack('V', $raw)[1];
        $raw = self::read($db['fp'], $offset, 4, $db['size']);
        if ($raw === null) {
            return null;
        }
        $count = unpack('V', $raw)[1];
        if ($count < 1 || $count > 1000000) {
            return null;
        }
        $entries = self::read($db['fp'], $offset + 4, 12 * $count, $db['size']);
        if ($entries === null) {
            return null;
        }
        for ($i = 0; $i < $count; $i++) {
            $entry = unpack('Vstart/Vend/Vptr', $entries, 12 * $i);
            if ($addr >= $entry['start'] && $addr <= $entry['end']) {
                return self::readLabel($db['fp'], $entry['ptr'], $db['size']);
            }
            if ($entry['start'] > $addr) {
                break; // entries are sorted
            }
        }
        return null;
    }

    /** Close cached handles (tests, long-running workers after a file swap). */
    public static function reset(): void
    {
        foreach (self::$open as $db) {
            if (is_array($db) && is_resource($db['fp'] ?? null)) {
                fclose($db['fp']);
            }
        }
        self::$open = [];
    }

    // --- internals ---------------------------------------------------------------

    /**
     * One handle per path for the life of the process — a web request serves
     * one click. A long-running worker that swaps the file calls reset().
     */
    private static function cacheKey(string $path): string
    {
        return $path;
    }

    private static function openGeo(string $path): ?array
    {
        $key = 'geo|' . self::cacheKey($path);
        if (array_key_exists($key, self::$open)) {
            return self::$open[$key];
        }
        self::$open[$key] = null;
        if (!is_file($path)) {
            return null;
        }
        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            return null;
        }
        $size = (int) filesize($path);
        $header = (string) fread($fp, 104);
        if (strlen($header) < 104 || substr($header, 0, 4) !== 'GDBC') {
            fclose($fp);
            return null;
        }
        $h = unpack('Vn4/Vn6/Vrecords/Vregions/Vcities/Visps/Vindex4/Vmin4/Vmax4/Vstep4/Vindex6', $header, 12);
        $min6 = substr($header, 56, 16);
        $max6 = substr($header, 72, 16);
        $step6 = substr($header, 88, 16);
        $ordered = $h['records'] >= 104
            && $h['records'] + 14 * ($h['n4'] + $h['n6']) <= $h['regions']
            && $h['regions'] <= $h['cities'] && $h['cities'] <= $h['isps'] && $h['isps'] <= $h['index4']
            && $h['index4'] < $h['index6'] && $h['index6'] < $size
            && $h['step4'] > 0 && $h['max4'] >= $h['min4'];
        if (!$ordered) {
            fclose($fp);
            return null;
        }
        $buckets4 = intdiv($h['max4'] - $h['min4'], $h['step4']) + 1;
        // The v6 bucket count is not stored: it is whatever is left of the tail
        // after the v4 table. The v4 table starts with 0 (bucket 0 sits at the
        // very start of the v4 index) — use that to confirm the split.
        $buckets6 = (int) round(self::ratio(self::sub128($max6, $min6), $step6)) + 1;
        $found = false;
        foreach ([0, 1, -1, 2, -2] as $delta) {
            $candidate = $buckets6 + $delta;
            $table4 = $size - 4 * ($buckets4 + $candidate);
            if ($candidate < 1 || $table4 <= $h['index6']) {
                continue;
            }
            $probe = self::read($fp, $table4, 8, $size);
            $probe6 = self::read($fp, $size - 4 * $candidate, 4, $size);
            if ($probe !== null && $probe6 !== null
                && unpack('V', $probe)[1] === 0 && unpack('V', $probe, 4)[1] > 0
                && unpack('V', $probe6)[1] === 0) {
                $buckets6 = $candidate;
                $found = true;
                break;
            }
        }
        if (!$found) {
            fclose($fp);
            return null;
        }

        return self::$open[$key] = $h + [
            'fp' => $fp,
            'size' => $size,
            'buckets4' => $buckets4,
            'buckets6' => $buckets6,
            'table4' => $size - 4 * ($buckets4 + $buckets6),
            'table6' => $size - 4 * $buckets6,
            'min6' => $min6,
            'max6' => $max6,
            'step6' => $step6,
        ];
    }

    private static function openList(string $path): ?array
    {
        $key = 'list|' . self::cacheKey($path);
        if (array_key_exists($key, self::$open)) {
            return self::$open[$key];
        }
        self::$open[$key] = null;
        if (!is_file($path)) {
            return null;
        }
        $fp = @fopen($path, 'rb');
        if ($fp === false) {
            return null;
        }
        $size = (int) filesize($path);
        $header = (string) fread($fp, 20);
        $tail = $size >= 32 ? self::read($fp, $size - 12, 12, $size) : null;
        if (strlen($header) < 20 || substr($header, 0, 4) !== 'DBCA' || $tail === null) {
            fclose($fp);
            return null;
        }
        $t = unpack('Vmin/Vmax/Vstep', $tail);
        if ($t['step'] < 1 || $t['max'] < $t['min']) {
            fclose($fp);
            return null;
        }
        // The tail's "max" is the end of the last bucket, so the division is exact.
        $buckets = intdiv($t['max'] - $t['min'], $t['step']);
        if (($t['max'] - $t['min']) % $t['step'] !== 0) {
            $buckets++;
        }
        $table = $size - 12 - 4 * $buckets;
        if ($buckets < 1 || $table < 20) {
            fclose($fp);
            return null;
        }
        return self::$open[$key] = $t + [
            'fp' => $fp,
            'size' => $size,
            'labels' => unpack('V', $header, 16)[1],
            'buckets' => $buckets,
            'table' => $table,
        ];
    }

    private static function read($fp, int $offset, int $length, int $size): ?string
    {
        if ($offset < 0 || $length < 1 || $offset + $length > $size) {
            return null;
        }
        if (fseek($fp, $offset) !== 0) {
            return null;
        }
        $data = fread($fp, $length);
        return is_string($data) && strlen($data) === $length ? $data : null;
    }

    private static function readLabel($fp, int $offset, int $size): ?string
    {
        $raw = self::read($fp, $offset, 4, $size);
        if ($raw === null) {
            return null;
        }
        $length = unpack('V', $raw)[1];
        if ($length === 0) {
            return '';
        }
        if ($length > 255) {
            return null;
        }
        return self::read($fp, $offset + 4, $length, $size);
    }

    private static function geoString(array $db, int $section, int $pointer, int $sectionEnd): string
    {
        $offset = $section + $pointer;
        if ($offset >= $sectionEnd) {
            return '';
        }
        $raw = self::read($db['fp'], $offset, 1, $db['size']);
        if ($raw === null) {
            return '';
        }
        $length = ord($raw);
        if ($length === 0 || $offset + 1 + $length > $sectionEnd) {
            return '';
        }
        $value = self::read($db['fp'], $offset + 1, $length, $db['size']);
        if ($value === null) {
            return '';
        }
        // Names are UTF-8; anything else would break json_encode of the click row.
        return mb_check_encoding($value, 'UTF-8') ? trim($value) : '';
    }

    private static function geoPointerV4(array $db, string $packed): ?int
    {
        $addr = unpack('N', $packed)[1];
        if ($addr < $db['min4'] || $addr > $db['max4']) {
            return null;
        }
        $bucket = intdiv($addr - $db['min4'], $db['step4']);
        $entries = self::geoBucket($db, $db['table4'], $db['index4'], $bucket, $db['buckets4'], 12, $db['index6']);
        if ($entries === null) {
            return null;
        }
        // Sorted by start: binary search for the last entry starting at or before the address.
        $low = 0;
        $high = intdiv(strlen($entries), 12) - 1;
        $hit = null;
        while ($low <= $high) {
            $mid = ($low + $high) >> 1;
            $start = unpack('V', $entries, 12 * $mid)[1];
            if ($start <= $addr) {
                $hit = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }
        if ($hit === null) {
            return null;
        }
        $entry = unpack('Vstart/Vend/Vptr', $entries, 12 * $hit);
        return $addr <= $entry['end'] ? $entry['ptr'] : null;
    }

    private static function geoPointerV6(array $db, string $packed): ?int
    {
        if (strcmp($packed, $db['min6']) < 0 || strcmp($packed, $db['max6']) > 0) {
            return null;
        }
        // 128-bit division without GMP: a float estimate of the bucket is off
        // by at most one, and a range that crosses buckets is listed in each of
        // them, so the estimate and its two neighbours cover every case.
        $estimate = (int) floor(self::ratio(self::sub128($packed, $db['min6']), $db['step6']));
        foreach ([0, -1, 1] as $delta) {
            $bucket = $estimate + $delta;
            if ($bucket < 0 || $bucket >= $db['buckets6']) {
                continue;
            }
            $entries = self::geoBucket($db, $db['table6'], $db['index6'], $bucket, $db['buckets6'], 36, $db['table4']);
            if ($entries === null) {
                continue;
            }
            $low = 0;
            $high = intdiv(strlen($entries), 36) - 1;
            $hit = null;
            while ($low <= $high) {
                $mid = ($low + $high) >> 1;
                if (strcmp(substr($entries, 36 * $mid, 16), $packed) <= 0) {
                    $hit = $mid;
                    $low = $mid + 1;
                } else {
                    $high = $mid - 1;
                }
            }
            if ($hit !== null && strcmp($packed, substr($entries, 36 * $hit + 16, 16)) <= 0) {
                return unpack('V', $entries, 36 * $hit + 32)[1];
            }
        }
        return null;
    }

    private static function geoBucket(array $db, int $table, int $index, int $bucket, int $buckets, int $entrySize, int $indexEnd): ?string
    {
        if ($bucket < 0 || $bucket >= $buckets) {
            return null;
        }
        $raw = self::read($db['fp'], $table + 4 * $bucket, 4, $db['size']);
        if ($raw === null) {
            return null;
        }
        $offset = $index + unpack('V', $raw)[1];
        $raw = self::read($db['fp'], $offset, 4, $db['size']);
        if ($raw === null) {
            return null;
        }
        $length = unpack('V', $raw)[1];
        if ($length < $entrySize || $length % $entrySize !== 0 || $offset + 4 + $length > $indexEnd) {
            return null;
        }
        return self::read($db['fp'], $offset + 4, $length, $db['size']);
    }

    /** a − b for two 16-byte big-endian numbers (a ≥ b). */
    private static function sub128(string $a, string $b): string
    {
        $out = '';
        $borrow = 0;
        for ($i = 15; $i >= 0; $i--) {
            $diff = ord($a[$i]) - ord($b[$i]) - $borrow;
            $borrow = $diff < 0 ? 1 : 0;
            $out = chr($diff & 0xff) . $out;
        }
        return $out;
    }

    /** a / b as a float for two 16-byte big-endian numbers. */
    private static function ratio(string $a, string $b): float
    {
        $toFloat = static function (string $bytes): float {
            $value = 0.0;
            for ($i = 0; $i < 16; $i++) {
                $value = $value * 256.0 + ord($bytes[$i]);
            }
            return $value;
        };
        $divisor = $toFloat($b);
        return $divisor > 0.0 ? $toFloat($a) / $divisor : 0.0;
    }
}
