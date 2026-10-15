<?php
/**
 * Builds dashboard data: fires the GA calls for every property in parallel and
 * rolls the answers up into one cross-property view.
 */
final class Reports
{
    const M = ['activeUsers', 'newUsers', 'sessions', 'screenPageViews', 'engagedSessions', 'userEngagementDuration', 'eventCount'];

    private GA $ga;
    private array $cfg;

    public function __construct(GA $ga, array $cfg)
    {
        $this->ga = $ga;
        $this->cfg = $cfg;
    }

    private static function mets(array $names): array
    {
        return array_map(fn($n) => ['name' => $n], $names);
    }

    private static function dims(array $names): array
    {
        return array_map(fn($n) => ['name' => $n], $names);
    }

    private static function zero(): array
    {
        return array_fill_keys(self::M, 0.0);
    }

    private static function add(array &$into, array $m): void
    {
        foreach ($m as $k => $v) $into[$k] = ($into[$k] ?? 0) + $v;
    }

    /** Derived ratios that must be computed from summed parts, never averaged. */
    private static function derive(array $m): array
    {
        $m['engagementRate'] = ($m['sessions'] ?? 0) > 0 ? $m['engagedSessions'] / $m['sessions'] : 0;
        $m['avgEngagementTime'] = ($m['activeUsers'] ?? 0) > 0 ? $m['userEngagementDuration'] / $m['activeUsers'] : 0;
        $m['viewsPerUser'] = ($m['activeUsers'] ?? 0) > 0 ? $m['screenPageViews'] / $m['activeUsers'] : 0;
        return $m;
    }

    /* ======================================================== realtime */

    private function realtimeRequests(string $pid): array
    {
        $ttl = (int) $this->cfg['cache_realtime_seconds'];
        $r = fn($body) => GA::realtime($pid, $body) + ['ttl' => $ttl];
        return [
            "$pid|tot" => $r([
                'metrics' => self::mets(['activeUsers', 'screenPageViews', 'eventCount']),
                'minuteRanges' => [
                    ['name' => 'm30', 'startMinutesAgo' => 29, 'endMinutesAgo' => 0],
                    ['name' => 'm5', 'startMinutesAgo' => 4, 'endMinutesAgo' => 0],
                ],
            ]),
            "$pid|min" => $r([
                'dimensions' => self::dims(['minutesAgo']),
                'metrics' => self::mets(['activeUsers', 'screenPageViews']),
            ]),
            "$pid|pages" => $r([
                'dimensions' => self::dims(['unifiedScreenName']),
                'metrics' => self::mets(['screenPageViews', 'activeUsers']),
                'orderBys' => [['metric' => ['metricName' => 'screenPageViews'], 'desc' => true]],
                'limit' => 10,
            ]),
            "$pid|geo" => $r([
                'dimensions' => self::dims(['country']),
                'metrics' => self::mets(['activeUsers']),
                'orderBys' => [['metric' => ['metricName' => 'activeUsers'], 'desc' => true]],
                'limit' => 15,
            ]),
            "$pid|dev" => $r([
                'dimensions' => self::dims(['deviceCategory']),
                'metrics' => self::mets(['activeUsers']),
            ]),
        ];
    }

    public function realtime(array $props): array
    {
        $reqs = [];
        foreach ($props as $p) $reqs += $this->realtimeRequests($p['id']);
        return $this->parseRealtime($props, $this->ga->multi($reqs));
    }

