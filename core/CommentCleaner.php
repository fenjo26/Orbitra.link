<?php
// core/CommentCleaner.php
//
// Движок правил чистилки комментариев (этап 3). Чистая логика без сети —
// Graph-вызовы живут в FacebookAdsEngine и cli/comment_cleaner_cron.php.
//
// Порядок оценки (evaluate) — исключения сильнее правил, комментарии самой
// страницы не трогаем никогда:
//   1. автор = страница → null (даже не логируется);
//   2. исключения (id автора / имя / фраза) → null;
//   3. режим «все комментарии» → сработало без правила;
//   4. правила в фиксированном порядке: стоп-слова → регулярки → ссылки →
//      контакты → только эмодзи → повторы символов → минимальная длина.
//
// Нормализация (регистр, гомоглифы кириллица/греческий→латиница, leetspeak,
// символы нулевой ширины) применяется к стоп-словам, фразам исключений и
// регуляркам; ссылки и контакты проверяются по ИСХОДНОМУ тексту — «t . me /
// spamlink» читается человеком как ссылка и должна ловиться как ссылка.

class CommentCleaner
{
    /** Катастрофический бэктрекинг: вход регулярки урезается до этого. */
    public const REGEX_SUBJECT_LIMIT = 500;

    /** Кириллические/греческие буквы, неотличимые от латинских на глаз. */
    private const HOMOGLYPHS = [
        'а' => 'a', 'е' => 'e', 'о' => 'o', 'р' => 'p', 'с' => 'c', 'у' => 'y',
        'х' => 'x', 'і' => 'i', 'ј' => 'j', 'ѕ' => 's', 'һ' => 'h', 'ԁ' => 'd',
        'α' => 'a', 'ο' => 'o', 'ε' => 'e', 'ρ' => 'p', 'υ' => 'u', 'ν' => 'v',
        'Α' => 'a', 'Ο' => 'o', 'Ε' => 'e', 'Ρ' => 'p',
    ];

    /** Leet-замены, которые спамеры используют в стоп-словах. */
    private const LEET = [
        '0' => 'o', '1' => 'i', '3' => 'e', '4' => 'a', '5' => 's', '7' => 't',
        '@' => 'a', '$' => 's',
    ];

    /**
     * Нормализованный вид текста: lower + гомоглифы + leet + без нулевой
     * ширины/мягких переносов + схлопнутые пробелы.
     */
    public static function normalize(string $text): string
    {
        $text = strtr($text, self::HOMOGLYPHS);
        $text = mb_strtolower($text, 'UTF-8');
        $text = strtr($text, self::LEET);
        // Нулевая ширина/BOM/мягкий перенос ИСЧЕЗАЮТ (ими склеивают буквы,
        // разрезая стоп-слова), а не превращаются в пробел; неразрывный
        // пробел — единственный, кто становится пробелом.
        $text = preg_replace('/[\x{200B}-\x{200F}\x{202A}-\x{202E}\x{FEFF}\x{00AD}]/u', '', $text) ?? $text;
        $text = str_replace("\x{00A0}", ' ', $text);
        $text = preg_replace('/\s+/u', ' ', $text) ?? $text;
        return trim($text);
    }

