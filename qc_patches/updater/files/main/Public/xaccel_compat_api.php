<?php
// xaccel_compat_api : API compatible XAccel (/xaapi/stream/...) pilotant les chaînes XC_VM. Pour les scripts écrits pour l'API XAccel.
// Routes : GET stream/stats | GET stream/{slot}/stats | GET stream/{slot}/config | POST stream/{slot}/update {input_urls?,name?} | POST stream/{slot}/(start|stop|restart)
// {slot} = id de slot XAccel, traduit en id XC_VM via config/xaccel_slot_map.json. Écritures bloquées tant que write_enabled=false (réponse dry_run).
header('Content-Type: application/json');
require_once dirname(__DIR__) . '/bootstrap.php';
XC_Bootstrap::boot(XC_Bootstrap::CONTEXT_ADMIN);
use XcVm\Core\Database\DatabaseHandler;
use XcVm\Core\Events\EventDispatcher;
use XcVm\Core\Events\Stream\StreamsChangedEvent;
use XcVm\Domain\Stream\StreamControlDispatcher;
use XcVm\Domain\Cluster\StreamPush;
use XcVm\Infrastructure\Database\DatabaseFactory;

const CFG_FILE = '/home/xc_vm/config/xaccel_compat_api.json';
const MAP_FILE = '/home/xc_vm/config/xaccel_slot_map.json';
$cfg = json_decode((string) @file_get_contents(CFG_FILE), true) ?: [];
function out($data, int $code = 200): void { http_response_code($code); die(json_encode($data, JSON_UNESCAPED_SLASHES)); }
function logline(string $s): void {
    global $cfg;
    $s = preg_replace(['#decryption_key=[^&\s"]+#', '#password=[^&\s"]+#'], ['decryption_key=***', 'password=***'], $s);
    @file_put_contents($cfg['log'] ?? '/home/xc_vm/tmp_qc/xaccel_compat_api.log', date('Y-m-d H:i:s') . ' ' . ($_SERVER['REMOTE_ADDR'] ?? '-') . ' ' . $s . "\n", FILE_APPEND);
}

$ip = $_SERVER['REMOTE_ADDR'] ?? '';
if (!$cfg || !in_array($ip, $cfg['ips'] ?? [], true)) { out(['error' => 'forbidden ip'], 403); }
$auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
if (!preg_match('/^Token\s+(\S+)$/', $auth, $m) || !hash_equals((string) $cfg['token'], $m[1])) { out(['error' => 'invalid API request'], 401); }

$path = trim(preg_replace('#^/xaapi#', '', $_SERVER['PATH_INFO'] ?? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH)), '/');
$method = $_SERVER['REQUEST_METHOD'];
$map = json_decode((string) @file_get_contents(MAP_FILE), true) ?: [];
$slot2xc = [];
foreach ($map as $slot => $e) { if (!empty($e['xc_id'])) { $slot2xc[(int) $slot] = (int) $e['xc_id']; } }
$xc2slot = array_flip($slot2xc);
$serverId = (int) ($cfg['server_id'] ?? 7);
$db = new DatabaseHandler();
DatabaseFactory::set($db);

