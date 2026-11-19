# Audiobookshelf Streams

An Unraid plugin that shows **who is listening on your [Audiobookshelf](https://www.audiobookshelf.org/) server** right now, as a tile on the Unraid Dashboard. A spin-off of the idea behind *Plex Streams* by dorgan, built for Audiobookshelf.

For each user you see:

| | |
|---|---|
| **Status** | `Listening`, `Paused` or `Connected` |
| **What** | cover, title and author |
| **Where** | progress bar, time left, device / client |
| **Actions** | stop-session button |

The tile refreshes automatically (default every 10 s) and pauses polling while the browser tab is hidden.

## Install

In Unraid: **Plugins → Install Plugin**, paste:

```
https://raw.githubusercontent.com/Hunter-Clipper/audiobookshelf-streams/main/audiobookshelf.streams.plg
```

Then open **Settings → Utilities → Audiobookshelf Streams** and enter:

1. **Server URL**, e.g. `http://192.168.1.10:13378`
2. **API token** of an **admin** user (Audiobookshelf → Config → Users → your user → API token)

Use **Test connection**, then **Apply**. Requires Unraid 6.12.0 or newer.

## How states are decided

Audiobookshelf does not expose an explicit "paused" flag, so the plugin infers it:

- **Connected** — the user is online (open web socket) but has no open playback session.
- **Listening** — the user has an open session that synced within the last *Paused after* seconds (default 45). Players sync every few seconds while playing.
- **Paused** — the user is online with an open session that has not synced for longer than that.

Session ages are measured against the Audiobookshelf server's own clock (from its HTTP `Date` header), so clock drift between Unraid and the ABS host doesn't matter.

## Stop button

The stop button calls `POST /api/session/:id/close` on your server. That ends the session on the server; it **cannot** force a player app to stop. A client that is still playing may open a new session on its next sync.

## Security

- The API token is stored in `/boot/config/plugins/audiobookshelf.streams/token` and is **never sent to the browser** — the plugin's PHP backend calls Audiobookshelf and also proxies cover images.
- The flash drive is FAT-formatted, so file permissions are advisory only; treat the token like any other credential on the flash drive and use a dedicated admin user if you like.
- State-changing requests are protected by Unraid's CSRF token.
- Self-signed HTTPS certificates are rejected unless you tick **Allow self-signed / invalid HTTPS certificates**.

## Development

```
source/            files installed under /usr/local/emhttp/plugins/audiobookshelf.streams
audiobookshelf.streams.plg.tpl   template (version, changelog, install/remove scripts)
build.sh           regenerates audiobookshelf.streams.plg (all files inline, with SHA256)
verify.sh          checks the .plg before publishing (XML, SHA256s, php -l, version bump)
```

Edit files under `source/`, bump `<!ENTITY version>` in the template, add a changelog entry, then:

```
./build.sh && ./verify.sh     # verify.sh needs php on PATH
```

Commit the regenerated `.plg` with the change. CI runs `verify.sh` on every push and PR.

## Credits & license

Inspired by *Plex Streams (Official)* by dorgan. No code is shared with it. MIT licensed — see [LICENSE](LICENSE).
