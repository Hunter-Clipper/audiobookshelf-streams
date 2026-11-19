<?php
/* Audiobookshelf Streams AJAX backend.
 *
 * Routes (action=...):
 *   status         GET   - who is listening / paused / connected right now
 *   cover          GET   - proxied cover image (keeps the API token server-side)
 *   load_settings  GET   - non-secret settings + whether a token is saved
 *   save_settings  POST  - save settings (CSRF)
 *   test           POST  - test a URL/token pair without saving (CSRF)
 *   stop           POST  - close a playback session on the ABS server (CSRF)
 *
 * The API token is never returned to the browser.
 */

require_once __DIR__ . '/csrf.php';
require_once __DIR__ . '/common.php';

function abss_json($data, $code = 200) {
    http_response_code($code);
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    echo json_encode($data);
    exit;
}

// Unraid's core JS appends its own csrf_token= to every $.post(); inside a
// Dashboard tile that copy can be empty and, because PHP's $_POST keeps only
// the LAST duplicate key, would shadow the real token. Scan the raw body for
// every csrf_token occurrence and accept any that matches.
function abss_csrf_ok() {
    $expected = abss_csrf_token();
    if ($expected === '') {
        abss_log('csrf check failed: no token could be read from var.ini');
        return false;
    }
    $raw = (string)@file_get_contents('php://input');
    foreach (explode('&', $raw) as $pair) {
        $eq = strpos($pair, '=');
        if ($eq === false || substr($pair, 0, $eq) !== 'csrf_token') continue;
        $val = urldecode(substr($pair, $eq + 1));
        if ($val !== '' && hash_equals($expected, $val)) return true;
    }
    $sent = isset($_POST['csrf_token']) ? (string)$_POST['csrf_token'] : '';
    if ($sent !== '' && hash_equals($expected, $sent)) return true;
    abss_log('csrf check failed: no posted csrf_token matched');
    return false;
}

function abss_require_post() {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') abss_json(['ok' => false, 'error' => 'POST required'], 405);
    if (!abss_csrf_ok()) abss_json(['ok' => false, 'error' => 'CSRF check failed - reload the page and try again.'], 403);
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

switch ($action) {
    case 'status':
        abss_json(abss_build_status());

    case 'cover':
        $id = $_GET['id'] ?? '';
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $id)) { http_response_code(400); exit; }
        $r = abss_api('GET', '/api/items/' . $id . '/cover?width=160&format=webp');
        if ($r['error'] !== '' || $r['code'] !== 200 || stripos($r['type'], 'image/') !== 0) {
            http_response_code(404);
            exit;
        }
        header('Content-Type: ' . $r['type']);
        header('Cache-Control: private, max-age=600');
        echo $r['body'];
        exit;

    case 'load_settings':
        $s = abss_settings();
        $s['TOKEN_SET'] = abss_token() !== '' ? 'yes' : 'no';
        abss_json(['ok' => true, 'settings' => $s]);

    case 'save_settings':
        abss_require_post();
        $url = abss_normalize_url((string)($_POST['ABS_URL'] ?? ''));
        // An empty URL is allowed on save (lets the user clear the config);
        // anything non-empty must be a valid http(s) URL.
        if ($url !== '' && ($err = abss_validate_url($url))) abss_json(['ok' => false, 'error' => $err]);
        $yn = function ($k) { return (($_POST[$k] ?? '') === 'yes') ? 'yes' : 'no'; };
        $refresh = (int)($_POST['REFRESH_SECONDS'] ?? 10);
        $refresh = min(300, max(3, $refresh));
        $paused = (int)($_POST['PAUSED_AFTER'] ?? 45);
        $paused = min(600, max(10, $paused));
        $ok = abss_save_settings([
            'ABS_URL'         => $url,
            'INSECURE_TLS'    => $yn('INSECURE_TLS'),
            'REFRESH_SECONDS' => (string)$refresh,
            'PAUSED_AFTER'    => (string)$paused,
            'SHOW_CONNECTED'  => $yn('SHOW_CONNECTED'),
            'SHOW_DASHBOARD'  => $yn('SHOW_DASHBOARD'),
        ]);
        // A blank token field means "keep the saved one"; 'CLEAR_TOKEN' removes it.
        $token = trim((string)($_POST['ABS_TOKEN'] ?? ''));
        if ($ok && $token !== '') {
            if (preg_match('/[\s"\']/', $token)) abss_json(['ok' => false, 'error' => 'The token contains invalid characters.']);
            $ok = abss_save_token($token);
        } elseif ($ok && ($_POST['CLEAR_TOKEN'] ?? '') === 'yes') {
            @unlink(ABSS_TOKEN);
        }
        @unlink(ABSS_USERS_CACHE);
        if (!$ok) {
            abss_log('failed to write settings under ' . ABSS_CFG_DIR);
            abss_json(['ok' => false, 'error' => 'Could not write settings to the flash drive.']);
        }
        abss_json(['ok' => true]);

    case 'test':
        abss_require_post();
        $url = abss_normalize_url((string)($_POST['ABS_URL'] ?? ''));
        if ($err = abss_validate_url($url)) abss_json(['ok' => false, 'error' => $err]);
        $token = trim((string)($_POST['ABS_TOKEN'] ?? ''));
        if ($token === '') $token = abss_token();
        $r = abss_api('GET', '/api/users/online', [
            'url' => $url, 'token' => $token, 'insecure' => (($_POST['INSECURE_TLS'] ?? '') === 'yes'),
        ]);
        if ($r['error'] !== '') abss_json(['ok' => false, 'error' => $r['error']]);
        if ($r['code'] !== 200) abss_json(['ok' => false, 'error' => abss_http_error_message($r['code'])]);
        $j = json_decode($r['body'], true);
        abss_json(['ok' => true, 'online' => is_array($j['usersOnline'] ?? null) ? count($j['usersOnline']) : 0]);

    case 'stop':
        abss_require_post();
        $sid = (string)($_POST['session_id'] ?? '');
        if (!preg_match('/^[A-Za-z0-9_-]{1,64}$/', $sid)) abss_json(['ok' => false, 'error' => 'Invalid session id.'], 400);
        $r = abss_api('POST', '/api/session/' . $sid . '/close');
        if ($r['error'] !== '') abss_json(['ok' => false, 'error' => $r['error']]);
        if ($r['code'] === 404) abss_json(['ok' => true, 'note' => 'Session was already closed.']);
        if ($r['code'] !== 200) {
            abss_log('stop session ' . $sid . ' failed: HTTP ' . $r['code']);
            abss_json(['ok' => false, 'error' => abss_http_error_message($r['code'])]);
        }
        abss_json(['ok' => true]);

    default:
        abss_json(['ok' => false, 'error' => 'Unknown action'], 400);
}
