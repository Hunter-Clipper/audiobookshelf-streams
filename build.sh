#!/bin/bash
# Regenerates audiobookshelf.streams.plg from source/ + audiobookshelf.streams.plg.tpl.
# The .plg ships every plugin file inline (no separate .txz package), so
# source/ is the single place to edit files - always run this after editing
# anything under source/ and commit the regenerated .plg alongside it.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
SRC="$ROOT/source"
TPL="$ROOT/audiobookshelf.streams.plg.tpl"
OUT="$ROOT/audiobookshelf.streams.plg"

files_block=""
while IFS= read -r -d '' f; do
  rel="${f#"$SRC"}"
  if grep -q ']]>' "$f"; then
    echo "ERROR: $f contains ']]>' which would break its CDATA block" >&2
    exit 1
  fi
  mode="0644"
  case "$f" in
    */scripts/*.sh) mode="0755" ;;
  esac
  # Unraid's installer only overwrites an existing target file if the SHA256
  # we give it doesn't match what's already on disk (scripts/plugin: "If file
  # already exists, do not overwrite" unless a hash mismatch triggers an
  # unlink first). Without this, every "Update" after the first install is a
  # silent no-op for these files. The hash must match what Unraid actually
  # writes: trim($file->INLINE) . PHP_EOL - i.e. the file content with a
  # single trailing newline, which is exactly what `cat` + a literal "\n"
  # here reproduces (command substitution already strips trailing newlines).
  sha256=$(printf '%s\n' "$(cat "$f")" | sha256sum | cut -d' ' -f1)
  files_block+="<FILE Name=\"$rel\" Mode=\"$mode\" SHA256=\"$sha256\">
<INLINE>
<![CDATA[
$(cat "$f")
]]>
</INLINE>
</FILE>

"
done < <(find "$SRC" -type f -print0 | LC_ALL=C sort -z)

tpl_content="$(cat "$TPL")"
# NOTE: deliberately not using ${tpl_content//pattern/replacement} here - bash
# treats a literal '&' inside the replacement text as a back-reference to the
# matched pattern (like sed), which mangles any '&' in the source files
# (&&, &hellip;, >&2, ...). Splitting on the placeholder with %%/# avoids
# that since no "replacement" argument is ever involved.
before="${tpl_content%%"{{FILES}}"*}"
after="${tpl_content#*"{{FILES}}"}"
printf '%s' "${before}${files_block}${after}" > "$OUT"

echo "Wrote $OUT"
