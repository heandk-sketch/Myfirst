# EV Charge Log

Reads the charged percentage and kWh from a photo of the charger screen, calculates the full battery capacity, saves it with today's date, and graphs the last two years with the seasons coloured.

## Setup

1. Activate **Plugin Controller** and **EV Charge Log**.
2. Open **Plugin Controller → Local Access**, configure a user and tick **EV Charge Log** under *Plugin access*.
3. Add `[ev_charge_log]` to a page and log in with the local passcode.

## Use

1. Take or pick a photo of the screen. EV Charge Log stores it on the WordPress server and Tesseract.js reads it in the browser to fill in `%` and `kWh`.
2. Check the values, correct them if needed, and press **Gem opladning**.
3. If the values were not recognized, tap one of the saved thumbnails to try reading that photo again.

Capacity is calculated as `kWh / (% / 100)`. For example 20 kWh for 40 % gives 50 kWh.

Charge data is stored in the `{prefix}ecl_charges` table and photos in `{prefix}ecl_photos`. Saved charge rows can be reviewed and deleted under **Plugin Controller → EV Charge Log**.

## Install as an app (Android)

The page with `[ev_charge_log]` is an installable web app (manifest, service worker and icons are served by the plugin).

1. The site must be served over **HTTPS** and reachable from the phone (a `.local` address on your PC will not work from the phone).
2. Open the page in **Chrome** on Android, log in, and choose **⋮ → Installér app** (or *Føj til startskærm*).
3. EV Log now opens full screen from the home screen.

Pages are never cached offline because they contain your data; without internet the app shows a short "Ingen forbindelse" message. The camera/photo picker works the same as in the browser. Saving a photo requires an internet connection.

## Graph

Seasons are shown as background colours: winter (Dec-Feb) white, spring (Mar-May) green, summer (Jun-Aug) yellow, autumn (Sep-Nov) orange.

## Privacy

Photos are stored in the WordPress database, not the Media Library. Only the three newest photos per local-access user are kept; older ones are automatically deleted. Saved photos are served only to that logged-in local-access user and are not publicly accessible. Supported formats are JPEG, PNG, WebP and GIF, up to 10 MB each. Tesseract.js and its language data are loaded from the jsDelivr CDN the first time a photo is read, so the browser needs internet access.
