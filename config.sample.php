<?php
/*
 * GA4 Portfolio Dashboard — configuration
 * Copy this file to config.php and edit the values below.
 * config.php is git-ignored; never commit it or your key file.
 */
return [

    // Absolute path to the Google Cloud service-account JSON key.
    // Keep it OUTSIDE your web root so it can never be downloaded.
    // If the file is missing, the dashboard runs in DEMO mode with sample data.
    'service_account_key' => '/path/outside/webroot/service-account.json',

    // Optional: only include properties from this Analytics account ID
    // (Admin → Account settings → Account ID). Leave '' for every property
    // the service account can read.
    'account_id' => '',

    // Optional: hide properties by numeric property ID, e.g. ['123456789'].
    'exclude_property_ids' => [],

    // Dashboard password (strongly recommended). Generate a hash with:
    //   php -r "echo password_hash('your-password', PASSWORD_DEFAULT), PHP_EOL;"
    // Set to '' to turn the login off — only if the dashboard is not reachable
    // from the internet.
    'password_hash' => '',

    // Optional extra lock: only these client IPs may open the dashboard,
    // e.g. ['127.0.0.1', '::1', '203.0.113.10']. Empty = any IP (login still applies).
    'allowed_ips' => [],

    // Set true ONLY if the dashboard is served exclusively through Cloudflare's proxy
    // (and your origin rejects direct traffic). Then the visitor IP is read from the
    // CF-Connecting-IP header. Otherwise leave false: that header is easy to fake.
    'trust_cloudflare_ip_header' => false,

    // How often the browser refreshes real-time numbers (seconds, minimum 15).
    'realtime_refresh_seconds' => 30,

    // Server-side cache lifetimes (seconds). Any number of open tabs costs
    // the same API quota as one.
    'cache_realtime_seconds'   => 25,
    'cache_today_seconds'      => 300,   // today/yesterday and the hourly chart
    'cache_history_seconds'    => 1800,  // multi-day reports
    'cache_properties_seconds' => 21600, // property list (6 hours)

    // Max simultaneous HTTPS calls to Google.
    'max_parallel_requests' => 16,

    // Where cache files go. Must be writable by the web server and not publicly
    // readable (the bundled .htaccess denies it on Apache; see README for Nginx).
    'cache_dir' => __DIR__ . '/cache',
];