    /**
     * Оценить комментарий по набору правил.
     *
     * @param array $ruleset строка comment_rulesets
     * @param string $text исходный текст комментария
     * @param string $authorId id автора
     * @param string $authorName имя автора
     * @param string $pageId id страницы-владельца поста
     * @return string|null метка сработавшего правила ('stopword:…', 'links',
     *         'contacts', 'emoji_only', 'repeats', 'min_length', 'regex:…',
     *         'mode:all') или null — не трогать
     */
    public static function evaluate(array $ruleset, string $text, string $authorId = '', string $authorName = '', string $pageId = ''): ?string
    {
        if ($authorId !== '' && $authorId === $pageId) {
            return null; // комментарий самой страницы — никогда
        }
        if (self::isExcepted($ruleset, $text, $authorId, $authorName)) {
            return null;
        }
        if ((string) ($ruleset['mode'] ?? 'rules') === 'all') {
            return 'mode:all'; // всё, кроме исключений и своих
        }

        $normalized = self::normalize($text);

        $stopword = self::matchStopword($ruleset, $normalized);
        if ($stopword !== null) {
            return 'stopword:' . $stopword;
        }
        $regex = self::matchRegex($ruleset, $normalized);
        if ($regex !== null) {
            return 'regex:' . $regex;
        }
        if (!empty($ruleset['block_links']) && self::hasBlockedLink($ruleset, $text)) {
            return 'links';
        }
        if (!empty($ruleset['block_contacts']) && self::looksLikeContact($text)) {
            return 'contacts';
        }
        if (!empty($ruleset['emoji_only']) && self::isEmojiOnly($text)) {
            return 'emoji_only';
        }
        if (!empty($ruleset['char_repeats']) && self::hasCharRepeats($text)) {
            return 'repeats';
        }
        $minLength = (int) ($ruleset['min_length'] ?? 0);
        if ($minLength > 0 && mb_strlen(trim($text)) < $minLength) {
            return 'min_length';
        }
        return null;
    }

    // ---- Исключения -----------------------------------------------------------

    /**
     * Исключения сильнее правил. Формат строк: «id:123» — id автора,
     * «name:Вася» — имя (contains, нормализованно), любое другое — фраза
     * (нормализованный contains).
     */
    private static function isExcepted(array $ruleset, string $text, string $authorId, string $authorName): bool
    {
        $exceptions = self::lines((string) ($ruleset['exceptions'] ?? ''));
        if ($exceptions === []) {
            return false;
        }
        $normText = self::normalize($text);
        $normName = self::normalize($authorName);
        foreach ($exceptions as $line) {
            $lineNorm = self::normalize($line);
            if ($lineNorm === '') {
                continue;
            }
            if (stripos($line, 'id:') === 0) {
                if (trim(substr($line, 3)) !== '' && trim(substr($line, 3)) === $authorId) {
                    return true;
                }
                continue;
            }
            if (stripos($line, 'name:') === 0) {
                $needle = self::normalize(trim(substr($line, 5)));
                if ($needle !== '' && $normName !== '' && strpos($normName, $needle) !== false) {
                    return true;
                }
                continue;
            }
            if ($lineNorm !== '' && strpos($normText, $lineNorm) !== false) {
                return true;
            }
        }
        return false;
    }

    // ---- Стоп-слова -------------------------------------------------------------

    /**
     * Первое сработавшее стоп-слово. Режим «целое слово» (whole_word) обёртывает
     * слово в \b…\bu — «кид» не сработает на «кидай мяч» (то, чего у adset нет).
     */
    private static function matchStopword(array $ruleset, string $normalizedText): ?string
    {
        $words = self::lines((string) ($ruleset['stopwords'] ?? ''));
        if ($words === []) {
            return null;
        }
        $wholeWord = !empty($ruleset['whole_word']);
        foreach ($words as $word) {
            $needle = self::normalize($word);
            if ($needle === '') {
                continue;
            }
            if ($wholeWord) {
                if (preg_match('/(?<![\p{L}\p{N}])' . preg_quote($needle, '/') . '(?![\p{L}\p{N}])/u', $normalizedText) === 1) {
                    return $needle;
                }
            } elseif (strpos($normalizedText, $needle) !== false) {
                return $needle;
            }
        }
        return null;
    }

    // ---- Регулярки ----------------------------------------------------------------

