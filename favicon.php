<?php
declare(strict_types=1);

/**
 * 卿辰导航 — favicon 代理与缓存
 *
 * 为什么需要它：
 *   现代站点大多不用 /favicon.ico，而是写在 <link rel="icon"> 里（svg/png 不定），
 *   前端直接拼域名 + /favicon.ico 命中率极低（实测 69 个站几乎全回落成首字）。
 *   第三方 favicon 服务（Google s2 / iowen）国内不稳定。
 *
 * 做法：
 *   1. 先查本地缓存 data/fav/<domain>.img，命中直接输出（带长缓存头）
 *   2. 未命中则抓首页 HTML，解析 <link rel="icon">，再退到 /favicon.ico
 *   3. 抓到就落地缓存，失败写 .fail 标记，7 天内不重试
 *
 * 安全（必须严格，否则是标准 SSRF）：
 *   - 只接受合法域名格式，不接受 IP、不接受协议前缀
 *   - DNS 解析后校验：内网 / 保留地址一律拒绝
 *   - curl 限定 HTTP(S)、禁跟随到内网、限制 200KB、6 秒超时
 *   - 只输出图片 MIME
 *
 * 用法：/favicon.php?d=chatgpt.com
 *
 * @since  2026-10-19
 */

require __DIR__ . '/lib.php';
nav_boot();

const FAV_DIR      = NAV_ROOT . '/data/fav';
const FAV_TTL      = 2592000;   // 缓存 30 天
const FAV_FAIL_TTL = 604800;    // 失败标记 7 天
const FAV_MAX_BYTES = 204800;   // 单文件上限 200KB
const FAV_TIMEOUT  = 6;

header('X-Robots-Tag: noindex, nofollow');

/* ---------- 参数与校验 ---------- */

$domain = strtolower(trim((string) ($_GET['d'] ?? '')));

// 只接受形如 a.b.c 的域名；显式排除 IP 与带协议/路径/端口的输入
if ($domain === ''
    || strlen($domain) > 253
    || !preg_match('/^(?=.{1,253}$)([a-z0-9]([a-z0-9\-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/', $domain)) {
    http_response_code(400);
    exit;
}

if (!is_dir(FAV_DIR)) {
    @mkdir(FAV_DIR, 0750, true);
}

$cacheImg  = FAV_DIR . '/' . $domain . '.img';
$cacheMeta = FAV_DIR . '/' . $domain . '.meta';

/* ---------- 缓存命中 ---------- */

function send_image(string $file, string $mime): void
{
    $mtime = @filemtime($file) ?: time();
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) @filesize($file));
    header('Cache-Control: public, max-age=2592000, immutable');
    header('Last-Modified: ' . gmdate('D, d M Y H:i:s', $mtime) . ' GMT');
    header('X-Content-Type-Options: nosniff');
    readfile($file);
    exit;
}

if (is_file($cacheImg) && (@filesize($cacheImg) ?: 0) > 0) {
    $mime = 'image/png';
    if (is_file($cacheMeta)) {
        $m = json_decode((string) @file_get_contents($cacheMeta), true);
        if (is_array($m) && !empty($m['mime'])) {
            $mime = (string) $m['mime'];
        }
    }
    send_image($cacheImg, $mime);
}

// 近期抓取失败过就不再反复打外网
if (is_file($cacheMeta) && (time() - (@filemtime($cacheMeta) ?: 0)) < FAV_FAIL_TTL) {
    http_response_code(404);
    exit;
}

/* ---------- SSRF 防护 ---------- */

function host_is_public(string $host): bool
{
    $ips = [];

    // gethostbynamel 只返回 IPv4，够用且简单
    $list = @gethostbynamel($host);
    if (is_array($list)) {
        $ips = $list;
    }

    if (empty($ips)) {
        return false;
    }

    foreach ($ips as $ip) {
        if (filter_var($ip, FILTER_VALIDATE_IP,
                FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false; // 内网 / 环回 / 保留地址
        }
    }
    return true;
}

/* ---------- 抓取 ---------- */

function http_get(string $url, int $maxBytes): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER   => true,
        CURLOPT_FOLLOWLOCATION   => true,
        CURLOPT_MAXREDIRS        => 3,
        CURLOPT_CONNECTTIMEOUT   => 4,
        CURLOPT_TIMEOUT          => FAV_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER   => false, // 老站点证书过期很常见，只取图标无敏感数据
        CURLOPT_SSL_VERIFYHOST   => false,
        CURLOPT_PROTOCOLS        => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_REDIR_PROTOCOLS  => CURLPROTO_HTTP | CURLPROTO_HTTPS,
        CURLOPT_USERAGENT        => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
                                  . '(KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
        CURLOPT_ENCODING         => '',
        CURLOPT_MAXFILESIZE      => $maxBytes,
        CURLOPT_HTTPHEADER       => ['Accept: image/*,text/html;q=0.8,*/*;q=0.5'],
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $type = (string) curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false || $code >= 400 || strlen((string) $body) === 0) {
        return ['ok' => false, 'body' => '', 'type' => $type, 'code' => $code, 'err' => $err];
    }
    return ['ok' => true, 'body' => (string) $body, 'type' => $type, 'code' => $code, 'err' => ''];
}

