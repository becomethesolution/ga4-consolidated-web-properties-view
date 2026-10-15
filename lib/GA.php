<?php
/**
 * Minimal Google Analytics 4 client — no Composer, no SDK.
 *  - Service-account auth (RS256 JWT -> OAuth access token), token cached on disk
 *  - Admin API: discovers every GA4 property the service account can read
 *  - Data API: runRealtimeReport / batchRunReports, fired in parallel with curl_multi
 *  - File cache per request, so many browser tabs cost one API call
 *  - DEMO mode (no key file) synthesizes realistic responses so the UI can be tried out
 */
final class GA
{
    const DATA  = 'https://analyticsdata.googleapis.com/v1beta/';
    const ADMIN = 'https://analyticsadmin.googleapis.com/v1beta/';
    const SCOPE = 'https://www.googleapis.com/auth/analytics.readonly';

    private array $cfg;
    private ?array $key = null;
    public bool $demo;

    public function __construct(array $cfg)
    {
        $this->cfg = $cfg;
        $path = $cfg['service_account_key'] ?? '';
        $this->demo = !($path && is_readable($path));
        if (!$this->demo) {
            $this->key = json_decode((string) file_get_contents($path), true);
            if (!is_array($this->key) || empty($this->key['private_key']) || empty($this->key['client_email'])) {
                throw new RuntimeException('Service-account key file is not valid JSON with private_key and client_email.');
            }
        }
        if (!is_dir($cfg['cache_dir'])) @mkdir($cfg['cache_dir'], 0770, true);
    }

    public function serviceAccountEmail(): string
    {
        return $this->key['client_email'] ?? '';
    }

    /* ------------------------------------------------------------ cache */

    private function cachePath(string $key): string
    {
        return rtrim($this->cfg['cache_dir'], '/') . '/' . sha1($key) . '.json';
    }

    public function cacheGet(string $key, int $ttl)
    {
        $f = $this->cachePath($key);
        if ($ttl > 0 && is_file($f) && (time() - filemtime($f)) < $ttl) {
            $d = json_decode((string) file_get_contents($f), true);
            if ($d !== null) return $d;
        }
        return null;
    }

    public function cacheSet(string $key, $value): void
    {
        $f = $this->cachePath($key);
        $tmp = $f . '.' . getmypid() . '.tmp';
        file_put_contents($tmp, json_encode($value));
        rename($tmp, $f);
    }

    /* ------------------------------------------------------------- auth */