function decodeSources(?string $json): array {
    $a = json_decode((string) $json, true);
    if (!is_array($a)) { return []; }
    return array_values(array_map(fn($u) => str_replace('\\/', '/', (string) $u), $a));
}
function serverFor(int $xcId): int {
    global $db, $serverId;
    $db->query('SELECT MIN(server_id) sid FROM streams_servers WHERE stream_id = ?', $xcId);
    $r = $db->get_row();
    return (int) ($r['sid'] ?? 0) ?: $serverId;
}
function rowsFor(array $xcIds): array {
    global $db, $serverId, $xc2slot;
    if (!$xcIds) { return []; }
    $db->query('SELECT s.id, s.stream_display_name, s.stream_source, s.category_id, ss.pid, ss.stream_status, ss.stream_started, ss.bitrate, ss.to_analyze, ss.progress_info, ss.server_id FROM streams s LEFT JOIN streams_servers ss ON ss.stream_id = s.id AND ss.server_id = (SELECT MIN(server_id) FROM streams_servers WHERE stream_id = s.id) WHERE s.id IN (' . implode(',', array_map('intval', $xcIds)) . ') ORDER BY s.id');
    $out = [];
    foreach ($db->get_rows() as $r) {
        $pid = (int) ($r['pid'] ?? 0); $st = (int) ($r['stream_status'] ?? 0); $an = (int) ($r['to_analyze'] ?? 0);
        $status = $pid > 0 ? ($st === 0 && !$an ? 'running' : 'starting') : ($st !== 0 || $an ? 'restarting' : 'stopped');
        $prog = json_decode((string) ($r['progress_info'] ?? ''), true) ?: [];
        $kbps = $pid > 0 ? (int) ($r['bitrate'] ?? 0) : 0; // sans processus : aucun débit (valeur DB périmée ignorée)
        if ($pid > 0 && !$kbps && is_numeric($prog['bitrate'] ?? null)) { $kbps = (int) round((float) $prog['bitrate']); }
        // sortie HLS : ffmpeg ne donne pas de bitrate -> on estime via le débit de trames pour que les scripts voient un signal (>0)
        if (!$kbps && $pid > 0 && (int) ($prog['frame'] ?? 0) > 0 && (float) ($prog['fps'] ?? 0) > 0) { $kbps = (int) ($prog['stream_bitrate_est'] ?? 1000); }
        $srcs = decodeSources($r['stream_source']);
        $out[] = [
            'id' => $xc2slot[(int) $r['id']] ?? (int) $r['id'], 'xc_id' => (int) $r['id'], 'name' => $r['stream_display_name'],
            'status' => $status, 'bitrate' => $kbps, 'uptime' => $pid > 0 && $r['stream_started'] ? max(0, time() - (int) $r['stream_started']) : 0,
            'input_urls' => $srcs, 'url' => $srcs[0] ?? null, 'server_id' => (int) ($r['server_id'] ?? 0), 'time' => $pid > 0 && $r['stream_started'] ? max(0, time() - (int) $r['stream_started']) : 0, 'speed' => $prog['speed'] ?? null, 'pid' => $pid, 'xc_status' => $st,
        ];
    }
    return $out;
}

if ($path === 'stream/stats' && $method === 'GET') {
    out(rowsFor(array_values($slot2xc)));
}
if (!preg_match('#^stream/(\d+)/(stats|config|update|start|stop|restart|dynamic-url)$#', $path, $m)) { out(['error' => 'invalid API request', 'path' => $path], 404); }
$slot = (int) $m[1]; $action = $m[2];
if (!isset($slot2xc[$slot])) { out(['error' => 'slot not mapped', 'id' => $slot], 404); }
$xcId = $slot2xc[$slot];