/**
 * 从 HTML 里解析 <link rel="icon"> 的候选地址（按优先级）
 */
function parse_icon_links(string $html, string $base): array
{
    $out = [];
    if (!preg_match_all('/<link\b[^>]*>/i', $html, $m)) {
        return $out;
    }

    $scored = [];
    foreach ($m[0] as $tag) {
        // rel 必须含 icon
        if (!preg_match('/\brel\s*=\s*["\']?([^"\'>]+)/i', $tag, $rm)) {
            continue;
        }
        $rel = strtolower($rm[1]);
        if (strpos($rel, 'icon') === false) {
            continue;
        }
        if (!preg_match('/\bhref\s*=\s*["\']([^"\']+)["\']/i', $tag, $hm)) {
            continue;
        }
        $href = trim($hm[1]);
        if ($href === '' || stripos($href, 'data:') === 0) {
            continue;
        }

        $score = 50;
        if (strpos($rel, 'apple-touch-icon') !== false) $score = 40; // 通常是 180px，最清晰
        if (strpos($rel, 'shortcut') !== false) $score = 30;
        if (preg_match('/\.svg($|\?)/i', $href)) $score -= 5;        // svg 浏览器支持好但个别环境异常
        if (preg_match('/\.ico($|\?)/i', $href)) $score -= 3;

        // 相对路径补全
        if (strpos($href, '//') === 0) {
            $href = 'https:' . $href;
        } elseif ($href[0] === '/') {
            $href = $base . $href;
        } elseif (!preg_match('#^https?://#i', $href)) {
            $href = rtrim($base, '/') . '/' . ltrim($href, './');
        }

        if (stripos($href, 'http') !== 0) {
            continue;
        }

        $scored[] = ['score' => $score, 'href' => $href];
    }

    usort($scored, fn($a, $b) => $b['score'] <=> $a['score']);
    foreach ($scored as $s) {
        $out[] = $s['href'];
    }
    return $out;
}

/**
 * 判断一段二进制是否是它声称的图片
 */
function sniff_image(string $data): string
{
    if (strncmp($data, "\x89PNG", 4) === 0) {
        return 'image/png';
    }
    if (strncmp($data, "\xFF\xD8\xFF", 3) === 0) {
        return 'image/jpeg';
    }
    if (strncmp($data, 'GIF8', 4) === 0) {
        return 'image/gif';
    }
    if (strncmp($data, "\x00\x00\x01\x00", 4) === 0) {
        return 'image/x-icon';
    }
    // SVG：可能带 BOM / XML 声明 / 注释，宽松匹配
    $head = substr($data, 0, 600);
    if (stripos($head, '<svg') !== false) {
        return 'image/svg+xml';
    }
    if (stripos($head, '<?xml') !== false && stripos($data, '<svg') !== false) {
        return 'image/svg+xml';
    }
    return '';
}

function save_fail(string $metaFile): void
{
    @file_put_contents($metaFile, json_encode(['fail' => 1, 'ts' => time()]));
}

/* ---------- 主流程 ---------- */

if (!host_is_public($domain)) {
    save_fail($cacheMeta);
    http_response_code(404);
    exit;
}

$base = 'https://' . $domain;

// 1) 抓首页，找 rel=icon
$candidates = [];
$home = http_get($base . '/', 307200);
if ($home['ok'] && stripos($home['type'], 'html') !== false) {
    $candidates = parse_icon_links($home['body'], $base);
}
// 2) 兜底：常规路径
$candidates[] = $base . '/favicon.ico';
$candidates[] = $base . '/favicon.png';
$candidates[] = $base . '/apple-touch-icon.png';

$saved = false;
foreach (array_slice(array_unique($candidates), 0, 6) as $url) {
    $r = http_get($url, FAV_MAX_BYTES);
    if (!$r['ok']) {
        continue;
    }
    $mime = sniff_image($r['body']);
    if ($mime === '') {
        continue;
    }
    @file_put_contents($cacheImg, $r['body']);
    @file_put_contents($cacheMeta, json_encode([
        'mime' => $mime,
        'src'  => $url,
        'ts'   => time(),
    ]));
    @chmod($cacheImg, 0640);
    @chmod($cacheMeta, 0640);
    $saved = true;
    break;
}

if (!$saved) {
    save_fail($cacheMeta);
    http_response_code(404);
    exit;
}

send_image($cacheImg, $mime ?? 'image/png');
