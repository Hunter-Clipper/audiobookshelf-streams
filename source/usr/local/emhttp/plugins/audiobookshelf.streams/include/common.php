<?php
/* Audiobookshelf Streams - settings storage and Audiobookshelf API client.
 *
 * Non-secret settings live in settings.cfg; the API token lives in its own
 * file so it is never parsed/echoed along with ordinary settings and never
 * sent to the browser.
 */

const ABSS_PLUGIN   = 'audiobookshelf.streams';
const ABSS_CFG_DIR  = '/boot/config/plugins/audiobookshelf.streams';
const ABSS_CFG      = ABSS_CFG_DIR . '/settings.cfg';
const ABSS_TOKEN    = ABSS_CFG_DIR . '/token';
const ABSS_USERS_CACHE = '/tmp/audiobookshelf.streams.users.json';

function abss_log($message) {
    openlog(ABSS_PLUGIN, LOG_PID, LOG_USER);
    syslog(LOG_WARNING, $message);
    closelog();
}

function abss_defaults() {
    return [
        'ABS_URL'         => '',
        'INSECURE_TLS'    => 'no',   // allow self-signed certificates
        'REFRESH_SECONDS' => '10',
        'PAUSED_AFTER'    => '45',   // seconds without a sync before "Paused"
        'SHOW_CONNECTED'  => 'yes',  // list users that are online but not playing
        'SHOW_DASHBOARD'  => 'yes',
    ];
}

function abss_settings() {
    $s = abss_defaults();
    if (is_readable(ABSS_CFG)) {
        $ini = @parse_ini_file(ABSS_CFG, false, INI_SCANNER_RAW);
        if (is_array($ini)) {
            foreach ($s as $k => $_) {
                if (isset($ini[$k])) $s[$k] = trim((string)$ini[$k], "\"' ");
            }
        }
    }
    return $s;
}

function abss_token() {
    if (!is_readable(ABSS_TOKEN)) return '';
    return trim((string)@file_get_contents(ABSS_TOKEN));
}

// Returns an error string, or '' on success.
function abss_validate_url($url) {
    if ($url === '') return 'Server URL is required.';
    if (!preg_match('#^https?://[^\s"\'<>]+$#i', $url)) return 'Server URL must start with http:// or https://';
    return '';
}

function abss_normalize_url($url) {
    return rtrim(trim($url), '/');
}

function abss_save_settings(array $s) {
    if (!is_dir(ABSS_CFG_DIR) && !@mkdir(ABSS_CFG_DIR, 0700, true)) return false;
    $out = '';
    foreach (abss_defaults() as $k => $_) {
        $v = isset($s[$k]) ? (string)$s[$k] : '';
        $v = str_replace(["\r", "\n", '"'], '', $v);
        $out .= $k . '="' . $v . '"' . "\n";
    }
    return @file_put_contents(ABSS_CFG, $out, LOCK_EX) !== false;
}

function abss_save_token($token) {
    if (!is_dir(ABSS_CFG_DIR) && !@mkdir(ABSS_CFG_DIR, 0700, true)) return false;
    $old = umask(0077);
    $ok = @file_put_contents(ABSS_TOKEN, trim($token) . "\n", LOCK_EX) !== false;
    umask($old);
    @chmod(ABSS_TOKEN, 0600);
    return $ok;
}

/* Calls the Audiobookshelf API.
 * Returns ['code' => int, 'body' => string, 'type' => string, 'date' => int|null, 'error' => string]
 * 'date' is the server's own clock (from the HTTP Date header) so session
 * ages are computed against ABS's time, not ours.
 */
function abss_api($method, $path, array $opts = []) {
    $url      = $opts['url']      ?? abss_normalize_url(abss_settings()['ABS_URL']);
    $token    = $opts['token']    ?? abss_token();
    $insecure = $opts['insecure'] ?? (abss_settings()['INSECURE_TLS'] === 'yes');
    $result = ['code' => 0, 'body' => '', 'type' => '', 'date' => null, 'error' => ''];

    if ($err = abss_validate_url($url)) { $result['error'] = $err; return $result; }
    if ($token === '')                  { $result['error'] = 'No API token configured.'; return $result; }

    $ch = curl_init($url . $path);
    $date = null;
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $token, 'Accept: application/json'],
        CURLOPT_SSL_VERIFYPEER => !$insecure,
        CURLOPT_SSL_VERIFYHOST => $insecure ? 0 : 2,
        CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$date) {
            if (stripos($line, 'date:') === 0) {
                $t = strtotime(trim(substr($line, 5)));
                if ($t) $date = $t;
            }
            return strlen($line);
        },
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POSTFIELDS, '{}');
        curl_setopt($ch, CURLOPT_HTTPHEADER, ['Authorization: Bearer ' . $token, 'Content-Type: application/json']);
    }
    $body = curl_exec($ch);
    if ($body === false) {
        $result['error'] = curl_error($ch) ?: 'Connection failed';
    } else {
        $result['body'] = $body;
        $result['code'] = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $result['type'] = (string)curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
        $result['date'] = $date;
    }
    curl_close($ch);
    return $result;
}

