// Single source of timezone options for the profile form and the setup
// wizard. Values are IANA IDs — the backend validates them with PHP's
// DateTimeZone, which accepts the same identifiers.
//
// Offsets are computed at runtime (Intl shortOffset) instead of being
// hardcoded, so labels follow DST instead of lying half of the year.

const ZONES = {
    Africa: [
        'Africa/Abidjan', 'Africa/Accra', 'Africa/Addis_Ababa', 'Africa/Algiers',
        'Africa/Cairo', 'Africa/Casablanca', 'Africa/Dakar', 'Africa/Dar_es_Salaam',
        'Africa/Djibouti', 'Africa/Freetown', 'Africa/Harare', 'Africa/Kampala',
        'Africa/Khartoum', 'Africa/Kinshasa', 'Africa/Lagos', 'Africa/Lome',
        'Africa/Luanda', 'Africa/Lusaka', 'Africa/Maputo', 'Africa/Mogadishu',
        'Africa/Nairobi', 'Africa/Ndjamena', 'Africa/Niamey', 'Africa/Ouagadougou',
        'Africa/Sao_Tome', 'Africa/Tripoli', 'Africa/Tunis',
    ],
    America: [
        'America/Anchorage', 'America/Argentina/Buenos_Aires', 'America/Asuncion',
        'America/Bogota', 'America/Caracas', 'America/Chicago', 'America/Costa_Rica',
        'America/Denver', 'America/Detroit', 'America/Edmonton',
        'America/Guatemala', 'America/Halifax', 'America/Havana', 'America/Jamaica',
        'America/La_Paz', 'America/Lima', 'America/Los_Angeles', 'America/Mexico_City',
        'America/Montevideo', 'America/New_York', 'America/Panama', 'America/Phoenix',
        'America/Port-au-Prince', 'America/Puerto_Rico', 'America/Santiago',
        'America/Santo_Domingo', 'America/Sao_Paulo', 'America/Tegucigalpa',
        'America/Tijuana', 'America/Toronto', 'America/Vancouver', 'America/Winnipeg',
    ],
    Asia: [
        'Asia/Almaty', 'Asia/Amman', 'Asia/Anadyr', 'Asia/Aqtau', 'Asia/Aqtobe',
        'Asia/Ashgabat', 'Asia/Baghdad', 'Asia/Baku', 'Asia/Bangkok', 'Asia/Beirut',
        'Asia/Bishkek', 'Asia/Brunei', 'Asia/Colombo', 'Asia/Damascus', 'Asia/Dhaka',
        'Asia/Dili', 'Asia/Doha', 'Asia/Dubai', 'Asia/Dushanbe', 'Asia/Gaza',
        'Asia/Ho_Chi_Minh', 'Asia/Hong_Kong', 'Asia/Hovd', 'Asia/Irkutsk',
        'Asia/Jakarta', 'Asia/Jerusalem', 'Asia/Kabul', 'Asia/Karachi',
        'Asia/Kathmandu', 'Asia/Kolkata', 'Asia/Krasnoyarsk', 'Asia/Kuala_Lumpur',
        'Asia/Kuwait', 'Asia/Macau', 'Asia/Magadan', 'Asia/Makassar', 'Asia/Manila',
        'Asia/Muscat', 'Asia/Nicosia', 'Asia/Novosibirsk', 'Asia/Omsk', 'Asia/Oral',
        'Asia/Phnom_Penh', 'Asia/Pontianak', 'Asia/Pyongyang', 'Asia/Qatar',
        'Asia/Qostanay', 'Asia/Qyzylorda', 'Asia/Riyadh', 'Asia/Sakhalin',
        'Asia/Samarkand', 'Asia/Seoul', 'Asia/Shanghai', 'Asia/Singapore',
        'Asia/Srednekolymsk', 'Asia/Taipei', 'Asia/Tashkent', 'Asia/Tbilisi',
        'Asia/Tehran', 'Asia/Thimphu', 'Asia/Tokyo', 'Asia/Tomsk', 'Asia/Ulaanbaatar',
        'Asia/Urumqi', 'Asia/Vientiane', 'Asia/Vladivostok', 'Asia/Yakutsk',
        'Asia/Yangon', 'Asia/Yekaterinburg', 'Asia/Yerevan',
    ],
    Atlantic: [
        'Atlantic/Azores', 'Atlantic/Cape_Verde', 'Atlantic/Reykjavik',
        'Atlantic/South_Georgia',
    ],
    Australia: [
        'Australia/Adelaide', 'Australia/Brisbane', 'Australia/Broken_Hill',
        'Australia/Darwin', 'Australia/Eucla', 'Australia/Hobart',
        'Australia/Lindeman', 'Australia/Lord_Howe', 'Australia/Melbourne',
        'Australia/Perth', 'Australia/Sydney',
    ],
    Europe: [
        'Europe/Amsterdam', 'Europe/Andorra', 'Europe/Astrakhan', 'Europe/Athens',
        'Europe/Belgrade', 'Europe/Berlin', 'Europe/Bratislava', 'Europe/Brussels',
        'Europe/Bucharest', 'Europe/Budapest', 'Europe/Chisinau', 'Europe/Copenhagen',
        'Europe/Dublin', 'Europe/Gibraltar', 'Europe/Helsinki', 'Europe/Kaliningrad',
        'Europe/Kirov', 'Europe/Kyiv', 'Europe/Lisbon', 'Europe/Ljubljana',
        'Europe/London', 'Europe/Luxembourg', 'Europe/Madrid', 'Europe/Malta',
        'Europe/Minsk', 'Europe/Monaco', 'Europe/Moscow', 'Europe/Oslo', 'Europe/Paris',
        'Europe/Podgorica', 'Europe/Prague', 'Europe/Riga', 'Europe/Rome',
        'Europe/Samara', 'Europe/San_Marino', 'Europe/Sarajevo', 'Europe/Saratov',
        'Europe/Simferopol', 'Europe/Skopje', 'Europe/Sofia', 'Europe/Stockholm',
        'Europe/Tallinn', 'Europe/Tirane', 'Europe/Ulyanovsk', 'Europe/Vaduz',
        'Europe/Vatican', 'Europe/Vienna', 'Europe/Vilnius', 'Europe/Volgograd',
        'Europe/Warsaw', 'Europe/Zagreb', 'Europe/Zaporozhye', 'Europe/Zurich',
    ],
    Indian: [
        'Indian/Antananarivo', 'Indian/Chagos', 'Indian/Christmas', 'Indian/Cocos',
        'Indian/Comoro', 'Indian/Kerguelen', 'Indian/Mahe', 'Indian/Maldives',
        'Indian/Mauritius', 'Indian/Mayotte', 'Indian/Reunion',
    ],
    Pacific: [
        'Pacific/Apia', 'Pacific/Auckland', 'Pacific/Bougainville', 'Pacific/Chatham',
        'Pacific/Chuuk', 'Pacific/Easter', 'Pacific/Efate', 'Pacific/Fakaofo',
        'Pacific/Fiji', 'Pacific/Funafuti', 'Pacific/Galapagos', 'Pacific/Gambier',
        'Pacific/Guadalcanal', 'Pacific/Guam', 'Pacific/Honolulu',
        'Pacific/Kanton', 'Pacific/Kiritimati', 'Pacific/Kosrae',
        'Pacific/Kwajalein', 'Pacific/Majuro', 'Pacific/Marquesas', 'Pacific/Midway',
        'Pacific/Nauru', 'Pacific/Niue', 'Pacific/Norfolk', 'Pacific/Noumea',
        'Pacific/Pago_Pago', 'Pacific/Palau', 'Pacific/Pitcairn', 'Pacific/Pohnpei',
        'Pacific/Port_Moresby', 'Pacific/Rarotonga', 'Pacific/Saipan', 'Pacific/Tahiti',
        'Pacific/Tarawa', 'Pacific/Tongatapu', 'Pacific/Wake',
    ],
};

// "GMT+3" / "GMT+3:30" from Intl shortOffset → "UTC+3" / "UTC+3:30".
// Exact "GMT" becomes plain "UTC".
const offsetLabel = (tz) => {
    try {
        const part = new Intl.DateTimeFormat('en-US', { timeZone: tz, timeZoneName: 'shortOffset' })
            .formatToParts(new Date())
            .find((p) => p.type === 'timeZoneName');
        if (!part) return '';
        return part.value === 'GMT' ? 'UTC' : part.value.replace('GMT', 'UTC');
    } catch {
        return '';
    }
};

export const TIMEZONE_GROUPS = [
    { region: 'UTC', zones: [{ value: 'UTC', label: 'UTC' }] },
    ...Object.entries(ZONES)
        .map(([region, zones]) => ({
            region,
            zones: zones
                .slice()
                .sort()
                .map((value) => {
                    const off = offsetLabel(value);
                    return { value, label: off ? `${value} (${off})` : value };
                }),
        }))
        .sort((a, b) => a.region.localeCompare(b.region)),
];

export const TIMEZONE_VALUES = new Set(
    TIMEZONE_GROUPS.flatMap((g) => g.zones.map((z) => z.value)),
);
