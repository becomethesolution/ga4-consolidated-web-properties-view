<?php
/**
 * DEMO transport: answers the same API calls GA would, with plausible sample data,
 * in the exact JSON shape Google returns. Used automatically when no service-account
 * key is configured, so the dashboard can be previewed and the parsing code exercised.
 */
final class Demo
{
    const SITES = [
        'RECIPES.EXAMPLE.COM' => 2600, 'DEALS.EXAMPLE.COM' => 1400, 'TECHFORUM.EXAMPLE.COM' => 1100,
        'LOCALGUIDE.EXAMPLE.COM' => 900, 'BLOG.EXAMPLE.COM' => 700, 'HELPDESK.EXAMPLE.COM' => 650,
        'GAMING.EXAMPLE.COM' => 420, 'MUSIC.EXAMPLE.COM' => 380, 'NEWS.EXAMPLE.COM' => 260,
        'PHOTOS.EXAMPLE.COM' => 240, 'TRAVEL.EXAMPLE.COM' => 210, 'FINANCE.EXAMPLE.COM' => 160,
        'GARDEN.EXAMPLE.COM' => 120, 'PETS.EXAMPLE.COM' => 90, 'CHURCH.EXAMPLE.ORG' => 60,
        'BAKERY.EXAMPLE.COM' => 45, 'STUDIO.EXAMPLE.NET' => 35,
    ];

    const LISTS = [
        'country' => ['United States', 'Canada', 'United Kingdom', 'India', 'Australia', 'Germany', 'Philippines', 'Mexico', 'France', 'Brazil', 'Netherlands', 'Japan'],
        'deviceCategory' => ['mobile', 'desktop', 'tablet'],
        'sessionDefaultChannelGroup' => ['Organic Search', 'Direct', 'Referral', 'Organic Social', 'Unassigned', 'Email'],
        'sessionSource' => ['google', '(direct)', 'bing', 'duckduckgo', 'facebook.com', 'reddit.com', 'yahoo', 'm.facebook.com', 'chatgpt.com', 'baidu'],
        'pagePath' => ['/', '/recipes/weeknight-chili', '/guides/getting-started', '/reviews/best-budget-blender', '/recipes/banana-bread', '/how-to/reset-router', '/recipes/sheet-pan-chicken', '/guides/meal-prep-basics', '/reviews/air-fryer-comparison', '/recipes/overnight-oats', '/how-to/fix-slow-wifi', '/search', '/recipes/lentil-soup', '/guides/pantry-staples', '/about'],
        'pageTitle' => ['Home', 'Weeknight Chili', 'Getting Started Guide', 'Best Budget Blender', 'Classic Banana Bread', 'How to Reset Your Router', 'Sheet Pan Chicken', 'Meal Prep Basics', 'Air Fryer Comparison', 'Overnight Oats', 'How to Fix Slow Wi-Fi', 'Search', 'Easy Lentil Soup', 'Pantry Staples Checklist', 'About'],
        'unifiedScreenName' => ['Weeknight Chili', 'Getting Started Guide', 'Home', 'Best Budget Blender', 'How to Reset Your Router', 'Classic Banana Bread', 'Meal Prep Basics', 'Air Fryer Comparison', 'Sheet Pan Chicken', 'Overnight Oats'],
    ];

    private static function pidFor(string $name): string
    {
        return (string) (300000000 + crc32($name) % 99999999);
    }

    private static function sizeFor(string $pid): float
    {
        foreach (self::SITES as $n => $s) if (self::pidFor($n) === $pid) return $s;
        return 100;
    }

    public static function respond(string $url, ?array $body): array
    {
        if (str_contains($url, 'accountSummaries')) {
            $ps = [];
            foreach (self::SITES as $n => $_) $ps[] = ['property' => 'properties/' . self::pidFor($n), 'displayName' => $n];
            return ['accountSummaries' => [['account' => 'accounts/123456789', 'displayName' => 'Demo account', 'propertySummaries' => $ps]]];
        }
        preg_match('~properties/(\d+):(\w+)~', $url, $m);
        $pid = $m[1] ?? '0';
        if (($m[2] ?? '') === 'batchRunReports') {
            return ['reports' => array_map(fn($r) => self::report($pid, $r, false), $body['requests'] ?? [])];
        }
        return self::report($pid, $body ?? [], true);
    }

