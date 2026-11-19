<?php
/* Shared CSRF helper for Audiobookshelf Streams.
 *
 * The .page files emit the token; include/exec.php validates POSTed tokens
 * against it. Both use this single source so they can't disagree.
 *
 * The token is regexed out of Unraid's state file rather than read with
 * parse_ini_file('/var/local/emhttp/var.ini'): other keys in that file can
 * carry values the default INI scanner rejects, which would make the whole
 * parse fail and reject every request.
 */

function abss_csrf_token() {
    $ini = @file_get_contents('/var/local/emhttp/var.ini');
    if ($ini === false) return '';
    // Unraid writes values quoted, e.g. csrf_token="ABCD1234..."
    if (preg_match('/^\s*csrf_token\s*=\s*"?([A-Za-z0-9]+)"?/m', $ini, $m)) {
        return $m[1];
    }
    return '';
}