    private function parseRealtime(array $props, array $res): array
    {
        $sites = [];
        $minutes = array_fill(0, 30, ['users' => 0, 'views' => 0]); // index = minutes ago
        $pages = [];
        $geo = [];
        $dev = [];
        $tot = ['users30' => 0, 'users5' => 0, 'views30' => 0, 'events30' => 0];
        $errors = [];

        foreach ($props as $p) {
            $pid = $p['id'];
            $site = ['id' => $pid, 'name' => $p['name'], 'users30' => 0, 'users5' => 0, 'views30' => 0, 'events30' => 0,
                     'minutes' => array_fill(0, 30, 0), 'topPage' => null];
            foreach (['tot', 'min', 'pages', 'geo', 'dev'] as $k) {
                if (isset($res["$pid|$k"]['__error'])) $errors[$p['name']] = $res["$pid|$k"]['__error'];
            }
            foreach (GA::rows($res["$pid|tot"] ?? null) as $row) {
                $rng = $row['dims']['dateRange'] ?? 'm30';
                if ($rng === 'm30') {
                    $site['users30'] = $row['m']['activeUsers'];
                    $site['views30'] = $row['m']['screenPageViews'];
                    $site['events30'] = $row['m']['eventCount'];
                } else {
                    $site['users5'] = $row['m']['activeUsers'];
                }
            }
            foreach (GA::rows($res["$pid|min"] ?? null) as $row) {
                $i = (int) $row['dims']['minutesAgo'];
                if ($i < 0 || $i > 29) continue;
                $site['minutes'][$i] = $row['m']['activeUsers'];
                $minutes[$i]['users'] += $row['m']['activeUsers'];
                $minutes[$i]['views'] += $row['m']['screenPageViews'];
            }
            foreach (GA::rows($res["$pid|pages"] ?? null) as $i => $row) {
                $title = $row['dims']['unifiedScreenName'];
                if ($i === 0) $site['topPage'] = $title;
                $pages[] = ['site' => $p['name'], 'siteId' => $pid, 'title' => $title,
                            'views' => $row['m']['screenPageViews'], 'users' => $row['m']['activeUsers']];
            }
            foreach (GA::rows($res["$pid|geo"] ?? null) as $row) {
                $c = $row['dims']['country'];
                $geo[$c] = ($geo[$c] ?? 0) + $row['m']['activeUsers'];
            }
            foreach (GA::rows($res["$pid|dev"] ?? null) as $row) {
                $d = $row['dims']['deviceCategory'];
                $dev[$d] = ($dev[$d] ?? 0) + $row['m']['activeUsers'];
            }
            foreach ($tot as $k => $_) $tot[$k] += $site[$k];
            $site['minutes'] = array_reverse($site['minutes']); // oldest -> newest
            $sites[] = $site;
        }

        usort($sites, fn($a, $b) => [$b['users30'], $b['views30']] <=> [$a['users30'], $a['views30']]);
        usort($pages, fn($a, $b) => $b['views'] <=> $a['views']);
        arsort($geo);
        arsort($dev);

        return [
            'totals' => $tot,
            'minutes' => array_reverse($minutes), // oldest (29 min ago) -> newest (now)
            'sites' => $sites,
            'pages' => array_slice($pages, 0, 20),
            'countries' => array_slice(array_map(fn($k, $v) => ['name' => $k, 'users' => $v], array_keys($geo), $geo), 0, 12),
            'devices' => array_map(fn($k, $v) => ['name' => $k, 'users' => $v], array_keys($dev), $dev),
            'errors' => $errors,
        ];
    }

    /* ==================================================== today + hourly */

    private function todayBatch(string $pid): array
    {
        $ranges = [
            ['name' => 'today', 'startDate' => 'today', 'endDate' => 'today'],
            ['name' => 'yest', 'startDate' => 'yesterday', 'endDate' => 'yesterday'],
        ];
        return GA::batch($pid, [
            ['dateRanges' => $ranges, 'metrics' => self::mets(self::M)],
            ['dateRanges' => $ranges, 'dimensions' => self::dims(['hour']),
             'metrics' => self::mets(['activeUsers', 'screenPageViews', 'sessions']),
             'orderBys' => [['dimension' => ['dimensionName' => 'hour']]]],
        ]) + ['ttl' => (int) $this->cfg['cache_today_seconds']];
    }

    private function parseToday(?array $batch): array
    {
        $t = ['today' => self::zero(), 'yest' => self::zero()];
        $hours = ['today' => array_fill(0, 24, null), 'yest' => array_fill(0, 24, 0)];
        $views = ['today' => array_fill(0, 24, null), 'yest' => array_fill(0, 24, 0)];
        $reports = $batch['reports'] ?? [];
        foreach (GA::rows($reports[0] ?? null) as $row) {
            $k = $row['dims']['dateRange'] ?? 'today';
            if (isset($t[$k])) $t[$k] = $row['m'] + self::zero();
        }
        foreach (GA::rows($reports[1] ?? null) as $row) {
            $k = $row['dims']['dateRange'] ?? 'today';
            $h = (int) $row['dims']['hour'];
            if (!isset($hours[$k]) || $h < 0 || $h > 23) continue;
            $hours[$k][$h] = $row['m']['activeUsers'];
            $views[$k][$h] = $row['m']['screenPageViews'];
        }
        return ['totals' => $t, 'hourUsers' => $hours, 'hourViews' => $views];
    }

    /* ========================================================= history */

    private static function periodRanges(int $days): array
    {
        return [
            ['name' => 'cur', 'startDate' => $days . 'daysAgo', 'endDate' => 'yesterday'],
            ['name' => 'prev', 'startDate' => (2 * $days) . 'daysAgo', 'endDate' => ($days + 1) . 'daysAgo'],
        ];
    }

