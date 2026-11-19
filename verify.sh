#!/bin/bash
# Verifies audiobookshelf.streams.plg is safe to publish. Run this before every
# push - CI (.github/workflows/verify.yml) also runs it on every push to
# main as a backstop. Catches, automatically, the two real bugs shipped by
# hand-checking alone: a corrupted build (stray "&"/"{{FILES}}") and inline
# files missing the SHA256 Unraid's installer needs to actually overwrite
# them on update.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
PLG="$ROOT/audiobookshelf.streams.plg"
fail=0

note() { echo "-- $1"; }
err() { echo "FAIL: $1" >&2; fail=1; }

command -v php >/dev/null 2>&1 || { echo "php is required to run verify.sh" >&2; exit 1; }

note "regenerating from source/ and checking the result matches what's committed"
"$ROOT/build.sh" >/dev/null
if command -v git >/dev/null 2>&1 && git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1; then
  if ! git -C "$ROOT" diff --quiet -- audiobookshelf.streams.plg; then
    err "audiobookshelf.streams.plg differs from build.sh's output for the committed source - regenerate and commit it (run ./build.sh)"
  else
    note "OK: committed .plg matches source/ + template"
  fi
fi

note "checking for a leftover {{FILES}} placeholder"
if grep -q '{{FILES}}' "$PLG"; then
  err "{{FILES}} placeholder found unexpanded in the .plg"
else
  note "OK: no leftover placeholder"
fi

note "validating XML the same way Unraid's plugin manager parses it (simplexml + LIBXML_NOCDATA)"
php -r '
$xml = @simplexml_load_file($argv[1], NULL, LIBXML_NOCDATA);
if (!$xml) {
  foreach (libxml_get_errors() as $e) fwrite(STDERR, $e->message);
  exit(1);
}
$attrs = $xml->attributes();
foreach (["name", "version", "pluginURL", "min"] as $req) {
  if (!isset($attrs[$req]) || (string)$attrs[$req] === "") {
    fwrite(STDERR, "missing required PLUGIN attribute: $req\n");
    exit(1);
  }
}
' "$PLG" || err "XML validation failed"
note "OK: XML parses, required attributes present"

note "checking every named FILE has a self-consistent SHA256 (what Unraid's installer needs to detect changes on update)"
php -r '
$xml = simplexml_load_file($argv[1], NULL, LIBXML_NOCDATA);
$bad = 0;
foreach ($xml->FILE as $file) {
  $name = (string)$file->attributes()->Name;
  if (!$name) continue; // install/remove script blocks have no Name - not subject to the skip-if-exists rule
  $sha256 = (string)$file->attributes()->SHA256;
  if ($sha256 === "") { fwrite(STDERR, "missing SHA256: $name\n"); $bad = 1; continue; }
  $expected = hash("sha256", trim((string)$file->INLINE) . PHP_EOL);
  if ($expected !== $sha256) { fwrite(STDERR, "SHA256 mismatch: $name\n"); $bad = 1; }
}
exit($bad);
' "$PLG" || err "SHA256 check failed"
note "OK: every named FILE has a correct SHA256"

note "php -l on every embedded PHP/.page file"
php -r '
$xml = simplexml_load_file($argv[1], NULL, LIBXML_NOCDATA);
$bad = 0;
foreach ($xml->FILE as $file) {
  $name = (string)$file->attributes()->Name;
  if (!preg_match("/\.(php|page)$/", $name)) continue;
  $tmp = tempnam(sys_get_temp_dir(), "abss-lint-");
  file_put_contents($tmp, trim((string)$file->INLINE) . PHP_EOL);
  exec("php -l " . escapeshellarg($tmp) . " 2>&1", $out, $rc);
  if ($rc !== 0) { fwrite(STDERR, "$name:\n" . implode("\n", $out) . "\n"); $bad = 1; }
  unlink($tmp);
}
exit($bad);
' "$PLG" || err "php -l failed on one or more files"
note "OK: all PHP/.page files lint clean"

note "confirming the version was bumped vs. the last committed .plg (HEAD)"
if command -v git >/dev/null 2>&1 && git -C "$ROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1 \
   && git -C "$ROOT" cat-file -e HEAD:audiobookshelf.streams.plg 2>/dev/null; then
  old_tmp="$(mktemp)"
  git -C "$ROOT" show HEAD:audiobookshelf.streams.plg > "$old_tmp"
  # Parsed via simplexml (not grep) so this reads the resolved version=
  # attribute value, not a raw text match that could hit the unresolved
  # "&version;" entity reference on the <PLUGIN> line instead of the actual
  # value declared in the DOCTYPE.
  old_version=$(php -r '$x=@simplexml_load_file($argv[1],NULL,LIBXML_NOCDATA); echo $x ? (string)$x->attributes()->version : "";' "$old_tmp" || true)
  new_version=$(php -r '$x=@simplexml_load_file($argv[1],NULL,LIBXML_NOCDATA); echo $x ? (string)$x->attributes()->version : "";' "$PLG" || true)
  rm -f "$old_tmp"
  if [ -n "$old_version" ] && [ "$old_version" = "$new_version" ] && ! git -C "$ROOT" diff --quiet -- source; then
    err "source/ changed but version is still $new_version (same as HEAD) - Unraid's update check (strcmp) will never see this as newer. Bump <!ENTITY version> in audiobookshelf.streams.plg.tpl."
  else
    note "OK: version is $new_version (was $old_version)"
  fi
fi

if [ "$fail" -ne 0 ]; then
  echo "verify.sh: FAILED" >&2
  exit 1
fi
echo "verify.sh: all checks passed"
