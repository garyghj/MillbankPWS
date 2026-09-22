# WXSIM Forecast vs Weather Underground Actuals

**Forecast Accuracy Dashboard by MillbankPWS, Munlochy, Scotland**

A PHP-based weather forecast verification system that compares **WXSIM forecasts** with **Weather Underground actual observations**.

The software archives WXSIM forecasts, collects subsequent Weather Underground observations, and presents the results through a set of browser-based comparison and accuracy displays.

## Main Features

- **7-Day Forecast vs Actual** — compares the archived WXSIM forecast with the observed weather for the corresponding seven-day period.
- **Detailed Comparison** — provides a more detailed forecast/observation comparison.
- **Accuracy Dashboard** — summarises forecast accuracy using the accumulated comparison records.
- **Cloud Cover** — displays WXSIM forecast cloud cover, including sky-cover percentage and oktas-style presentation.
- Choice of metric or imperial display units.
- Configurable station name, location and IANA timezone.
- Configurable WXSIM source units.
- Weather Underground observations obtained using the station ID and API key supplied by the user.
- Automated operation using scheduled jobs after initial configuration.

## Requirements

The software is intended primarily for a web server with:

- PHP 8.0 or later.
- Access to a WXSIM `latest.csv` forecast file, either by URL or local path.
- A Weather Underground Personal Weather Station.
- A Weather Underground API key.
- The ability to schedule PHP scripts using CRON or an equivalent scheduler.
- Write permission for the application's data and log directories.

## Installation

The package includes detailed documentation. New users should begin with:

**Part 1 - Quick Start / Initial Setup**

More detailed configuration, folder and scheduling information is provided in:

**Part 2 - Detailed Installation & Configuration Guide**

After uploading the PHP files to the server, open the application in a web browser and use the **Setup & Test** page to enter and test the station-specific settings.

Do not place another user's `data/settings.json`, archived forecasts, comparison records or log files into a new installation.

## Scheduled Tasks

Normal operation uses three scheduled PHP scripts:

- `worker.php` — captures and freezes the weekly WXSIM forecast.
- `update_actuals.php` — retrieves the completed day's Weather Underground observations.
- `stage2_update.php` — creates the detailed comparison records.

The exact CRON examples and scheduling instructions are provided in the installation documentation.

## Live Example

A working installation at Black Isle Weather Centre can be viewed at:

https://blackisleweather.net/wu_forecast_compare_csv/index.php

## Project Background

This project was developed by **MillbankPWS in Munlochy, Scotland** for comparing locally generated WXSIM forecasts with subsequent observations from a personal weather station.

It is provided for other weather enthusiasts, amateur weather observers, educational users and non-commercial organisations who may find forecast verification useful.

The author is not a professional software developer and cannot provide individual technical support for installation, server configuration or modification of the software.

General enquiries may be made through:

https://millbankhouse.co.uk/contactandcredits.php

## Licence

Copyright © 2026 MillbankPWS. All rights reserved.

The software is made available for **non-commercial use subject to the conditions in `LICENSE.txt`**.

In particular, commercial use, modification, adaptation or redistribution in modified form requires prior written permission from MillbankPWS.

Please read `LICENSE.txt` before installing, modifying or redistributing the software.

## Third-Party Services

WXSIM, Weather Underground and other third-party products or services referred to by this project remain the property of their respective owners.

This software is independently produced and does not imply endorsement by or affiliation with those third parties. Users are responsible for complying with the terms, API conditions and licensing requirements of any third-party services they use.