    private function historyBatch(string $pid, int $days): array
    {
        $r = self::periodRanges($days);
        return GA::batch($pid, [
            ['dateRanges' => [$r[0]], 'dimensions' => self::dims(['date']), 'metrics' => self::mets(self::M),
             'orderBys' => [['dimension' => ['dimensionName' => 'date']]]],
            ['dateRanges' => $r, 'metrics' => self::mets(self::M)],
        ]) + ['ttl' => (int) $this->cfg['cache_history_seconds']];
    }

    private static function dateList(int $days): array
    {
        $out = [];
        for ($i = $days; $i >= 1; $i--) $out[] = date('Ymd', strtotime("-$i days"));
        return $out;
    }

    private function parseHistory(?array $batch, int $days): array
    {
        $reports = $batch['reports'] ?? [];
        $series = [];
        foreach (self::dateList($days) as $d) $series[$d] = self::zero();
        foreach (GA::rows($reports[0] ?? null) as $row) {
            $d = $row['dims']['date'];
            if (isset($series[$d])) $series[$d] = $row['m'] + self::zero();
        }
        $tot = ['cur' => self::zero(), 'prev' => self::zero()];
        foreach (GA::rows($reports[1] ?? null) as $row) {
            $k = $row['dims']['dateRange'] ?? 'cur';
            if (isset($tot[$k])) $tot[$k] = $row['m'] + self::zero();
        }
        return ['series' => $series, 'totals' => $tot];
    }

    /** Landing page: today/yesterday + period history for every property. */
    public function summary(array $props, int $days): array
    {
        $reqs = [];
        foreach ($props as $p) {
            $reqs[$p['id'] . '|today'] = $this->todayBatch($p['id']);
            $reqs[$p['id'] . '|hist'] = $this->historyBatch($p['id'], $days);
        }
        $res = $this->ga->multi($reqs);

        $today = ['today' => self::zero(), 'yest' => self::zero()];
        $hourUsers = ['today' => array_fill(0, 24, null), 'yest' => array_fill(0, 24, 0)];
        $hourViews = ['today' => array_fill(0, 24, null), 'yest' => array_fill(0, 24, 0)];
        $period = ['cur' => self::zero(), 'prev' => self::zero()];
        $series = [];
        foreach (self::dateList($days) as $d) $series[$d] = self::zero();
        $sites = [];
        $errors = [];

        foreach ($props as $p) {
            $pid = $p['id'];
            foreach (['today', 'hist'] as $k) {
                if (isset($res["$pid|$k"]['__error'])) $errors[$p['name']] = $res["$pid|$k"]['__error'];
            }
            $t = $this->parseToday($res["$pid|today"] ?? null);
            $h = $this->parseHistory($res["$pid|hist"] ?? null, $days);

            self::add($today['today'], $t['totals']['today']);
            self::add($today['yest'], $t['totals']['yest']);
            foreach (['today', 'yest'] as $k) {
                for ($i = 0; $i < 24; $i++) {
                    if ($t['hourUsers'][$k][$i] !== null) $hourUsers[$k][$i] = ($hourUsers[$k][$i] ?? 0) + $t['hourUsers'][$k][$i];
                    if ($t['hourViews'][$k][$i] !== null) $hourViews[$k][$i] = ($hourViews[$k][$i] ?? 0) + $t['hourViews'][$k][$i];
                }
            }
            self::add($period['cur'], $h['totals']['cur']);
            self::add($period['prev'], $h['totals']['prev']);
            $spark = [];
            foreach ($h['series'] as $d => $m) {
                self::add($series[$d], $m);
                $spark[] = $m['activeUsers'];
            }
            $sites[] = [
                'id' => $pid, 'name' => $p['name'],
                'today' => self::derive($t['totals']['today']),
                'yest' => self::derive($t['totals']['yest']),
                'cur' => self::derive($h['totals']['cur']),
                'prev' => self::derive($h['totals']['prev']),
                'spark' => $spark,
            ];
        }

        usort($sites, fn($a, $b) => $b['cur']['activeUsers'] <=> $a['cur']['activeUsers']);
        return [
            'days' => $days,
            'today' => ['today' => self::derive($today['today']), 'yest' => self::derive($today['yest'])],
            'hourly' => ['users' => $hourUsers, 'views' => $hourViews],
            'period' => ['cur' => self::derive($period['cur']), 'prev' => self::derive($period['prev'])],
            'series' => array_map(fn($d, $m) => ['date' => (string) $d] + self::derive($m), array_keys($series), $series),
            'sites' => $sites,
            'errors' => $errors,
        ];
    }

