<?php
// Shared device detection and targeting taxonomy for every click entry point.

if (!function_exists('orbitraNormalizeDeviceGroup')) {
    /** Normalize detector/import aliases to the three routing groups. */
    function orbitraNormalizeDeviceGroup(string $device): string
    {
        $device = strtolower(trim($device));

        return match ($device) {
            'mobile', 'smartphone', 'phablet', 'feature phone', 'feature_phone',
            'feature-phone', 'phone', 'iphone', 'ipod' => 'mobile',
            'tablet', 'tab', 'ipad', 'kindle', 'kindle fire', 'playbook', 'silk',
            'android tablet' => 'tablet',
            default => 'desktop',
        };
    }
}

if (!function_exists('orbitraDeviceGroupMatches')) {
    /** Match a visitor alias against a list of canonical or granular aliases. */
    function orbitraDeviceGroupMatches(string $visitorDevice, array $targetDevices): bool
    {
        $visitorGroup = orbitraNormalizeDeviceGroup($visitorDevice);
        foreach ($targetDevices as $targetDevice) {
            $targetDevice = trim((string) $targetDevice);
            if ($targetDevice !== '' && orbitraNormalizeDeviceGroup($targetDevice) === $visitorGroup) {
                return true;
            }
        }
        return false;
    }
}

if (!function_exists('orbitraDetectDeviceType')) {
    /** Detect the canonical Mobile / Tablet / Desktop type from a user agent. */
    function orbitraDetectDeviceType(string $userAgent): string
    {
        $ua = strtolower($userAgent);

        // Tablets must be checked first: Android tablets and iPadOS UAs often
        // contain tokens that otherwise resemble mobile phones or desktops.
        if (preg_match('/ipad|tablet|playbook|silk|kindle fire|kftt|kfot|kfjwi|kfjwa|kfsowi|kfdowi|sm-t|gt-p|sch-i800|nexus 7|nexus 10|xoom|macintosh.*mobile/', $ua)
            || (str_contains($ua, 'android') && !str_contains($ua, 'mobile'))) {
            return 'Tablet';
        }

        if (preg_match('/iphone|ipod|smartphone|phablet|feature[ _-]?phone|\bphone\b|iemobile|blackberry|bb\d+|meego|opera m(?:ob|in)i|palm|symbian|series(?:4|6)0|wap|windows ce|xda|xiino|mobile.+firefox|fennec|netfront|\bmobile\b/', $ua)
            || (str_contains($ua, 'android') && str_contains($ua, 'mobile'))) {
            return 'Mobile';
        }

        return 'Desktop';
    }
}