    /** Relative traffic by hour of day (evening peak). */
    private static function hourShape(int $h): float
    {
        return 0.45 + 0.75 * exp(-(($h - 20) ** 2) / 30) + 0.4 * exp(-(($h - 12) ** 2) / 12);
    }

    private static function daysBetween(string $a, string $b): array
    {
        $out = [];
        for ($t = strtotime($a); $t <= strtotime($b); $t += 86400) $out[] = date('Ymd', $t);
        return $out;
    }

    private static function resolveDate(string $s): string
    {
        if ($s === 'today') return date('Y-m-d');
        if ($s === 'yesterday') return date('Y-m-d', strtotime('-1 day'));
        if (preg_match('/^(\d+)daysAgo$/', $s, $m)) return date('Y-m-d', strtotime('-' . $m[1] . ' days'));
        return $s;
    }

    private static function metrics(float $u, array $names, int $salt): array
    {
        $sessions = $u * 1.28;
        $vals = [
            'activeUsers' => $u, 'newUsers' => $u * 0.74, 'sessions' => $sessions,
            'screenPageViews' => $u * 2.05, 'engagedSessions' => $sessions * 0.56,
            'userEngagementDuration' => $u * 48, 'eventCount' => $u * 6.3, 'keyEvents' => 0,
        ];
        $out = [];
        foreach ($names as $i => $n) {
            $v = $vals[$n] ?? $u;
            mt_srand($salt * 31 + $i);
            $v *= 0.93 + mt_rand(0, 140) / 1000;
            $out[] = ['value' => (string) max(0, (int) round($v))];
        }
        return $out;
    }