    /* ======================================================== property */

    public function property(array $p, int $days): array
    {
        $pid = $p['id'];
        $r = self::periodRanges($days);
        $cur = [$r[0]];
        $ttl = (int) $this->cfg['cache_history_seconds'];
        $top = fn(string $dim, array $mets, string $by, int $limit, array $extraDims = []) => [
            'dateRanges' => $cur,
            'dimensions' => self::dims(array_merge([$dim], $extraDims)),
            'metrics' => self::mets($mets),
            'orderBys' => [['metric' => ['metricName' => $by], 'desc' => true]],
            'limit' => $limit,
        ];

        $reqs = $this->realtimeRequests($pid);
        $reqs["$pid|today"] = $this->todayBatch($pid);
        $reqs["$pid|hist"] = $this->historyBatch($pid, $days);
        $reqs["$pid|tops1"] = GA::batch($pid, [
            $top('pagePath', ['screenPageViews', 'activeUsers', 'userEngagementDuration'], 'screenPageViews', 25, ['pageTitle']),
            $top('sessionDefaultChannelGroup', ['sessions', 'activeUsers', 'engagedSessions'], 'sessions', 12),
            $top('sessionSource', ['sessions', 'activeUsers', 'engagedSessions'], 'sessions', 12),
        ]) + ['ttl' => $ttl];
        $reqs["$pid|tops2"] = GA::batch($pid, [
            $top('country', ['activeUsers', 'sessions'], 'activeUsers', 12),
            $top('deviceCategory', ['activeUsers', 'sessions'], 'activeUsers', 5),
            $top('landingPage', ['sessions', 'activeUsers', 'engagedSessions'], 'sessions', 15),
        ]) + ['ttl' => $ttl];

        $res = $this->ga->multi($reqs);
        $errors = [];
        foreach ($res as $k => $v) if (isset($v['__error'])) $errors[$k] = $v['__error'];

        // realtime for just this property, reusing the cross-property parser
        $rt = $this->parseRealtime([$p], $res);
        $t = $this->parseToday($res["$pid|today"] ?? null);
        $h = $this->parseHistory($res["$pid|hist"] ?? null, $days);

        $list = function (?array $report, array $map) {
            $out = [];
            foreach (GA::rows($report) as $row) {
                $o = [];
                foreach ($map as $outKey => $src) $o[$outKey] = $row['dims'][$src] ?? $row['m'][$src] ?? null;
                $out[] = $o;
            }
            return $out;
        };
        $t1 = $res["$pid|tops1"]['reports'] ?? [];
        $t2 = $res["$pid|tops2"]['reports'] ?? [];

        $pages = $list($t1[0] ?? null, ['path' => 'pagePath', 'title' => 'pageTitle', 'views' => 'screenPageViews', 'users' => 'activeUsers', 'eng' => 'userEngagementDuration']);
        foreach ($pages as &$pg) $pg['avgEng'] = $pg['users'] > 0 ? $pg['eng'] / $pg['users'] : 0;
        unset($pg);

        return [
            'property' => $p,
            'days' => $days,
            'realtime' => $rt,
            'today' => ['today' => self::derive($t['totals']['today']), 'yest' => self::derive($t['totals']['yest'])],
            'hourly' => ['users' => $t['hourUsers'], 'views' => $t['hourViews']],
            'period' => ['cur' => self::derive($h['totals']['cur']), 'prev' => self::derive($h['totals']['prev'])],
            'series' => array_map(fn($d, $m) => ['date' => (string) $d] + self::derive($m), array_keys($h['series']), $h['series']),
            'pages' => $pages,
            'channels' => $list($t1[1] ?? null, ['name' => 'sessionDefaultChannelGroup', 'sessions' => 'sessions', 'users' => 'activeUsers', 'engaged' => 'engagedSessions']),
            'sources' => $list($t1[2] ?? null, ['name' => 'sessionSource', 'sessions' => 'sessions', 'users' => 'activeUsers', 'engaged' => 'engagedSessions']),
            'countries' => $list($t2[0] ?? null, ['name' => 'country', 'users' => 'activeUsers', 'sessions' => 'sessions']),
            'devices' => $list($t2[1] ?? null, ['name' => 'deviceCategory', 'users' => 'activeUsers', 'sessions' => 'sessions']),
            'landing' => $list($t2[2] ?? null, ['path' => 'landingPage', 'sessions' => 'sessions', 'users' => 'activeUsers', 'engaged' => 'engagedSessions']),
            'errors' => $errors,
        ];
    }
}