function abss_http_error_message($code) {
    if ($code === 401) return 'Audiobookshelf rejected the API token (401).';
    if ($code === 403) return 'The API token is valid but not an admin token (403). Streams need an admin user.';
    if ($code === 404) return 'Not found (404) - check the server URL.';
    return 'Audiobookshelf returned HTTP ' . $code . '.';
}

/* userId => username map. /api/users is admin-only and not needed on every
 * poll, so it is cached briefly. Only consulted for a session whose user is
 * not in the online list (e.g. a backgrounded mobile app still syncing).
 */
function abss_username_map($force = false) {
    if (!$force && is_readable(ABSS_USERS_CACHE) && (time() - filemtime(ABSS_USERS_CACHE)) < 300) {
        $c = json_decode((string)@file_get_contents(ABSS_USERS_CACHE), true);
        if (is_array($c)) return $c;
    }
    $r = abss_api('GET', '/api/users');
    $map = [];
    if ($r['code'] === 200) {
        $j = json_decode($r['body'], true);
        foreach (($j['users'] ?? []) as $u) {
            if (isset($u['id'], $u['username'])) $map[$u['id']] = $u['username'];
        }
        @file_put_contents(ABSS_USERS_CACHE, json_encode($map), LOCK_EX);
    }
    return $map;
}

/* Builds the data the dashboard tile renders. */
function abss_build_status() {
    $cfg = abss_settings();
    $out = ['ok' => false, 'configured' => false, 'error' => '', 'streams' => [], 'refresh' => (int)$cfg['REFRESH_SECONDS']];

    if (abss_validate_url(abss_normalize_url($cfg['ABS_URL'])) !== '' || abss_token() === '') {
        $out['error'] = 'Not configured yet.';
        return $out;
    }
    $out['configured'] = true;

    $r = abss_api('GET', '/api/users/online');
    if ($r['error'] !== '') { $out['error'] = $r['error']; return $out; }
    if ($r['code'] !== 200) { $out['error'] = abss_http_error_message($r['code']); return $out; }
    $j = json_decode($r['body'], true);
    if (!is_array($j)) { $out['error'] = 'Unexpected response from Audiobookshelf.'; return $out; }

    $now       = $r['date'] ?? time();
    $pausedAft = max(10, (int)$cfg['PAUSED_AFTER']);
    $online    = [];                       // userId => user entry
    foreach (($j['usersOnline'] ?? []) as $u) { if (isset($u['id'])) $online[$u['id']] = $u; }

    $rows = [];
    $withSession = [];
    foreach (($j['openSessions'] ?? []) as $s) {
        $uid = $s['userId'] ?? null;
        if ($uid === null) continue;
        $updated = isset($s['updatedAt']) ? (int)floor($s['updatedAt'] / 1000) : 0;
        $age     = max(0, $now - $updated);
        $isOnline = isset($online[$uid]);
        // A stale session for a user who is no longer connected is just an
        // abandoned record - not worth showing.
        if (!$isOnline && $age > $pausedAft) continue;
        $dur = (float)($s['duration'] ?? 0);
        $cur = (float)($s['currentTime'] ?? 0);
        $dev = $s['deviceInfo'] ?? [];
        $rows[] = [
            'user'       => $isOnline ? ($online[$uid]['username'] ?? '?') : null,
            'user_id'    => $uid,
            'state'      => $age <= $pausedAft ? 'listening' : 'paused',
            'session_id' => $s['id'] ?? '',
            'item_id'    => $s['libraryItemId'] ?? '',
            'title'      => $s['displayTitle'] ?? '',
            'author'     => $s['displayAuthor'] ?? '',
            'duration'   => $dur,
            'current'    => $cur,
            'progress'   => $dur > 0 ? min(100, max(0, round($cur / $dur * 100, 1))) : 0,
            'device'     => trim(($dev['deviceName'] ?? '') . (!empty($dev['clientName']) ? ' (' . $dev['clientName'] . ')' : '')),
            'age'        => $age,
        ];
        $withSession[$uid] = true;
    }

    $needNames = false;
    foreach ($rows as $row) { if ($row['user'] === null) { $needNames = true; break; } }
    $names = $needNames ? abss_username_map() : [];
    foreach ($rows as &$row) {
        if ($row['user'] === null) $row['user'] = $names[$row['user_id']] ?? 'unknown user';
    }
    unset($row);

    if ($cfg['SHOW_CONNECTED'] === 'yes') {
        foreach ($online as $uid => $u) {
            if (isset($withSession[$uid])) continue;
            $rows[] = ['user' => $u['username'] ?? '?', 'user_id' => $uid, 'state' => 'connected', 'session_id' => ''];
        }
    }

    $order = ['listening' => 0, 'paused' => 1, 'connected' => 2];
    usort($rows, function ($a, $b) use ($order) {
        return [$order[$a['state']], strtolower($a['user'])] <=> [$order[$b['state']], strtolower($b['user'])];
    });

    $out['ok'] = true;
    $out['streams'] = $rows;
    return $out;
}
