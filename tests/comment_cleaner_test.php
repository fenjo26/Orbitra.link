<?php
/**
 * tests/comment_cleaner_test.php
 *
 * Чистилка комментариев FB (этап 3), движок правил без сети:
 * нормализация (гомоглифы, leet, нулевая ширина), стоп-слова в режимах
 * «подстрока» и «целое слово», ссылки с белым списком и «разорванными»
 * доменами, контакты, только-эмодзи, повторы, минимальная длина, исключения
 * сильнее правил, свои комментарии, расписание (экспоненциальная задержка,
 * суточный интервал выключенных), автопауза по ошибкам и кодам лимита.
 *
 * Фикстуры — реальные спам-комментарии и ложные срабатывания (в т.ч. «кидай
 * мяч» из плана: у adset «кид» ловит подстрокой — мы обязаны целым словом).
 *
 * Запуск из корня проекта:
 *
 *     php tests/comment_cleaner_test.php
 */

require_once __DIR__ . '/../core/CommentCleaner.php';

$passed = 0;
$failed = 0;

function check(string $name, bool $condition, string $detail = ''): void
{
    global $passed, $failed;
    if ($condition) {
        $passed++;
        echo "  ok   $name\n";
    } else {
        $failed++;
        echo "  FAIL $name" . ($detail !== '' ? " — $detail" : '') . "\n";
    }
}

$ruleset = [
    'name' => 'test', 'mode' => 'rules', 'whole_word' => 0,
    'stopwords' => "scam\nкид",
    'regexes' => "/\\bwin\\s?big\\b/iu",
    'block_links' => 0, 'links_whitelist' => '', 'block_contacts' => 0,
    'emoji_only' => 0, 'char_repeats' => 0, 'min_length' => 0,
    'exceptions' => '',
];

// ---- Нормализация -----------------------------------------------------------

echo "normalization\n";

check('cyrillic homoglyphs fold to latin', CommentCleaner::normalize('sсаm') === 'scam', CommentCleaner::normalize('sсаm'));
check('leet decodes', CommentCleaner::normalize('fr33 b0nus') === 'free bonus', CommentCleaner::normalize('fr33 b0nus'));
check('zero-width and soft hyphen stripped', CommentCleaner::normalize("sc\xE2\x80\x8Ba\xC2\xADm") === 'scam', CommentCleaner::normalize("sc\xE2\x80\x8Ba\xC2\xADm"));
check('whitespace collapsed', CommentCleaner::normalize("  a\t b  \n c ") === 'a b c');

// ---- Стоп-слова ----------------------------------------------------------------

echo "stopwords\n";

check('plain stopword matches', CommentCleaner::evaluate($ruleset, 'This is SCAM!!!') === 'stopword:scam');
check('homoglyph stopword matches', CommentCleaner::evaluate($ruleset, 'это sсаm') === 'stopword:scam');

$ww = array_merge($ruleset, ['whole_word' => 1]);
check('whole word: «кидай мяч» is NOT a match', CommentCleaner::evaluate($ww, 'кидай мяч') === null);
check('whole word: «кид» alone matches', CommentCleaner::evaluate($ww, 'он кид') === 'stopword:кид');
check('substring mode (adset behavior): «кидай» matches', CommentCleaner::evaluate($ruleset, 'кидай мяч') === 'stopword:кид');

// ---- Регулярки ------------------------------------------------------------------

echo "regexes\n";

check('admin regex matches', CommentCleaner::evaluate($ruleset, 'WIN BIG today') === 'regex:1');
check('regex validated at save time', CommentCleaner::regexIsValid('/\d+/iu') === true && CommentCleaner::regexIsValid('[unclosed') === false);

// ---- Ссылки ------------------------------------------------------------------------

echo "links\n";

$links = array_merge($ruleset, ['block_links' => 1, 'links_whitelist' => "facebook.com\nour-brand.com"]);
check('plain URL blocked', CommentCleaner::evaluate($links, 'see https://spam.example.com/x') === 'links');
check('bare domain blocked', CommentCleaner::evaluate($links, 'best casino spamlink .com now') === 'links');
check('whitelisted domain passes', CommentCleaner::evaluate($links, 'see https://facebook.com/events') === null);
check('whitelisted subdomain passes', CommentCleaner::evaluate($links, 'https://shop.our-brand.com/x') === null);
check('whitelisted outer domain does NOT whitelist the host itself', CommentCleaner::evaluate($links, 'https://not-our-brand.com/') === 'links');
check('messenger deep link blocked', CommentCleaner::evaluate($links, 'join t.me/spam_channel') === 'links');
check('no link — no match', CommentCleaner::evaluate($links, 'great game honestly') === null);

