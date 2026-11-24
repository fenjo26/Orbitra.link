<?php
// core/stream_action.php
//
// What a stream with schema "action" (or a landing of type "action") does to
// the visitor: 404, show text, show HTML, nothing, or hand them to another
// campaign. It ends the request, so an entry point logs its click first.
//
// Shared by the redirect router (index.php) and click.php. click.php used to
// have no notion of a stream action: selected, such a stream answered
// "200 OK, no redirect" instead of its 404.

/**
 * Perform a landing/stream "action" and end the request.
 *
 * The five behaviours a tracker is expected to offer, shared by both places an
 * action can be configured: a stream with schema "action", and a landing of type
 * "action". Before this they diverged — a stream understood two of them and
 * silently fell through to "Do nothing." for anything else, while a landing just
 * echoed its payload as HTML whatever you meant by it.
 *
 * @param string $type    not_found | show_text | show_html | do_nothing | to_campaign
 * @param string $payload text/HTML to show, or the target campaign id
 */
if (!function_exists('performTrackerAction')) {
    function performTrackerAction($type, $payload = '')
    {
        $type = trim((string) $type);
        // Anything unrecognised means an install from before action types existed,
        // where the payload was always treated as HTML.
        if ($type === '' || !in_array($type, ['not_found', 'show_text', 'show_html', 'do_nothing', 'to_campaign'], true)) {
            $type = $payload === '' ? 'do_nothing' : 'show_html';
        }

        switch ($type) {
            case 'not_found':
                http_response_code(404);
                header('Content-Type: text/html; charset=utf-8');
                echo '<!DOCTYPE html><html><head><title>404 Not Found</title></head><body><h1>404 Not Found</h1></body></html>';
                exit;

            case 'show_text':
                header('Content-Type: text/plain; charset=utf-8');
                echo $payload;
                exit;

            case 'show_html':
                header('Content-Type: text/html; charset=utf-8');
                echo $payload;
                exit;

            case 'to_campaign':
                // Keitaro hands the visitor to another campaign without a redirect.
                // Doing that here would mean re-entering this script, which declares
                // its functions at file scope and cannot be included twice, so this is
                // a redirect instead. The click is recorded in both campaigns either
                // way; the visitor just makes one extra hop.
                $targetId = (int) $payload;
                if ($targetId <= 0) {
                    http_response_code(500);
                    header('Content-Type: text/plain; charset=utf-8');
                    echo 'Action "to_campaign" has no target campaign id configured.';
                    exit;
                }
                $query = $_GET;
                unset($query['campaign'], $query['campaign_id'], $query['fallback_campaign_id'], $query['_lp']);
                $query['campaign_id'] = $targetId;
                header('Location: /?' . http_build_query($query), true, 302);
                exit;

            case 'do_nothing':
            default:
                // Nothing to render and nowhere to send them: answer without a body
                // so the browser leaves the page as it is.
                http_response_code(204);
                exit;
        }
    }
}
