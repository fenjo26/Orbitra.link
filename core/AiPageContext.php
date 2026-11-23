<?php
// core/AiPageContext.php
//
// Which campaign, landing or offer the user has open when they write to the
// assistant. The panel sends {kind, id}; the name is looked up here, behind
// the user's own scope, so the model can be told "campaign 12 «DE nutra»"
// without trusting a name the client could have made up — and without naming
// an entity the user is not allowed to see.

require_once __DIR__ . '/resource_access.php';

/**
 * ['kind', 'id', 'name'] for a page the user may see, or null: unknown kind,
 * bad id, nothing by that id, or outside the user's scope.
 */
function orbitraAiPageContext(PDO $pdo, $in): ?array
{
    if (!is_array($in)) {
        return null;
    }
    $kind = (string) ($in['kind'] ?? '');
    if (!in_array($kind, AiAgent::PAGE_KINDS, true)) {
        return null;
    }
    $id = (int) ($in['id'] ?? 0);
    if ($id <= 0) {
        return null;
    }
    $resource = $kind === 'campaign' ? 'campaigns' : ($kind === 'landing' ? 'landings' : 'offers');
    // Recompute rather than reuse the request cache: the answer must follow
    // the signed-in user, and this runs once per message.
    $scope = orbitraResourceScope($pdo, $resource, true);
    if ($scope !== null && !in_array($id, $scope['ids'], true)) {
        return null;
    }
    $st = $pdo->prepare("SELECT name FROM {$resource} WHERE id = ?");
    $st->execute([$id]);
    $name = $st->fetchColumn();
    $st->closeCursor();
    if (!is_string($name)) {
        return null;
    }
    return ['kind' => $kind, 'id' => $id, 'name' => mb_substr(trim((string) preg_replace('/\s+/u', ' ', $name)), 0, 120)];
}