// ---- Контакты ----------------------------------------------------------------------

echo "contacts\n";

$contacts = array_merge($ruleset, ['block_contacts' => 1]);
check('phone number blocked', CommentCleaner::evaluate($contacts, 'write me +30 694 123 45 67') === 'contacts');
check('email blocked', CommentCleaner::evaluate($contacts, 'me spam@example.com') === 'contacts');
check('telegram invitation blocked', CommentCleaner::evaluate($contacts, 'пиши в telegram @spam') === 'contacts');
check('dm invitation blocked', CommentCleaner::evaluate($contacts, 'DM me for bonus') === 'contacts');
check('normal text passes', CommentCleaner::evaluate($contacts, 'won 500 euros yesterday!') === null);
// Ложное срабатывание: год не телефон (4 цифры).
check('a year is not a phone number', CommentCleaner::evaluate($contacts, 'since 2024 best casino') === null);

// ---- Эмодзи / повторы / длина ----------------------------------------------------------

echo "emoji / repeats / length\n";

$emoji = array_merge($ruleset, ['emoji_only' => 1]);
check('emoji-only comment flagged', CommentCleaner::evaluate($emoji, '🔥🔥🔥 💰') === 'emoji_only');
check('text with emoji is NOT emoji-only', CommentCleaner::evaluate($emoji, 'won 🔥 50 bucks') === null);

$repeats = array_merge($ruleset, ['char_repeats' => 1]);
check('char repeat flagged', CommentCleaner::evaluate($repeats, 'cooooool!!!!') === 'repeats');
check('normal word passes', CommentCleaner::evaluate($repeats, 'cool!') === null);

$short = array_merge($ruleset, ['min_length' => 5]);
check('too short flagged', CommentCleaner::evaluate($short, 'ok') === 'min_length');
check('long enough passes', CommentCleaner::evaluate($short, 'nice game') === null);

// ---- Исключения и свои ------------------------------------------------------------

echo "exceptions and own comments\n";

$excepted = array_merge($ruleset, ['exceptions' => "id:100123\nname:Мария\ngreat bonus"]);
check('author id exception wins over rules', CommentCleaner::evaluate($excepted, 'this is scam', '100123', 'Someone', '999') === null);
check('author name exception wins (normalized)', CommentCleaner::evaluate($excepted, 'scam', '555', 'мария иванова', '999') === null);
check('phrase exception wins', CommentCleaner::evaluate($excepted, 'great bonus scam', '555', 'X', '999') === null);
check('page own comment never touched', CommentCleaner::evaluate($ruleset, 'scam scam scam', '999', 'The Page', '999') === null);
check('non-excepted spam still matches', CommentCleaner::evaluate($excepted, 'scam', '777', 'Spammer', '999') === 'stopword:scam');

// ---- Режим «все комментарии» --------------------------------------------------------

$all = array_merge($ruleset, ['mode' => 'all']);
check('mode all flags everything except exceptions', CommentCleaner::evaluate($all, 'nice comment') === 'mode:all');
check('mode all still respects exceptions', CommentCleaner::evaluate(array_merge($all, ['exceptions' => 'nice comment']), 'nice comment') === null);

// ---- Расписание ----------------------------------------------------------------------

echo "scheduling\n";

check('fresh post: 15 minutes', CommentCleaner::nextCheckDelayMinutes(0, true) === 15);
check('quiet streak doubles the delay', CommentCleaner::nextCheckDelayMinutes(1, true) === 30 && CommentCleaner::nextCheckDelayMinutes(2, true) === 60);
check('delay capped at 24h', CommentCleaner::nextCheckDelayMinutes(10, true) === 24 * 60);
check('inactive ad checked once a day', CommentCleaner::nextCheckDelayMinutes(0, false) === 24 * 60);

// ---- Автопауза -------------------------------------------------------------------------

echo "auto-pause\n";

check('no pause on a clean cycle', CommentCleaner::pauseReason(50, 0, []) === null);
check('no pause below the error threshold', CommentCleaner::pauseReason(50, 10, []) === null);
check('pause above 30% errors', CommentCleaner::pauseReason(50, 16, []) !== null);
check('tiny cycles do not pause (min 10 actions)', CommentCleaner::pauseReason(5, 5, []) === null);
check('rate-limit code pauses immediately', CommentCleaner::pauseReason(3, 0, [17]) !== null && CommentCleaner::pauseReason(3, 0, [613]) !== null);
check('a plain 404 does not rate-limit-pause', CommentCleaner::pauseReason(3, 0, [404]) === null);

echo "\n$passed passed, $failed failed\n";
exit($failed > 0 ? 1 : 0);
