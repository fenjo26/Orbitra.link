<?php
/**
 * kclient.php — KClient PHP, Keitaro-compatible.
 *
 * Download: open /kclient.php?download=1 in a browser (the file would otherwise
 * execute and return nothing) and save it next to your site's index.php.
 *
 * Place this file next to your site's index.php and put the tracking code from
 * the campaign's Tracking tab in the FIRST lines of index.php (before DOCTYPE):
 *
 *   <?php
 *   require_once dirname(__FILE__) . '/kclient.php';
 *   $client = new KClickClient('https://your-tracker.com', 'CAMPAIGN_TOKEN');
 *   $client->sendAllParams();
 *   $client->execute();
 *
 * The client talks to the tracker's Click API v3: one server-side request per
 * visit. The tracker resolves the campaign's streams (filters, weights,
 * landing/offer split) and answers with instructions this client carries out:
 *   - a Location header   → redirect (executeAndBreak / forceRedirectOffer)
 *   - a body              → stream content ("Show as HTML/text") — echo it, or
 *                           fetch it yourself with getContent()
 *   - neither             → "Do nothing": the visitor stays on your site
 *
 * The click id (subid) is kept in the PHP session and a cookie, so secondary
 * pages can restore it with restoreFromSession() / restoreFromQuery() without
 * creating a new click, and conversions can be posted back with the subid.
 */

// The tracker serves this file to be downloaded — without this guard a direct
// request would simply execute the class definition and return an empty page.
if (isset($_GET['download'])) {
    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="kclient.php"');
    header('Content-Length: ' . filesize(__FILE__));
    readfile(__FILE__);
    exit;
}

class KClickClient
{
    /** @var string tracker base URL, no trailing slash */
    private $apiBase;

    /** @var string campaign token (Campaign editor → Tracking tab) */
    private $token;

    /** @var array request parameters to pass through to the click */
    private $params = [];

    /** @var array|null decoded Click API response */
    private $response = null;

    /** @var string|null resolved Location header from the response */
    private $location = null;

    /** @var bool add force_redirect_offer=1 (redirect straight to the offer) */
    private $forceRedirect = false;

    /** @var bool keep the subid in the PHP session */
    private $useSessions = true;

    /** @var bool print the request log when debug() was called */
    private $debug = false;

    public function __construct($apiBase, $token)
    {
        $this->apiBase = rtrim((string) $apiBase, '/');
        $this->token = (string) $token;
    }

    // ------------------------------------------------------------------
    // Request building
    // ------------------------------------------------------------------

    /** Pass the current query string to the tracker (call before execute()). */
    public function sendAllParams()
    {
        foreach ($_GET as $key => $value) {
            if ($key === '' || $key[0] === '_') {
                continue; // client-internal params (_subid, _new, …)
            }
            $this->param($key, $value);
        }
        if (!empty($_SERVER['HTTP_REFERER'])) {
            $this->param('se_referrer', $_SERVER['HTTP_REFERER']);
        }
        if (!empty($_SERVER['HTTP_USER_AGENT'])) {
            $this->param('ua', $_SERVER['HTTP_USER_AGENT']);
        }
        return $this;
    }

    /** Pass a query-string-like parameter list: `param1=a&param2=b`. */
    public function params($queryString)
    {
        parse_str((string) $queryString, $parsed);
        foreach ((array) $parsed as $key => $value) {
            $this->param($key, $value);
        }
        return $this;
    }

    public function param($name, $value)
    {
        $this->params[(string) $name] = $value;
        return $this;
    }

    /** Use $keyword as the click keyword (Keitaro: source placeholder or title). */
    public function keyword($keyword)
    {
        return $this->param('keyword', $keyword);
    }

    /** Forward utm_* labels from the address bar. */
    public function sendUtmLabels()
    {
        foreach ($_GET as $key => $value) {
            if (strpos($key, 'utm_') === 0) {
                $this->param($key, $value);
            }
        }
        return $this;
    }

