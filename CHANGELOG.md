# Changelog

All notable changes to this project are listed here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and versions follow [Semantic Versioning](https://semver.org/).

## [Unreleased]

### Changed
- Renamed the project to **GA4 Consolidated Web Properties View** (repository `ga4-consolidated-web-properties-view`). The old GitHub address redirects.

## [1.0.0] - 2026-10-15

### Added
- All-properties landing page: real-time users (last 30 / 5 minutes) across every GA4 property, per-minute chart, devices, countries, live per-site table and top pages across sites.
- Today vs. yesterday by hour; 7 / 28 / 90-day and 12-month reports compared with the previous period; sortable per-site table with share and daily sparkline.
- Per-property page with real-time, today, period, top pages, landing pages, channels, sources, countries and devices.
- Service-account auth with no SDK, automatic property discovery, parallel API calls and a file cache.
- Password login, optional IP allow-list, CSP and `noindex` headers.
- Demo mode with sample data, and a smoke test that runs in CI.
