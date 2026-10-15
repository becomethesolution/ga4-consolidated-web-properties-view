# Security policy

This dashboard shows private analytics data and holds a Google service-account key, so security reports are taken seriously.

## Reporting a vulnerability

Please **do not open a public issue**. Report it privately through GitHub: **Security → Report a vulnerability** on this repository (or go to `/security/advisories/new`). Include the steps to reproduce and the version or commit you tested.

You should hear back within a few days. Once a fix is released, you'll be credited in the changelog unless you'd rather not be.

## Supported versions

Only the latest release on the `main` branch gets security fixes.

## Hardening checklist for people running it

- Keep the service-account key **outside** the web root, readable only by the web-server user (`chmod 600`).
- Give the service account the **Viewer** role only.
- Set a long `password_hash`, and use HTTPS if the dashboard is reachable from the internet.
- For internet-facing installs, put an extra layer in front (Cloudflare Access, a VPN or HTTP basic auth) and/or set `allowed_ips`.
- On Nginx, add the deny rules from the README; the `.htaccess` files only protect Apache.
- If a key is ever exposed, delete it in Google Cloud (**Service account → Keys**) and create a new one.