    /** Send the current page's URL as the referrer. */
    public function currentPageAsReferrer()
    {
        $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
        $host = $_SERVER['HTTP_HOST'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        if ($host !== '') {
            $this->param('se_referrer', $scheme . '://' . $host . $uri);
        }
        return $this;
    }

    /** If the stream selected an offer, redirect to it right away. */
    public function forceRedirectOffer()
    {
        $this->forceRedirect = true;
        return $this;
    }

    /** Stop storing the subid in the PHP session (restore needs it back). */
    public function disableSessions()
    {
        $this->useSessions = false;
        return $this;
    }

    public function debug()
    {
        $this->debug = true;
        return $this;
    }

    // ------------------------------------------------------------------
    // Restore (secondary pages — no new click)
    // ------------------------------------------------------------------

    /** Restore a previous visit from the session instead of creating a click. */
    public function restoreFromSession()
    {
        $this->startSession();
        if (!empty($_SESSION['orbitra_kclient_subid'])) {
            $this->clickId = $_SESSION['orbitra_kclient_subid'];
            if (!empty($_SESSION['orbitra_kclient_offer'])) {
                $this->offerUrl = $_SESSION['orbitra_kclient_offer'];
            }
            $this->restored = true;
        }
        return $this;
    }

    /** Restore from _subid/_token in the URL (links the client appends). */
    public function restoreFromQuery()
    {
        if (!empty($_GET['_subid'])) {
            $this->clickId = (string) $_GET['_subid'];
            $this->restored = true;
        }
        return $this;
    }

    // ------------------------------------------------------------------
    // Execution
    // ------------------------------------------------------------------

    /** Execute the campaign's instructions; the page keeps running (Do nothing). */
    public function execute()
    {
        $this->perform();
        if ($this->forceRedirect && $this->location !== null) {
            $this->redirect($this->location);
        }
        return $this;
    }

    /** Execute and stop the page when the tracker said redirect / show content. */
    public function executeAndBreak()
    {
        $this->perform();
        if ($this->location !== null) {
            $this->redirect($this->location);
        }
        if ($this->response !== null && $this->response['body'] !== null) {
            header('Content-Type: ' . ($this->response['contentType'] ?? 'text/html; charset=utf-8'));
            echo $this->response['body'];
            exit;
        }
        return $this;
    }

    /**
     * The offer URL of the selected stream, or $default when the tracker did
     * not resolve one. $opts with array('offer_id' => N) picks a specific offer
     * of the stream (the URL carries offer_id — the tracker honors it on the
     * landing→offer transition).
     */
    public function getOffer($opts = null, $default = null)
    {
        $this->perform();
        $url = $this->offerUrl;
        if (is_array($opts) && !empty($opts['offer_id'])) {
            $sep = strpos($url, '?') === false ? '?' : '&';
            $url = $url . $sep . 'offer_id=' . (int) $opts['offer_id'];
        }
        return $url !== null ? $url : $default;
    }

    /** Stream content ("Show as HTML/text") — banner blocks, content injection. */
    public function getContent()
    {
        $this->perform();
        return $this->response['body'] ?? null;
    }

    public function getBody()      { return $this->getContent(); }
    public function getSubid()     { $this->perform(); return $this->clickId; }
    public function getHeaders()   { $this->perform(); return $this->response['headers'] ?? []; }

    /** Bot / uniqueness verdicts are decided server-side on redirect visits;
     *  the Click API does not expose them yet — null means "not available". */
    public function isBot()        { return null; }
    public function isUnique($type = 'campaign') { return null; }

    /** Tracker-side conversion URL for this click (email pixels, thank-you pages). */
    public function getConversionUrl($status = 'lead', $payout = 0)
    {
        $this->perform();
        if ($this->clickId === null) {
            return null;
        }
        return $this->apiBase . '/pixel.gif?action=conversion'
            . '&subid=' . urlencode($this->clickId)
            . '&status=' . urlencode((string) $status)
            . '&payout=' . urlencode((string) $payout);
    }

    // ------------------------------------------------------------------
    // Internals
    // ------------------------------------------------------------------

    private $clickId = null;
    private $offerUrl = null;
    private $restored = false;
    private $executed = false;

    private function perform()
    {
        if ($this->executed || $this->restored) {
            return;
        }
        $this->executed = true;

        $query = [
            'token' => $this->token,
            'info'  => '1',
        ];
        if ($this->forceRedirect) {
            $query['force_redirect_offer'] = '1';
        }
        foreach ($this->params as $key => $value) {
            $query[(string) $key] = $value;
        }

        $url = $this->apiBase . '/click_api/v3?' . http_build_query($query);
        $raw = $this->httpGet($url);

        if ($this->debug) {
            echo '<!-- KClickClient request: ' . htmlspecialchars($url, ENT_QUOTES) . ' -->';
        }

        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($decoded)) {
            if ($this->debug) {
                echo '<!-- KClickClient: no response from tracker -->';
            }
            return;
        }
        $this->response = $decoded;

        foreach ((array) ($decoded['headers'] ?? []) as $header) {
            if (stripos((string) $header, 'Location:') === 0) {
                $this->location = trim(substr((string) $header, 9));
                break;
            }
        }

        $info = $decoded['info'] ?? [];
        if (!empty($info['sub_id'])) {
            $this->clickId = (string) $info['sub_id'];
            $this->startSession();
            $_SESSION['orbitra_kclient_subid'] = $this->clickId;
            @setcookie('orbitra_subid', $this->clickId, time() + 86400, '/');
        }
        // offer_link is the signed tracker-side transition (continues this click);
        // url is the raw offer template — both valid, the signed one is better.
        if (!empty($info['offer_link'])) {
            $this->offerUrl = (string) $info['offer_link'];
        } elseif (!empty($info['url'])) {
            $this->offerUrl = (string) $info['url'];
        }
        if ($this->offerUrl !== null) {
            $this->startSession();
            $_SESSION['orbitra_kclient_offer'] = $this->offerUrl;
        }
    }

    private function redirect($url)
    {
        header('Location: ' . $url, true, 302);
        exit;
    }

    private function startSession()
    {
        if (!$this->useSessions) {
            return;
        }
        if (session_status() === PHP_SESSION_NONE) {
            @session_start();
        }
    }

    /** @return string|null */
    private function httpGet($url)
    {
        $timeout = 8;
        if (function_exists('curl_init')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            curl_setopt($ch, CURLOPT_PROTOCOLS, CURLPROTO_HTTP | CURLPROTO_HTTPS);
            curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
            $body = curl_exec($ch);
            $err = curl_error($ch);
            // curl_close() is a no-op since PHP 8 and deprecated since 8.5 — skip it.
            if ($body === false) {
                if ($this->debug) {
                    echo '<!-- KClickClient curl error: ' . htmlspecialchars($err, ENT_QUOTES) . ' -->';
                }
                return null;
            }
            return (string) $body;
        }

        $ctx = stream_context_create(['http' => ['timeout' => $timeout]]);
        $result = @file_get_contents($url, false, $ctx);
        return is_string($result) ? $result : null;
    }
}