    private static function report(string $pid, array $req, bool $rt): array
    {
        $dims = array_map(fn($d) => $d['name'], $req['dimensions'] ?? []);
        $mets = array_map(fn($m) => $m['name'], $req['metrics'] ?? []);
        $daily = self::sizeFor($pid);
        $ranges = $rt ? ($req['minuteRanges'] ?? [['startMinutesAgo' => 29, 'endMinutesAgo' => 0]])
                      : ($req['dateRanges'] ?? [['startDate' => '27daysAgo', 'endDate' => 'today']]);
        $multi = count($ranges) > 1;
        $nowH = (int) date('G');
        $rows = [];
        $minuteSeed = (int) floor(time() / 30); // realtime values move every 30s

        foreach ($ranges as $ri => $r) {
            $rname = $r['name'] ?? ('date_range_' . $ri);
            if ($rt) {
                $from = (int) ($r['startMinutesAgo'] ?? 29);
                $to = (int) ($r['endMinutesAgo'] ?? 0);
                $minutes = range($to, $from);
                $perMin = $daily / 1440 * self::hourShape($nowH) * 1.6;
                $total = $perMin * count($minutes) * 0.55; // users overlap across minutes
                $dates = null;
            } else {
                $a = self::resolveDate($r['startDate']);
                $b = self::resolveDate($r['endDate']);
                $dates = self::daysBetween($a, $b);
                $total = 0;
                foreach ($dates as $d) $total += self::dayUsers($daily, $d, $pid);
            }

            // build value combinations
            $combo = [[]];
            $paired = null;
            foreach ($dims as $dn) {
                $vals = [];
                if ($dn === 'date') {
                    foreach ($dates as $d) $vals[] = [$d, self::dayUsers($daily, $d, $pid) / max(1, $total)];
                } elseif ($dn === 'hour') {
                    $isToday = $dates && end($dates) === date('Ymd');
                    $maxH = $isToday ? $nowH : 23;
                    $sum = 0;
                    for ($h = 0; $h <= $maxH; $h++) $sum += self::hourShape($h);
                    for ($h = 0; $h <= $maxH; $h++) $vals[] = [sprintf('%02d', $h), self::hourShape($h) / $sum];
                } elseif ($dn === 'minutesAgo') {
                    foreach ($minutes as $mi) {
                        mt_srand(crc32($pid) + (int) floor(time() / 60) - $mi); // window slides each minute
                        $vals[] = [sprintf('%02d', $mi), (0.6 + mt_rand(0, 800) / 1000) / count($minutes) / 0.55];
                    }
                } elseif ($dn === 'landingPage' || $dn === 'pagePath' || $dn === 'pageTitle' || isset(self::LISTS[$dn])) {
                    $list = self::LISTS[$dn === 'landingPage' ? 'pagePath' : $dn] ?? ['(other)'];
                    if ($paired !== null) { // second paired dim (pagePath + pageTitle)
                        foreach ($combo as &$c) $c[$dn] = [$list[$c['__i']] ?? '(not set)', 1];
                        unset($c);
                        continue;
                    }
                    $paired = true;
                    $zs = 0;
                    foreach ($list as $i => $_) $zs += 1 / ($i + 1.4);
                    foreach ($list as $i => $v) $vals[] = [$v, (1 / ($i + 1.4)) / $zs, $i];
                } else {
                    $vals[] = ['(not set)', 1];
                }
                $next = [];
                foreach ($combo as $c) foreach ($vals as $v) {
                    $n = $c;
                    $n[$dn] = [$v[0], $v[1]];
                    if (isset($v[2])) $n['__i'] = $v[2];
                    $next[] = $n;
                }
                $combo = $next;
            }

            foreach ($combo as $ci => $c) {
                unset($c['__i']);
                $share = 1;
                $dv = [];
                foreach ($dims as $dn) { $share *= $c[$dn][1]; $dv[] = ['value' => $c[$dn][0]]; }
                if ($multi) $dv[] = ['value' => $rname];
                $u = $total * $share;
                if ($rt && $u < 1 && $u > 0.15) $u = 1;
                if ($rt && $u < 0.5) continue; // realtime omits empty rows, like GA
                $rows[] = ['dimensionValues' => $dv, 'metricValues' => self::metrics($u, $mets, crc32($pid . json_encode($dv)) + ($rt ? $minuteSeed : 0))];
            }
        }

        // ordering + limit
        $ob = $req['orderBys'][0] ?? null;
        if ($ob && isset($ob['metric'])) {
            $mi = array_search($ob['metric']['metricName'], $mets, true);
            usort($rows, fn($a, $b) => (int) $b['metricValues'][$mi]['value'] <=> (int) $a['metricValues'][$mi]['value']);
        } elseif ($ob && isset($ob['dimension'])) {
            $di = array_search($ob['dimension']['dimensionName'], $dims, true);
            usort($rows, fn($a, $b) => strcmp($a['dimensionValues'][$di]['value'], $b['dimensionValues'][$di]['value']));
        }
        if (!empty($req['limit'])) $rows = array_slice($rows, 0, (int) $req['limit']);

        $dh = array_map(fn($d) => ['name' => $d], $dims);
        if ($multi) $dh[] = ['name' => 'dateRange'];
        $out = [
            'dimensionHeaders' => $dh,
            'metricHeaders' => array_map(fn($m) => ['name' => $m, 'type' => 'TYPE_INTEGER'], $mets),
            'rowCount' => count($rows),
            'kind' => $rt ? 'analyticsData#runRealtimeReport' : 'analyticsData#runReport',
        ];
        if ($rows) $out['rows'] = $rows;
        return $out;
    }

    private static function dayUsers(float $daily, string $ymd, string $pid): float
    {
        $t = strtotime($ymd);
        $dow = (int) date('w', $t);
        $week = in_array($dow, [0, 6], true) ? 0.82 : 1.05;
        $trend = 1 + (($t - time()) / 86400) * -0.0009; // gentle growth toward today
        mt_srand(crc32($pid . $ymd));
        $noise = 0.85 + mt_rand(0, 300) / 1000;
        $u = $daily * $week * $noise / max(0.6, $trend);
        if ($ymd === date('Ymd')) { // today is partial
            $sum = 0; $part = 0;
            for ($h = 0; $h <= 23; $h++) { $s = self::hourShape($h); $sum += $s; if ($h <= (int) date('G')) $part += $s; }
            $u *= $part / $sum;
        }
        return $u;
    }
}
