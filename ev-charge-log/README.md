# EV Charge Log

Reads the charged percentage and kWh from a photo of the charger screen, calculates the full battery capacity, saves it with today's date, and graphs the last two years with the seasons coloured.

## Setup

1. Activate **Plugin Controller** and **EV Charge Log**.
2. Open **Plugin Controller → Local Access**, configure a user and tick **EV Charge Log** under *Plugin access*.
3. Add `[ev_charge_log]` to a page and log in with the local passcode.

## Use

1. Take or pick a photo of the screen. Tesseract.js reads it in the browser and fills in `%` and `kWh`.
2. Check the values, correct them if needed, and press **Gem opladning**.

Capacity is calculated as `kWh / (% / 100)`. For example 20 kWh for 40 % gives 50 kWh.

Data is stored in the `{prefix}ecl_charges` table. Saved rows can be reviewed and deleted under **Plugin Controller → EV Charge Log**.

## Graph

Seasons are shown as background colours: winter (Dec-Feb) white, spring (Mar-May) green, summer (Jun-Aug) yellow, autumn (Sep-Nov) orange.

## Privacy

The photo is never uploaded to the site; only the extracted numbers are submitted. Tesseract.js and its language data are loaded from the jsDelivr CDN the first time a photo is read, so the browser needs internet access.
