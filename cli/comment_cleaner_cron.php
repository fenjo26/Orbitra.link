<?php
// cli/comment_cleaner_cron.php
//
// Чистилка комментариев FB (этап 3): раз в 10 минут читает новые комментарии
// под постами объявлений (comment_posts, пополняется синком креативов) и
// применяет набор правил (core/CommentCleaner.php): скрывает (обратимо, по
// умолчанию) или удаляет. Пробный режим у набора по умолчанию — пишет в
// журнал и ничего не делает на Graph.
//
// Автопауза: доля ошибок цикла >30% или Graph-лимит (4/17/32/613) → пауза
// (settings comment_cleaner_paused), баннер в панели + сообщение в Telegram.
// Посты без новых комментариев проверяются всё реже (экспоненциальная
// задержка), выключенные объявления — раз в сутки.
//
// Crontab (install.sh, маркер # orbitra-comments):
//   */10 * * * * php /var/www/orbitra/cli/comment_cleaner_cron.php --quiet \
//     >> /var/www/orbitra/var/logs/comments.log 2>&1

if (php_sapi_name() !== 'cli') {
    die('This script must be run from the command line.');
}

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../core/CommentCleaner.php';
require_once __DIR__ . '/../aggregator_engines/FacebookAdsEngine.php';

$options = getopt('', ['quiet']);
$isQuiet = isset($options['quiet']);

function cc_log(string $msg): void
{
    global $isQuiet;
    if (!$isQuiet) {
        echo '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    }
}

function cc_log_error(string $msg): void
{
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL);
}

$lockFile = __DIR__ . '/../var/locks/comment_cleaner.lock';
@mkdir(dirname($lockFile), 0775, true);
$fp = @fopen($lockFile, 'c+');
if ($fp === false || !flock($fp, LOCK_EX | LOCK_NB)) {
    exit(0); // уже работает
}

set_time_limit(540);

/** Пауза (settings) с причиной; пустая строка = работаем. */
$paused = '';
try {
    $stmt = $pdo->query("SELECT value FROM settings WHERE key = 'comment_cleaner_paused' LIMIT 1");
    $paused = trim((string) $stmt->fetchColumn());
} catch (\Throwable $e) {
}

if ($paused !== '') {
    cc_log("paused: $paused");
    flock($fp, LOCK_UN);
    fclose($fp);
    exit(0);
}

/**
 * Page-токены подключения, кэш в settings на 24 часа (токены страниц
 * долгоживущие, а /me/accounts — лишний вызов на каждый цикл).
 *
 * @return array<string,string> pageId => token
 */
