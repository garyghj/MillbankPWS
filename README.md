# WXSIM Forecast Accuracy Dashboard

**Current Release: v1.0.3**

WXSIM Forecast Accuracy Dashboard is a PHP-based system for comparing a frozen **WXSIM seven-day weather forecast** with subsequent observations from a **Weather Underground Personal Weather Station (PWS)**.

The system preserves the original WXSIM forecast and progressively adds actual observations, allowing forecast performance to be reviewed without subsequently changing the forecast against which the observations are compared.

Developed by **MillbankPWS, Munlochy, Scotland**.

## Dashboard Pages

The package provides four main displays:

* **7-Day Comparison** — compares the frozen WXSIM daily forecast with completed Weather Underground daily observations.
* **Detailed Comparison** — provides a more detailed day-by-day comparison of forecast and actual values.
* **Accuracy Dashboard** — compares WXSIM forecast values with Weather Underground observations and provides forecast-error information.
* **Cloud Cover** — displays WXSIM forecast cloud cover using percentage values and meteorological cloud-cover symbols.

A separate **Setup & Tests** facility is provided for configuration and system diagnostics.

## Requirements

The system requires:

* **PHP 8.0 or later**
* WXSIM producing `latest.csv`
* WXSIM configured to provide **at least seven consecutive calendar forecast dates**
* A Weather Underground Personal Weather Station
* A Weather Underground API key
* CRON, or another suitable scheduler, for automatic updates
* Appropriate outbound server access to the configured WXSIM CSV source and Weather Underground

## Metric and Imperial Support

Version 1.0.3 supports WXSIM installations using either **Metric or Imperial source/output units**.

WXSIM source units and dashboard display units are configured separately.

Forecast data are normalised internally to common units before comparison with Weather Underground observations. Dashboard presentation can then independently be selected as Metric or Imperial.

This corrects the Imperial WXSIM temperature conversion issue identified in v1.0.2.

## Seven-Day Forecast Requirement

The 7-Day Comparison requires WXSIM `latest.csv` to contain at least **seven consecutive calendar forecast dates**.

Setup & Tests checks the actual date coverage contained in `latest.csv`.

If WXSIM is configured to generate only six forecast days, the test will report a failure and WXSIM should be changed to produce seven days before the comparison is initialised.

## Installation

Detailed installation instructions are supplied with the release package.

For a new installation:

1. Download the latest release package.
2. Upload the files to a dedicated directory on the web server.
3. Open the application and complete **Setup & Tests**.
4. Configure the station timezone, WXSIM source, source units, Weather Underground station information and display preferences.
5. Run the supplied tests and correct any reported failures.
6. Initialise the first seven-day forecast.
7. Configure the required scheduled/CRON jobs.

Refer to the supplied **Quick Start** and **Detailed Guide** for the complete procedure.

## Upgrading an Existing Installation

**Back up the complete existing installation before upgrading.**

Do not delete historical data or forecast archives simply to install a newer version.

In particular, preserve existing runtime data, weekly forecast archives and historical comparison records.

After upgrading, run **Setup & Tests** and confirm that the WXSIM and Weather Underground tests pass before relying on automatic operation.

## Rolling Back to an Earlier Version

Previous published versions remain available through the repository's **Releases** section.

If a newer release causes a problem:

1. Stop or temporarily disable the application's scheduled jobs.
2. Restore the backup made immediately before upgrading; this is the preferred rollback method.
3. Alternatively, download the required previous release from GitHub Releases and restore the corresponding application files.
4. Take care not to overwrite historical data with incompatible or empty files.
5. Run Setup & Tests before re-enabling automatic operation.

Maintaining a complete backup before every upgrade provides the safest rollback route.

## What's New in v1.0.3

Version 1.0.3 includes:

* Corrected support for **Imperial WXSIM source/output data**.
* Separation of **WXSIM source units** from **dashboard display units**.
* Corrected Imperial values and units in Detailed Comparison.
* Improved handling of temperature-error/MAE conversions.
* Explicit validation that `latest.csv` contains at least **seven consecutive forecast dates**.
* Improved diagnostics when WXSIM does not provide sufficient forecast coverage.
* **Station Time and UTC clocks** on the 7-Day Comparison.
* Improved WXSIM/Weather Underground matching in Accuracy processing.
* Improved wind-unit normalisation.
* Improved rainfall interval handling and safeguards.
* Continued protection of frozen forecast/comparison records once saved.
* Improved Setup & Tests diagnostics.

Users running WXSIM with **Imperial output** are particularly encouraged to upgrade from v1.0.2.

## Previous Releases

Earlier releases, including **v1.0.2** and **v1.0.1**, remain available from GitHub Releases for reference and rollback.

The main repository represents the current version of the software; tagged releases preserve previous published versions.

## Licence

See the supplied licence documentation for the terms under which the software is distributed.

© 2026 MillbankPWS. All rights reserved.
