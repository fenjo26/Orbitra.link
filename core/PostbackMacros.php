<?php
// core/PostbackMacros.php
//
// Macro handling shared by the outgoing S2S postback path.
//
// Why this exists: the traffic-source templates shipped in data/keitaro_*.json come
// straight from Keitaro, and 15 of them carry a status transform in the URL —
// {status: lead=reg sale=dep}. Without this the tracker would send the literal
// "{status: lead=reg sale=dep}" to the network, which is the class of bug the
// template rewrite was meant to remove.

if (!function_exists('orbitraApplyStatusTransform')) {

    /**
     * Resolve Keitaro's {status: from=to from=to} transform against an internal status.
     *
     * The transform maps the tracker's own status name to whatever vocabulary the
     * traffic source expects. A status the template does not list falls back to the
     * internal name, matching Keitaro's behaviour.
     *
     * Values are urlencoded because the result lands inside a query string, the same
     * way the plain {status} macro is encoded by the caller.
     */
    function orbitraApplyStatusTransform(string $url, string $internalStatus): string
    {
        if (stripos($url, '{status:') === false) {
            return $url;
        }

        return (string) preg_replace_callback(
            '/\{status:\s*([^}]*)\}/i',
            function (array $m) use ($internalStatus) {
                foreach (preg_split('/\s+/', trim($m[1])) as $pair) {
                    if ($pair === '' || strpos($pair, '=') === false) {
                        continue;
                    }
                    list($from, $to) = explode('=', $pair, 2);
                    if (strcasecmp(trim($from), $internalStatus) === 0) {
                        return urlencode(trim($to));
                    }
                }
                return urlencode($internalStatus);
            },
            $url
        );
    }
}