if ($action === 'stats' || $action === 'config') {
    $rows = rowsFor([$xcId]);
    if (!$rows) { out(['error' => 'stream not found', 'id' => $slot], 404); }
    $r = $rows[0];
    out($action === 'stats' ? $r : ['id' => $slot, 'xc_id' => $xcId, 'name' => $r['name'], 'input_urls' => $r['input_urls'], 'status' => $r['status']]);
}
if ($method !== 'POST') { out(['error' => 'POST required'], 405); }
$writes = !empty($cfg['write_enabled']);
if ($action === 'dynamic-url') {
    // XAccel : remplace la source à chaud par la liste d'URLs du corps JSON -> ici update + restart
    $urls = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($urls) || !$urls) { out(['ok' => false, 'error' => 'json list of urls required'], 400); }
    // GARDE-FOU : jamais de redémarrage d'une chaîne en ligne (clients connectés) — seules les chaînes sans processus (bloquées/en relance) sont acceptées, sauf ?force=1
    $cur = rowsFor([$xcId]);
    // Ne protéger que les chaînes RÉELLEMENT en lecture (clients servis) : status running ET débit > 0.
    // Une chaîne qui boucle en relance (pid transitoire, bitrate 0, source morte) DOIT pouvoir être corrigée.
    // Un placeholder local (127.0.0.1:8899) qui joue n'est PAS protege : un manager doit pouvoir le remplacer par le vrai flux du match.
    $isPlaceholder = $cur && strpos((string) ($cur[0]['url'] ?? ''), '127.0.0.1:8899') !== false;
    $healthy = $cur && !$isPlaceholder && $cur[0]['status'] === 'running' && (int) $cur[0]['bitrate'] > 0;
    if ($healthy && empty($_GET['force'])) {
        logline("REFUS dynamic-url slot=$slot xc=$xcId : chaîne en lecture (bitrate={$cur[0]['bitrate']}, statut {$cur[0]['status']})");
        out(['ok' => false, 'error' => 'stream is online, not restarted (use ?force=1)', 'id' => $slot, 'xc_id' => $xcId, 'status' => $cur[0]['status']], 409);
    }
    $body = ['input_urls' => array_values(array_map('strval', $urls))];
    $action = 'update'; $GLOBALS['restartAfterUpdate'] = true;
} else { $body = null; }
if ($action === 'update') {
    $body ??= json_decode((string) file_get_contents('php://input'), true) ?: [];
    $set = []; $args = [];
    if (array_key_exists('input_urls', $body) && is_array($body['input_urls'])) {
        // relais local XAccel (http://127.0.0.1/stream/<slot>/channel.ts) -> sortie HLS locale de la chaîne XC_VM primaire sur le nœud
        $urls = array_map(function ($u) use ($slot2xc) {
            if (preg_match('#^https?://(127\.0\.0\.1|localhost)(:\d+)?/stream/(\d+)/channel\.ts#', (string) $u, $mm) && isset($slot2xc[(int) $mm[3]])) {
                return '/home/xc_vm/content/streams/' . $slot2xc[(int) $mm[3]] . '_.m3u8';
            }
            return (string) $u;
        }, $body['input_urls']);
        $set[] = 'stream_source = ?'; $args[] = json_encode(array_values($urls)); $body['input_urls'] = $urls;
    }
    if (array_key_exists('name', $body) && is_string($body['name']) && $body['name'] !== '') { $set[] = 'stream_display_name = ?'; $args[] = $body['name']; }
    logline(($writes ? 'UPDATE' : 'DRY update') . " slot=$slot xc=$xcId " . json_encode($body, JSON_UNESCAPED_SLASHES));
    if (!$set) { out(['ok' => false, 'error' => 'nothing to update']); }
    if (!$writes) { out(['ok' => true, 'dry_run' => true, 'id' => $slot, 'xc_id' => $xcId]); }
    $args[] = $xcId;
    $db->query('UPDATE streams SET ' . implode(', ', $set) . ' WHERE id = ?', ...$args);
    EventDispatcher::dispatch(new StreamsChangedEvent([$xcId]));
    // pousser tout de suite au nœud (sinon push au shutdown) et lui laisser le temps d'appliquer la nouvelle source avant le start qui suit
    if (class_exists(StreamPush::class)) { StreamPush::flush(); }
    usleep((int) (($cfg['apply_wait_ms'] ?? 2500) * 1000));
    if (!empty($GLOBALS['restartAfterUpdate'])) {
        $res = [];
        foreach (['stop', 'start'] as $fn) { $res[$fn] = StreamControlDispatcher::request(['action' => 'stream', 'sub' => $fn, 'stream_ids' => [$xcId], 'servers' => [serverFor($xcId)]], 10); if ($fn === 'stop') { sleep(1); } }
        out(['ok' => true, 'id' => $slot, 'xc_id' => $xcId, 'action' => 'dynamic-url', 'result' => $res]);
    }
    out(['ok' => true, 'id' => $slot, 'xc_id' => $xcId, 'updated' => array_keys(array_intersect_key($body, ['input_urls' => 1, 'name' => 1]))]);
}
// start / stop / restart
logline(($writes ? strtoupper($action) : 'DRY ' . $action) . " slot=$slot xc=$xcId");
if (!$writes) { out(['ok' => true, 'dry_run' => true, 'id' => $slot, 'xc_id' => $xcId, 'action' => $action]); }
$res = [];
foreach ($action === 'restart' ? ['stop', 'start'] : [$action] as $fn) {
    $res[$fn] = StreamControlDispatcher::request(['action' => 'stream', 'sub' => $fn, 'stream_ids' => [$xcId], 'servers' => [serverFor($xcId)]], 10);
    if ($action === 'restart' && $fn === 'stop') { sleep(1); }
}
out(['ok' => true, 'id' => $slot, 'xc_id' => $xcId, 'action' => $action, 'result' => $res]);
