# GolfScore

## Plugin Controller integration

GolfScore works independently when Plugin Controller is inactive. When both
plugins are active, GolfScore appears in **Plugin Controller > Local Access**.
Grant it to a local user and sign in on a page containing
`[plugin_controller_apps]`; GolfScore then appears in the launcher. The direct
`[golfscore_session]` shortcode and GolfScore AJAX actions also require that
local access. Existing users must be granted access after enabling the
integration.

GolfScore's administrator page is available under **Plugin Controller >
GolfScore** when Plugin Controller is active. Without it, the page remains
available from the standalone **GolfScore** admin menu.

The existing standalone GolfScore PWA remains available on a page containing
`[golfscore_session]`. Its service worker no longer caches pages or provides
offline access, to avoid retaining private score pages after logout. Devices
with an older worker need to revisit that page online to receive the update.

## Functional test page

Install and activate the optional Plugin Controller Test plugin, then open
**Plugin Controller > Plugin Tests** as a WordPress administrator and expand
**GolfScore**. If Plugin Controller is inactive, use **Tools > Plugin Tests**.
If Plugin Controller is active, first sign in through its app launcher as a
local user with GolfScore access in the same browser. Click **Run tests** to exercise session
creation, player management, scoring and score updates, polling, hole
advancement, and ending a session through the WordPress AJAX hooks. The test
also checks that invalid scores are rejected. The page retains the most recent
results and their timestamp; they do not represent a continuous health check.

Each run creates a uniquely named temporary session and removes its scores,
players, and session afterwards. Cleanup failures are reported on the page;
an interrupted request can leave a session named `GolfScore test ...` for
an administrator to investigate. The tests do not exercise actual HTTP
transport, the browser UI, scoreboard OCR, or PWA installation.