function cc_page_tokens(PDO $pdo, int $connectionId, array $credentials): array
{
    $cacheKey = 'comment_pages_' . $connectionId;
    try {
        $stmt = $pdo->prepare("SELECT value, updated_at FROM settings WHERE key = ? LIMIT 1");
        $stmt->execute([$cacheKey]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && !empty($row['value'])) {
            $age = time() - (int) strtotime((string) $row['updated_at'] . ' UTC');
            $decoded = json_decode((string) $row['value'], true);
            if (is_array($decoded) && $age < 86400) {
                return $decoded;
            }
        }
    } catch (\Throwable $e) {
    }

    $pages = FacebookAdsEngine::fetchPages($credentials);
    $map = [];
    foreach ($pages as $page) {
        $map[(string) $page['id']] = (string) $page['token'];
    }
    try {
        $pdo->prepare("INSERT INTO settings (key, value, updated_at) VALUES (?, ?, datetime('now'))
                       ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime('now')")
            ->execute([$cacheKey, json_encode($map)]);
    } catch (\Throwable $e) {
    }
    return $map;
}

/** Поставить паузу + уведомить Telegram (чаты с ежедневной сводкой). */
function cc_pause(PDO $pdo, string $reason): void
{
    try {
        $pdo->prepare("INSERT INTO settings (key, value, updated_at) VALUES ('comment_cleaner_paused', ?, datetime('now'))
                       ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime('now')")
            ->execute([$reason]);
    } catch (\Throwable $e) {
    }
    try {
        if (!function_exists('sendTelegramNotification')) {
            require_once __DIR__ . '/../telegram_notify.php';
        }
        $tokenRow = $pdo->query("SELECT value FROM settings WHERE key = 'telegram_bot_token' LIMIT 1")->fetchColumn();
        if ($tokenRow) {
            foreach ($pdo->query("SELECT chat_id FROM telegram_bot_chats WHERE notify_daily = 1 AND is_active = 1")->fetchAll(PDO::FETCH_COLUMN) as $chatId) {
                sendTelegramNotification((string) $tokenRow, (string) $chatId, "⚠️ Orbitra: чистилка комментариев на паузе — {$reason}");
            }
        }
    } catch (\Throwable $e) {
        // Telegram — попытка уведомить, не критерий паузы.
    }
    cc_log_error("PAUSED: $reason");
}

$actions = 0;
$errors = 0;
$errorCodes = [];
$postsChecked = 0;
$commentsSeen = 0;
$hiddenDry = 0;
$hiddenDone = 0;

try {
    // Активные наборы правил.
    $rulesets = $pdo->query("SELECT * FROM comment_rulesets WHERE state = 'active'")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($rulesets as $ruleset) {
        $rulesetId = (int) $ruleset['id'];
        $connectionIds = json_decode((string) $ruleset['connection_ids'], true);
        if (!is_array($connectionIds) || $connectionIds === []) {
            continue;
        }
        $isDry = (int) $ruleset['dry_run'] === 1;

        foreach ($connectionIds as $connectionId) {
            $connectionId = (int) $connectionId;
            try {
                $stmt = $pdo->prepare("SELECT * FROM aggregator_connections WHERE id = ? AND engine IN ('facebook','facebook_ads') AND is_active = 1 LIMIT 1");
                $stmt->execute([$connectionId]);
                $conn = $stmt->fetch(PDO::FETCH_ASSOC);
            } catch (\Throwable $e) {
                $conn = false;
            }
            if (!$conn) {
                continue;
            }
            $credentials = json_decode((string) ($conn['credentials_json'] ?? '{}'), true) ?: [];

            // Свежие статусы объявлений — выключенные посты проверяем раз в сутки.
            $adActive = [];
            try {
                $stmt = $pdo->prepare("SELECT external_id, status FROM ad_entities WHERE platform = 'facebook' AND level = 'ad'");
                $stmt->execute();
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $adActive[(string) $row['external_id']] = (string) $row['status'];
                }
            } catch (\Throwable $e) {
            }

            // Посты к проверке.
            $stmt = $pdo->prepare("SELECT * FROM comment_posts WHERE connection_id = ? AND next_check_at <= datetime('now') ORDER BY next_check_at LIMIT 30");
            $stmt->execute([$connectionId]);
            $posts = $stmt->fetchAll(PDO::FETCH_ASSOC);
            if (!$posts) {
                continue;
            }

            $pageTokens = null; // лениво: нужен только если есть посты
            $updPost = $pdo->prepare("UPDATE comment_posts SET last_check_at = datetime('now'), next_check_at = ?, check_count = check_count + 1, no_new_count = ?, ad_active = ? WHERE id = ?");
            $insLog = $pdo->prepare("INSERT INTO comment_log (connection_id, ruleset_id, post_id, comment_id, author_id, author_name, text, matched_rule, action, status, error) VALUES (?,?,?,?,?,?,?,?,?,?,?)");

            foreach ($posts as $post) {
                if ($actions >= 200) {
                    break 3; // бюджет цикла по действиям на Graph
                }
                try {
                    if ($pageTokens === null) {
                        $pageTokens = cc_page_tokens($pdo, $connectionId, $credentials);
                    }
                    $pageId = (string) ($post['page_id'] !== '' ? $post['page_id'] : strtok((string) $post['post_id'], '_'));
                    if ($pageId === '' || !isset($pageTokens[$pageId])) {
                        // Страница недоступна токену — пост не наша забота.
                        $updPost->execute([date('Y-m-d H:i:s', time() + 24 * 3600), (int) $post['no_new_count'], (int) $post['ad_active'], $post['id']]);
                        continue;
                    }
                    $pageCredentials = array_merge($credentials, ['token' => $pageTokens[$pageId]]);

                    $sinceUnix = null;
                    if (!empty($post['last_check_at'])) {
                        $ts = strtotime((string) $post['last_check_at'] . ' UTC');
                        if ($ts !== false) {
                            $sinceUnix = $ts - 60; // минута на границу
                        }
                    }

                    $comments = FacebookAdsEngine::fetchPostComments($pageCredentials, (string) $post['post_id'], $sinceUnix);
                    $postsChecked++;
                    $commentsSeen += count($comments);
                    $newCount = 0;

                    $postAdActive = (int) $post['ad_active'] === 1
                        && (!isset($adActive[(string) $post['ad_id']]) || $adActive[(string) $post['ad_id']] === 'ACTIVE');

                    foreach ($comments as $comment) {
                        // Органика (пост не из рекламы?) — здесь каждый пост рекламный;
                        // флаг include_organic решает судьбу комментариев под
                        // выключенными объявлениями: без него они не чистятся.
                        if (!$postAdActive && (int) $ruleset['include_organic'] !== 1) {
                            continue;
                        }

                        $matched = CommentCleaner::evaluate(
                            $ruleset,
                            (string) $comment['message'],
                            (string) $comment['from_id'],
                            (string) $comment['from_name'],
                            $pageId
                        );
                        if ($matched === null) {
                            continue;
                        }
                        $newCount++;

                        $action = (string) $ruleset['action'];
                        if ($isDry) {
                            $insLog->execute([$connectionId, $rulesetId, $post['post_id'], $comment['id'], $comment['from_id'], $comment['from_name'], $comment['message'], $matched, $action, 'dry', null]);
                            $hiddenDry++;
                            continue;
                        }

                        $result = $action === 'delete'
                            ? FacebookAdsEngine::deleteComment($pageCredentials, (string) $comment['id'])
                            : FacebookAdsEngine::setCommentHidden($pageCredentials, (string) $comment['id'], true);
                        $actions++;
                        if (!empty($result['ok'])) {
                            $hiddenDone++;
                            $insLog->execute([$connectionId, $rulesetId, $post['post_id'], $comment['id'], $comment['from_id'], $comment['from_name'], $comment['message'], $matched, $action, 'ok', null]);
                        } else {
                            $errors++;
                            $code = (int) filter_var($result['error'] ?? '', FILTER_SANITIZE_NUMBER_INT);
                            if ($code > 0) {
                                $errorCodes[] = $code;
                            }
                            $insLog->execute([$connectionId, $rulesetId, $post['post_id'], $comment['id'], $comment['from_id'], $comment['from_name'], $comment['message'], $matched, $action, 'error', (string) ($result['error'] ?? 'unknown')]);
                        }
                    }

                    // Расписание: свежие комментарии сбрасывают задержку.
                    $noNew = $newCount > 0 ? 0 : (int) $post['no_new_count'] + 1;
                    $delay = CommentCleaner::nextCheckDelayMinutes($noNew, $postAdActive);
                    $updPost->execute([date('Y-m-d H:i:s', time() + $delay * 60), $noNew, $postAdActive ? 1 : 0, $post['id']]);
                } catch (\Throwable $e) {
                    $errors++;
                    $code = (int) filter_var($e->getMessage(), FILTER_SANITIZE_NUMBER_INT);
                    if ($code > 0) {
                        $errorCodes[] = $code;
                    }
                    cc_log_error('post ' . $post['post_id'] . ': ' . $e->getMessage());
                    // Ошибка поста не должна заморозить его навсегда.
                    try {
                        $pdo->prepare("UPDATE comment_posts SET last_check_at = datetime('now'), next_check_at = datetime('now', '+30 minutes'), check_count = check_count + 1 WHERE id = ?")
                            ->execute([$post['id']]);
                    } catch (\Throwable $e2) {
                    }
                }
            }
        }
    }

    // Автопауза по итогам цикла.
    $pauseReason = CommentCleaner::pauseReason($actions, $errors, $errorCodes);
    if ($pauseReason !== null) {
        cc_pause($pdo, $pauseReason);
    }

    // Тексты комментариев живут 60 дней (журнал действий остаётся без текста).
    try {
        $pdo->exec("UPDATE comment_log SET text = NULL WHERE created_at < datetime('now', '-60 days') AND text IS NOT NULL");
    } catch (\Throwable $e) {
    }
} catch (\Throwable $e) {
    cc_log_error('comment cleaner ERROR: ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
    exit(1);
} finally {
    try {
        $pdo->prepare("INSERT INTO settings (key, value, updated_at) VALUES ('comment_cleaner_last_run', ?, datetime('now'))
                       ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = datetime('now')")
            ->execute([gmdate('c')]);
    } catch (\Throwable $e) {
    }
    flock($fp, LOCK_UN);
    fclose($fp);
}

cc_log("comment_cleaner: posts=$postsChecked comments=$commentsSeen dry=$hiddenDry done=$hiddenDone errors=$errors");