    private static function b64url(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private function token(): string
    {
        $ck = 'token:' . $this->key['client_email'];
        $c = $this->cacheGet($ck, 3300); // Google tokens last 3600s
        if (is_array($c) && !empty($c['t'])) return $c['t'];

        $now = time();
        $head = self::b64url(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
        $claims = self::b64url(json_encode([
            'iss'   => $this->key['client_email'],
            'scope' => self::SCOPE,
            'aud'   => $this->key['token_uri'] ?? 'https://oauth2.googleapis.com/token',
            'iat'   => $now - 30,
            'exp'   => $now + 3570,
        ]));
        $sig = '';
        if (!openssl_sign("$head.$claims", $sig, $this->key['private_key'], OPENSSL_ALGO_SHA256)) {
            throw new RuntimeException('Could not sign the JWT — check the private key in the service-account file.');
        }
        $ch = curl_init($this->key['token_uri'] ?? 'https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_POSTFIELDS => http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => "$head.$claims." . self::b64url($sig),
            ]),
        ]);
        $raw = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        $j = json_decode((string) $raw, true);
        if ($code !== 200 || empty($j['access_token'])) {
            throw new RuntimeException('Google sign-in failed (' . $code . '): ' . ($j['error_description'] ?? $j['error'] ?? substr((string) $raw, 0, 200)));
        }
        $this->cacheSet($ck, ['t' => $j['access_token']]);
        return $j['access_token'];
    }

    /* ------------------------------------------------------- transport */

    /**
     * Run many API calls in parallel.
     * $reqs: [key => ['url'=>..., 'body'=>array|null, 'ttl'=>seconds]]
     * Returns [key => decoded JSON] or [key => ['__error' => message]].
     */
    public function multi(array $reqs): array
    {
        $out = [];
        $todo = [];
        foreach ($reqs as $k => $r) {
            $ck = 'req:' . $r['url'] . ':' . json_encode($r['body'] ?? null);
            $hit = $this->cacheGet($ck, (int) ($r['ttl'] ?? 0));
            if ($hit !== null) { $out[$k] = $hit; continue; }
            $todo[$k] = $r + ['ck' => $ck];
        }
        if (!$todo) return $out;

        if ($this->demo) {
            foreach ($todo as $k => $r) {
                $out[$k] = Demo::respond($r['url'], $r['body'] ?? null);
                $this->cacheSet($r['ck'], $out[$k]);
            }
            return $out;
        }

        $token = $this->token();
        $mh = curl_multi_init();
        $queue = $todo;
        $active = [];
        $max = max(1, (int) ($this->cfg['max_parallel_requests'] ?? 16));

        $add = function () use (&$queue, &$active, $mh, $token, $max) {
            while ($queue && count($active) < $max) {
                $k = array_key_first($queue);
                $r = $queue[$k];
                unset($queue[$k]);
                $ch = curl_init($r['url']);
                $h = ['Authorization: Bearer ' . $token, 'Accept: application/json'];
                $opts = [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 30, CURLOPT_ENCODING => ''];
                if (isset($r['body'])) {
                    $h[] = 'Content-Type: application/json';
                    $opts[CURLOPT_POST] = true;
                    $opts[CURLOPT_POSTFIELDS] = json_encode($r['body']);
                }
                $opts[CURLOPT_HTTPHEADER] = $h;
                curl_setopt_array($ch, $opts);
                curl_multi_add_handle($mh, $ch);
                $active[(int) $ch] = [$k, $r, $ch];
            }
        };

        $add();
        do {
            curl_multi_exec($mh, $running);
            curl_multi_select($mh, 1.0);
            while ($info = curl_multi_info_read($mh)) {
                $ch = $info['handle'];
                [$k, $r] = $active[(int) $ch];
                $raw = curl_multi_getcontent($ch);
                $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $j = json_decode((string) $raw, true);
                if ($code === 200 && is_array($j)) {
                    $out[$k] = $j;
                    $this->cacheSet($r['ck'], $j);
                } else {
                    if ($code === 401) @unlink($this->cachePath('token:' . $this->key['client_email'])); // force re-auth next time
                    $msg = $j['error']['message'] ?? (curl_error($ch) ?: ('HTTP ' . $code));
                    $out[$k] = ['__error' => $msg, '__code' => $code];
                }
                curl_multi_remove_handle($mh, $ch);
                curl_close($ch);
                unset($active[(int) $ch]);
                $add();
            }
        } while ($running || $active);
        curl_multi_close($mh);
        return $out;
    }

    /* ------------------------------------------------------- properties */

    /** @return array<int, array{id:string,name:string,account:string}> */
    public function properties(): array
    {
        $ttl = (int) ($this->cfg['cache_properties_seconds'] ?? 21600);
        $hit = $this->cacheGet('properties', $ttl);
        if (is_array($hit)) return $hit;

        $props = [];
        $page = '';
        do {
            $url = self::ADMIN . 'accountSummaries?pageSize=200' . ($page ? '&pageToken=' . urlencode($page) : '');
            $res = $this->multi(['p' => ['url' => $url, 'body' => null, 'ttl' => 0]])['p'];
            if (isset($res['__error'])) throw new RuntimeException('Could not list properties: ' . $res['__error']);
            foreach ($res['accountSummaries'] ?? [] as $acct) {
                $aid = substr($acct['account'] ?? '', strlen('accounts/'));
                if (!empty($this->cfg['account_id']) && $aid !== (string) $this->cfg['account_id']) continue;
                foreach ($acct['propertySummaries'] ?? [] as $p) {
                    $pid = substr($p['property'], strlen('properties/'));
                    if (in_array($pid, $this->cfg['exclude_property_ids'] ?? [], true)) continue;
                    $props[] = ['id' => $pid, 'name' => $p['displayName'] ?? $pid, 'account' => $aid];
                }
            }
            $page = $res['nextPageToken'] ?? '';
        } while ($page);

        usort($props, fn($a, $b) => strcasecmp($a['name'], $b['name']));
        $this->cacheSet('properties', $props);
        return $props;
    }

    /* ---------------------------------------------------- request makers */

    public static function realtime(string $pid, array $body): array
    {
        return ['url' => self::DATA . 'properties/' . $pid . ':runRealtimeReport', 'body' => $body];
    }

    public static function batch(string $pid, array $requests): array
    {
        return ['url' => self::DATA . 'properties/' . $pid . ':batchRunReports', 'body' => ['requests' => $requests]];
    }

    /**
     * Flatten a report into rows keyed by header name:
     *   [['dims'=>['date'=>'20261014','dateRange'=>'cur'], 'm'=>['activeUsers'=>12.0, ...]], ...]
     */
    public static function rows(?array $report): array
    {
        if (!$report || isset($report['__error'])) return [];
        $dh = array_map(fn($h) => $h['name'], $report['dimensionHeaders'] ?? []);
        $mh = array_map(fn($h) => $h['name'], $report['metricHeaders'] ?? []);
        $out = [];
        foreach ($report['rows'] ?? [] as $row) {
            $d = [];
            foreach ($row['dimensionValues'] ?? [] as $i => $v) $d[$dh[$i] ?? "d$i"] = $v['value'] ?? '';
            $m = [];
            foreach ($row['metricValues'] ?? [] as $i => $v) $m[$mh[$i] ?? "m$i"] = (float) ($v['value'] ?? 0);
            $out[] = ['dims' => $d, 'm' => $m];
        }
        return $out;
    }
}