    /**
     * Первая сработавшая регулярка админа. Вход урезается до 500 символов и
     * backtrack_limit опускается на время вызова — это и есть «таймаут» на
     * катастрофический бэктрекинг: паттерн с вложенными квантификаторами
     * умирает по лимиту, а не вешает воркер.
     */
    private static function matchRegex(array $ruleset, string $normalizedText): ?string
    {
        $patterns = self::lines((string) ($ruleset['regexes'] ?? ''));
        if ($patterns === []) {
            return null;
        }
        $subject = mb_substr($normalizedText, 0, self::REGEX_SUBJECT_LIMIT);
        $oldLimit = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '20000');
        try {
            foreach ($patterns as $index => $pattern) {
                $pattern = trim($pattern);
                if ($pattern === '') {
                    continue;
                }
                // Разделитель уже в строке (админ пишет /…/iu целиком); без
                // него строка пропускается как невалидная — валидация при
                // сохранении не даёт такой попасть в набор.
                if (@preg_match($pattern, 'x') === false) {
                    continue;
                }
                if (preg_match($pattern, $subject) === 1) {
                    return (string) ($index + 1);
                }
            }
        } finally {
            ini_set('pcre.backtrack_limit', (string) $oldLimit);
        }
        return null;
    }

    /**
     * Паттерн — валидная регулярка с разделителем? Вызывается при сохранении
     * набора (админские регуляризируются только там).
     */
    public static function regexIsValid(string $pattern): bool
    {
        $pattern = trim($pattern);
        if ($pattern === '' || strlen($pattern) < 3) {
            return false;
        }
        return @preg_match($pattern, 'x') !== false;
    }

    // ---- Ссылки ------------------------------------------------------------------

    /** TLD, на которые ловим «голые» домены без схемы. */
    private const LINK_TLDS = 'com|net|org|io|me|ru|ua|xyz|info|biz|co|cc|tv|pro|top|club|online|site|store|link|app|dev|live|fun|win|bet|casino|xyz|vip|shop|monster|icu|rest|bar|space|pw|tk|ml|ga|cf|gq';

    public static function extractUrls(string $text): array
    {
        $urls = [];
        // «site .com» человек читает как ссылку — спамер ставит пробел перед
        // точкой. Сжимаем « .» в «.» только внутри детектора ссылок.
        $text = preg_replace('~\s+\.(?=[a-z]{2,}\b)~iu', '.', $text) ?? $text;
        if (preg_match_all('~(?:https?://|www\.)[^\s<>"\']+~i', $text, $m)) {
            foreach ($m[0] as $url) {
                $urls[] = $url;
            }
        }
        if (preg_match_all('~(?:^|\s)([a-z0-9][a-z0-9-]{1,61}\.(?:' . self::LINK_TLDS . '))(?:/[^\s<>"\']*|\b)~iu', $text, $m2)) {
            foreach ($m2[1] as $domain) {
                $urls[] = $domain;
            }
        }
        // Мессенджер-«глубокие» ссылки — та же ссылка для целей фильтра.
        if (preg_match_all('~(?:t\.me|wa\.me|api\.whatsapp\.com|chat\.whatsapp\.com)/[^\s<>"\']+~i', $text, $m3)) {
            foreach ($m3[0] as $url) {
                $urls[] = $url;
            }
        }
        return array_values(array_unique($urls));
    }

    /**
     * Есть ли ссылка вне белого списка доменов (проверка по исходному тексту).
     */
    public static function hasBlockedLink(array $ruleset, string $text): bool
    {
        $urls = self::extractUrls($text);
        if ($urls === []) {
            return false;
        }
        $whitelist = [];
        foreach (self::lines((string) ($ruleset['links_whitelist'] ?? '')) as $domain) {
            $domain = strtolower(preg_replace('~^https?://~i', '', trim($domain)));
            $domain = preg_replace('~/.*$~', '', $domain);
            if ($domain !== '') {
                $whitelist[$domain] = true;
                // поддомены белого домена тоже белые
                $whitelist['.' . $domain] = true;
            }
        }
        foreach ($urls as $url) {
            $host = strtolower(preg_replace('~^(?:https?://|www\.)~i', '', $url));
            $host = preg_replace('~/.*$~', '', $host);
            $host = preg_replace('~:\d+$~', '', $host);
            if ($host === '') {
                continue;
            }
            $allowed = isset($whitelist[$host]);
            if (!$allowed) {
                foreach (array_keys($whitelist) as $white) {
                    if ($white !== '' && $white[0] === '.' && substr($host, -strlen($white)) === $white) {
                        $allowed = true;
                        break;
                    }
                }
            }
            if (!$allowed) {
                return true;
            }
        }
        return false;
    }

    // ---- Контакты -----------------------------------------------------------------

    /**
     * Телефон / e-mail / прямое приглашение в мессенджер (по исходному тексту).
     */
    public static function looksLikeContact(string $text): bool
    {
        // Телефон: 7+ цифр с разрешёнными разделителями.
        if (preg_match('~(?:\+?\d[\d\s().\-]{6,}\d)~', $text) === 1) {
            $digits = preg_replace('~\D~', '', $text);
            if (strlen($digits) >= 7 && strlen($digits) <= 15) {
                return true;
            }
        }
        if (preg_match('~[a-z0-9._%+-]+@[a-z0-9.-]+\.[a-z]{2,}~i', $text) === 1) {
            return true;
        }
        $normalized = self::normalize($text);
        foreach (['whatsapp', 'whats app', 'telegram', 'viber', 'signal', 'напиши в лс', 'пиши в лс', 'пиши мне', 'напиши мне', 'dm me', 'text me', 'в личку', 'в личные'] as $marker) {
            if (strpos($normalized, self::normalize($marker)) !== false) {
                return true;
            }
        }
        return false;
    }

    // ---- Эмодзи / повторы --------------------------------------------------------

    /**
     * Только эмодзи (плюс пробелы/знаки)? Букв и цифр нет совсем.
     */
    public static function isEmojiOnly(string $text): bool
    {
        $stripped = preg_replace(
            '/[\x{1F000}-\x{1FAFF}\x{2600}-\x{27BF}\x{2B00}-\x{2BFF}\x{FE0F}\x{1F1E6}-\x{1F1FF}\x{2190}-\x{21FF}\x{2700}-\x{27BF}\s\p{P}]/u',
            '',
            $text
        );
        return $stripped !== null && $stripped === '';
    }

    /**
     * Повтор одного символа 4+ раз («sssss», «!!!!!»).
     */
    public static function hasCharRepeats(string $text): bool
    {
        return preg_match('/(.)\1{3,}/u', $text) === 1;
    }

    // ---- Расписание ----------------------------------------------------------------

    /**
     * Когда проверять пост снова. Есть новые комментарии → интервал
     * сбрасывается на базовый; пустых проверок подряд N → экспоненциальная
     * задержка 15м·2^N с потолком 24ч; объявление выключено — раз в сутки.
     */
    public static function nextCheckDelayMinutes(int $noNewCount, bool $adActive): int
    {
        if (!$adActive) {
            return 24 * 60;
        }
        $base = 15;
        $delay = (int) round($base * pow(2, min($noNewCount, 7)));
        return min($delay, 24 * 60);
    }

    /**
     * Наступила ли автопауза: доля ошибок цикла >30% (минимум 10 действий)
     * или Graph вернул код лимита (4, 17, 32, 613).
     *
     * @return string|null причина (текст для баннера) или null — работаем
     */
    public static function pauseReason(int $actions, int $errors, array $errorCodes): ?string
    {
        foreach ($errorCodes as $code) {
            if (in_array((int) $code, [4, 17, 32, 613], true)) {
                return 'Graph rate limit (code ' . (int) $code . ')';
            }
        }
        if ($actions >= 10 && $errors / max($actions, 1) > 0.30) {
            return 'error rate ' . round($errors / $actions * 100) . '% over the cycle';
        }
        return null;
    }

    // ---- Общее -----------------------------------------------------------------------

    /** Строки текстового поля настроек: split, trim, пустые наружу. */
    public static function lines(string $text): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $text) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }
        return $out;
    }
}
