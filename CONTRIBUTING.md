# Contributing

Thanks for helping. A few ground rules keep this project easy to install and run.

## Principles

- **Zero dependencies.** Plain PHP 8.0+ with `curl` and `openssl`, and vanilla JavaScript. No Composer packages, npm packages, CDNs or build step. Someone should be able to `git clone` into a web folder and be done.
- **Read-only.** The app only ever uses the `analytics.readonly` scope. It must never change anything in a user's Google account.
- **Private by default.** Nothing leaves the user's server except calls to Google's APIs. No tracking, telemetry or external fonts.
- **Quota-friendly.** New reports should be cached and batched. Prefer `batchRunReports` and extend the existing parallel calls over adding sequential ones.

## Development

```bash
git clone https://github.com/becomethesolution/ga4-consolidated-web-properties-view.git
cd ga4-consolidated-web-properties-view
php -S localhost:8080        # demo mode, no Google account needed
```

Demo mode (`lib/Demo.php`) answers every API call with sample data in the exact JSON shape Google returns. If you add a new report, teach `Demo.php` to answer it too, so the UI and tests keep working without credentials.

Before opening a pull request:

```bash
tests/smoke.sh               # starts the app in demo mode and checks every endpoint
node --check assets/app.js   # syntax-checks the front end
```

There is no hosted CI, so please run these locally (on PHP 8.0 or later) before opening a pull request.

## Style

- PHP: 4-space indents, `final` classes, small static helpers. Keep `lib/` free of HTML.
- JS: one IIFE in `assets/app.js`, no frameworks. Escape every value you put into HTML with `esc()`.
- UI text: short, plain English, sentence case.

## Reporting bugs

Open an issue with your PHP version, web server, how many properties you have, and the exact error text. Remove property IDs, emails and anything else private first.
