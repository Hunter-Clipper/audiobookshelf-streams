<?xml version='1.0' standalone='yes'?>

<!DOCTYPE PLUGIN [
<!ENTITY name      "audiobookshelf.streams">
<!ENTITY author    "Hunter Clipper">
<!ENTITY version   "2026.11.19c">
<!ENTITY pluginURL "https://raw.githubusercontent.com/Hunter-Clipper/audiobookshelf-streams/main/audiobookshelf.streams.plg">
]>

<PLUGIN name="&name;" author="&author;" version="&version;" pluginURL="&pluginURL;" support="https://github.com/Hunter-Clipper/audiobookshelf-streams/issues" icon="headphones" min="6.12.0">

<CHANGES>
<![CDATA[
###2026.11.19c
- Removed the stop-session button. It called an Audiobookshelf endpoint
  that closes the session on the server but cannot stop the player, and
  Audiobookshelf can exit if that call errors while a client is still
  syncing. The plugin is now read-only.

###2026.11.19b
- Corrected the name capitalization to "Audiobookshelf Streams".

###2026.11.19a
- Plugin now shows as "Audiobookshelf Streams" with a description on the
  Plugins page.

###2026.11.19
- Initial release. Dashboard tile showing which Audiobookshelf users are
  Listening, Paused or Connected, with cover, title/author, progress bar,
  device, a stop-session button and auto-refresh. Settings under
  Settings > Utilities > Audiobookshelf Streams (server URL, admin API token,
  refresh interval, paused threshold, self-signed TLS option, Test connection).
]]>
</CHANGES>

<!--
Copyright 2026, Hunter Clipper
Audiobookshelf Streams - see who is listening on Audiobookshelf from the
Unraid Dashboard.
-->

{{FILES}}

<!-- POST-INSTALL SCRIPT -->
<FILE Run="/bin/bash">
<INLINE>
<![CDATA[
echo ""
echo "-----------------------------------------------------------"
echo " audiobookshelf.streams has been installed."
echo " Version: 2026.11.19c"
echo ""
echo " Add your server URL and admin API token under"
echo " Settings > Utilities > Audiobookshelf Streams."
echo "-----------------------------------------------------------"
echo ""
]]>
</INLINE>
</FILE>

<!-- REMOVE SCRIPT -->
<FILE Run="/bin/bash" Method="remove">
<INLINE>
<![CDATA[
rm -rf /usr/local/emhttp/plugins/audiobookshelf.streams
rm -rf /boot/config/plugins/audiobookshelf.streams
rm -f /tmp/audiobookshelf.streams.users.json
]]>
</INLINE>
</FILE>

</PLUGIN>
