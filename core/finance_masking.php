<?php
/**
 * Financial masking for restricted users.
 *
 * permissions_json may carry a 'finance' subkey — {show_costs, show_revenue,
 * show_payout}, booleans, missing = allowed — set by the UsersPage role
 * templates / permissions modal. Admins always see everything (the backend
 * ignores permissions for admins everywhere else too).
 *
 * Read endpoints pass their payload through orbitraMaskFinance(), which nulls
 * the hidden key families so the numbers never reach a restricted browser or
 * API client. Save handlers must call orbitraPreserveHiddenFinanceFields():
 * a restricted editor loads nulls, and saving that null back would wipe the
 * stored value.
 */

/**
 * Extract the finance flags from a decoded permissions_json array. Anything
 * missing — no 'finance' key at all, a non-array, an absent flag — means
 * "allowed", so pre-existing users keep seeing everything.
 */
function orbitraFinanceFlagsFromPermissions(?array $permissions): array
{
    $finance = $permissions['finance'] ?? null;
    if (!is_array($finance)) {
        return ['costs' => true, 'revenue' => true, 'payout' => true];
    }
    $allowed = static function (string $key) use ($finance): bool {
        return !array_key_exists($key, $finance) || $finance[$key] !== false;
    };
    return [
        'costs' => $allowed('show_costs'),
        'revenue' => $allowed('show_revenue'),
        'payout' => $allowed('show_payout'),
    ];
}

function orbitraAllFinanceVisible(array $flags): bool
{
    return $flags['costs'] && $flags['revenue'] && $flags['payout'];
}

/**
 * Does this row/array key belong to a hidden family?
 *
 * Cost family: cost, click_cost, costs, spend, cpc, cpv, cpa, cost_value — but NOT
 * cost_model (a CPC/CPM label, not an amount).
 * Revenue family: any prefix chain ending in revenue / profit / roi / epc
 * (revenue_confirmed, real_revenue, click_sale_revenue, real_roi, ...).
 * Payout family: payout, payouts, payout_value — but NOT payout_type.
 */
function orbitraFinanceKeyMasked(string $key, array $flags): bool
{
    if (orbitraAllFinanceVisible($flags)) {
        return false;
    }
    $k = strtolower(trim($key));
    if (!$flags['costs'] && (
        preg_match('/^(?:[a-z]+_)*cost$/', $k)
        || in_array($k, ['costs', 'spend', 'cpc', 'cpv', 'cpa', 'cost_value'], true)
    )) {
        return true;
    }
    if (!$flags['revenue'] && (
        preg_match('/^(?:[a-z]+_)*(?:revenue|profit|roi|epc)$/', $k)
        || preg_match('/^revenue(?:_[a-z]+)?$/', $k)
    )) {
        return true;
    }
    if (!$flags['payout'] && in_array($k, ['payout', 'payouts', 'payout_value'], true)) {
        return true;
    }
    return false;
}

/**
 * Recursively walk a response payload and null every key of a hidden family.
 * Scalars and non-array values pass through untouched.
 */
function orbitraMaskFinance($data, array $flags)
{
    if (!is_array($data)) {
        return $data;
    }
    $out = [];
    foreach ($data as $k => $v) {
        if (is_string($k) && orbitraFinanceKeyMasked($k, $flags)) {
            $out[$k] = null;
            continue;
        }
        $out[$k] = orbitraMaskFinance($v, $flags);
    }
    return $out;
}

/**
 * Finance flags for the current request's user: admins see everything; anyone
 * else is resolved from their stored permissions_json (one query, cached for
 * the request). A resolution failure falls back to "visible" — masking is a
 * display restriction, not a security boundary, and a broken SELECT must not
 * blank the panel for everyone.
 */
function orbitraFinanceFlagsForRequest(PDO $pdo, ?string $role, ?int $userId): array
{
    if ($role === 'admin' || $userId === null) {
        return ['costs' => true, 'revenue' => true, 'payout' => true];
    }
    try {
        $stmt = $pdo->prepare('SELECT permissions_json FROM users WHERE id = ?');
        $stmt->execute([$userId]);
        $raw = $stmt->fetchColumn();
        $permissions = is_string($raw) && $raw !== '' ? json_decode($raw, true) : null;
        return orbitraFinanceFlagsFromPermissions(is_array($permissions) ? $permissions : null);
    } catch (Throwable $e) {
        return ['costs' => true, 'revenue' => true, 'payout' => true];
    }
}

/**
 * A restricted editor loads masked (null) values; blind saving would write
 * those nulls over the stored amounts. For every field whose family is hidden,
 * restore the stored value from the existing row before the UPDATE runs.
 */
function orbitraPreserveHiddenFinanceFields(PDO $pdo, string $table, int $id, array $data, array $flags, array $fieldMap): array
{
    $hiddenFields = [];
    foreach ($fieldMap as $field => $family) {
        if (empty($flags[$family]) && array_key_exists($field, $data)) {
            $hiddenFields[] = $field;
        }
    }
    if (!$hiddenFields) {
        return $data;
    }
    try {
        $cols = implode(', ', array_map(static fn ($f) => '"' . $f . '"', $hiddenFields));
        $stmt = $pdo->prepare("SELECT $cols FROM \"$table\" WHERE id = ?");
        $stmt->execute([$id]);
        $stored = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($stored) {
            foreach ($hiddenFields as $field) {
                $data[$field] = $stored[$field];
            }
        }
    } catch (Throwable $e) {
        // Keep the incoming values — same "not a security boundary" stance.
    }
    return $data;
}
