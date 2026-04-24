<?php
ini_set('memory_limit', '256M');
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { exit(0); }

function envOr(string $key, string $default): string {
    $v = getenv($key);
    return ($v !== false && $v !== '') ? $v : $default;
}

$bodyRaw  = file_get_contents('php://input');
$body     = $bodyRaw ? (json_decode($bodyRaw, true) ?? []) : [];
$frontCfg = $body['cfg'] ?? [];

function frontOr(array $fc, string $key, string $envKey, string $default): string {
    if (!empty($fc[$key])) return $fc[$key];
    return envOr($envKey, $default);
}

$config = [
    'radarr' => [
        'url'    => !empty($_GET['radarr_url']) ? $_GET['radarr_url'] : envOr('RADARR_URL', 'http://localhost:7878'),
        'apiKey' => !empty($_GET['radarr_key']) ? $_GET['radarr_key'] : envOr('RADARR_API_KEY', ''),
    ],
    'sonarr' => [
        'url'    => !empty($_GET['sonarr_url']) ? $_GET['sonarr_url'] : envOr('SONARR_URL', 'http://localhost:8989'),
        'apiKey' => !empty($_GET['sonarr_key']) ? $_GET['sonarr_key'] : envOr('SONARR_API_KEY', ''),
    ],
    'rsync' => [
        'sourceUser'     => frontOr($frontCfg, 'srcUser',    'SOURCE_USER',      'user'),
        'sourceHost'     => frontOr($frontCfg, 'srcHost',    'SOURCE_HOST',      ''),
        'sourceBasePath' => frontOr($frontCfg, 'srcPath',    'SOURCE_BASE_PATH', '/media'),
        'destUser'       => frontOr($frontCfg, 'dstUser',    'DEST_USER',        ''),
        'destHost'       => frontOr($frontCfg, 'dstHost',    'DEST_HOST',        ''),
        'destBasePath'   => frontOr($frontCfg, 'dstPath',    'DEST_BASE_PATH',   '/downloads'),
        'sshKey'         => frontOr($frontCfg, 'sshKey',     'SSH_KEY',          '/var/www/.ssh/id_rsa'),
        'extraOptions'   => frontOr($frontCfg, 'rsyncExtra', 'RSYNC_EXTRA',      '-avz --progress'),
    ],
];

define('JOBS_FILE', '/var/www/html/data/rsync_jobs.json');
define('LOGS_DIR',  '/var/www/html/logs');
if (!is_dir('/var/www/html/data')) @mkdir('/var/www/html/data', 0755, true);
if (!is_dir(LOGS_DIR))             @mkdir(LOGS_DIR, 0755, true);

$action = $_GET['action'] ?? '';
switch ($action) {
    case 'movies':     echo json_encode(fetchRadarr($config));          break;
    case 'series':     echo json_encode(fetchSonarr($config));          break;
    case 'download':   echo json_encode(startRsync($body, $config));    break;
    case 'jobs':       echo json_encode(getJobs());                     break;
    case 'job_status': echo json_encode(getJobStatus($_GET['id']??'')); break;
    case 'health':     echo json_encode(['status'=>'ok','time'=>date('c')]); break;
    default:
        http_response_code(404);
        echo json_encode(['error' => 'Accio no trobada']);
}

function apiRequest(string $url, string $apiKey): array {
    if (empty($apiKey)) return ['error' => 'API Key buida — configura-la a ⚙ CONFIG'];
    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => ["X-Api-Key: $apiKey"],
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_FOLLOWLOCATION => true,
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error    = curl_error($ch);
    curl_close($ch);
    if ($error)            return ['error' => "cURL: $error"];
    if ($httpCode === 401) return ['error' => 'API Key incorrecta (401)'];
    if ($httpCode === 400) return ['error' => "Error 400 — resposta: " . substr($response, 0, 200)];
    if ($httpCode >= 400)  return ['error' => "Error HTTP $httpCode — " . substr($response, 0, 200)];
    $decoded = json_decode($response, true);
    if ($decoded === null) return ['error' => 'JSON invalid: ' . substr($response, 0, 200)];
    return $decoded;
}

function fetchRadarr(array $cfg): array {
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 100;

    // Radarr v3 doesn't have native pagination on /movie, so we fetch all
    // but only process/return the requested page to save memory
    $data = apiRequest($cfg['radarr']['url'].'/api/v3/movie', $cfg['radarr']['apiKey']);
    if (isset($data['error'])) return $data;

    // Filtre de cerca
    $search = trim($_GET['search'] ?? '');
    if ($search !== '') {
        $data = array_filter($data, fn($m) => stripos($m['title'] ?? '', $search) !== false);
        $data = array_values($data);
    }

    // Sort by title
    usort($data, fn($a, $b) => strcmp($a['title'] ?? '', $b['title'] ?? ''));

    $total  = count($data);
    $offset = ($page - 1) * $perPage;
    $slice  = array_slice($data, $offset, $perPage);

    $items = array_values(array_map(fn($m) => [
        'id'      => $m['id'],
        'title'   => $m['title'],
        'year'    => $m['year'] ?? '',
        'status'  => $m['status'] ?? '',
        'hasFile' => $m['hasFile'] ?? false,
        'path'    => $m['path'] ?? '',
        'poster'  => collectImage($m['images'] ?? [], 'poster', $cfg['radarr']['url']),
        'type'    => 'movie',
    ], $slice));

    return [
        'items'    => $items,
        'total'    => $total,
        'page'     => $page,
        'perPage'  => $perPage,
        'pages'    => (int)ceil($total / $perPage),
    ];
}

function fetchSonarr(array $cfg): array {
    $page    = max(1, (int)($_GET['page'] ?? 1));
    $perPage = 100;

    $data = apiRequest($cfg['sonarr']['url'].'/api/v3/series', $cfg['sonarr']['apiKey']);
    if (isset($data['error'])) return $data;

    // Filtre de cerca
    $search = trim($_GET['search'] ?? '');
    if ($search !== '') {
        $data = array_filter($data, fn($s) => stripos($s['title'] ?? '', $search) !== false);
        $data = array_values($data);
    }

    usort($data, fn($a, $b) => strcmp($a['title'] ?? '', $b['title'] ?? ''));

    $total  = count($data);
    $offset = ($page - 1) * $perPage;
    $slice  = array_slice($data, $offset, $perPage);

    $items = array_values(array_map(fn($s) => [
        'id'      => $s['id'],
        'title'   => $s['title'],
        'year'    => $s['year'] ?? '',
        'status'  => $s['status'] ?? '',
        'hasFile' => ($s['statistics']['episodeFileCount'] ?? 0) > 0,
        'path'    => $s['path'] ?? '',
        'poster'  => collectImage($s['images'] ?? [], 'poster', $cfg['sonarr']['url']),
        'type'    => 'series',
    ], $slice));

    return [
        'items'    => $items,
        'total'    => $total,
        'page'     => $page,
        'perPage'  => $perPage,
        'pages'    => (int)ceil($total / $perPage),
    ];
}

function collectImage(array $images, string $type, string $baseUrl): string {
    foreach ($images as $img) {
        if (($img['coverType'] ?? '') === $type) {
            $url = $img['remoteUrl'] ?? $img['url'] ?? '';
            if ($url && !str_starts_with($url, 'http')) $url = rtrim($baseUrl,'/').$url;
            return $url;
        }
    }
    return '';
}

function startRsync(array $body, array $cfg): array {
    $itemPath = trim($body['path'] ?? '');
    $title    = $body['title'] ?? 'Desconegut';
    $type     = $body['type']  ?? 'movie';
    if (empty($itemPath)) return ['error' => 'Ruta buida'];

    $rsync = $cfg['rsync'];

    if (!empty($rsync['sourceHost'])) {
        $srcFull = "{$rsync['sourceUser']}@{$rsync['sourceHost']}:".rtrim($rsync['sourceBasePath'],'/').'/'.ltrim($itemPath,'/');
    } else {
        $srcFull = rtrim($rsync['sourceBasePath'],'/').'/'.ltrim($itemPath,'/');
    }

    $subDir   = ($type === 'movie') ? 'movies' : 'series';
    $destPath = rtrim($rsync['destBasePath'],'/')."/{$subDir}";

    if (!empty($rsync['destHost'])) {
        $dstFull = "{$rsync['destUser']}@{$rsync['destHost']}:{$destPath}";
    } else {
        if (!is_dir($destPath)) @mkdir($destPath, 0755, true);
        $dstFull = $destPath;
    }

    $sshKeyOpt = '';
    if (!empty($rsync['sshKey']) && file_exists($rsync['sshKey'])) {
        $sshKeyOpt = ' -i '.escapeshellarg($rsync['sshKey']);
    }
    $sshE = '-e '.escapeshellarg('ssh'.$sshKeyOpt.' -o StrictHostKeyChecking=no -o BatchMode=yes');

    $jobId   = uniqid('job_', true);
    $logFile = LOGS_DIR."/rsync_{$jobId}.log";

    $cmd = sprintf('rsync %s %s %s %s > %s 2>&1 & echo $!',
        $rsync['extraOptions'],
        $sshE,
        escapeshellarg($srcFull),
        escapeshellarg($dstFull),
        escapeshellarg($logFile)
    );

    $pid = trim(shell_exec($cmd) ?? '');
    if (!is_numeric($pid)) return ['error' => 'No s\'ha pogut iniciar rsync'];

    $job = [
        'id'       => $jobId, 'pid' => $pid,
        'title'    => $title, 'type' => $type,
        'source'   => $srcFull, 'dest' => $dstFull,
        'status'   => 'running',
        'started'  => date('Y-m-d H:i:s'), 'finished' => null,
        'logFile'  => $logFile,
    ];
    saveJob($job);
    return ['success' => true, 'jobId' => $jobId, 'pid' => $pid];
}

function getJobStatus(string $jobId): array {
    if (empty($jobId)) return ['error' => 'ID buit'];
    $jobs = loadJobs();
    if (!isset($jobs[$jobId])) return ['error' => 'Job no trobat'];
    $job = $jobs[$jobId];
    if ($job['status'] === 'running' && !empty($job['pid'])) {
        $check = trim(shell_exec("ps -p {$job['pid']} -o pid= 2>/dev/null") ?? '');
        if (empty($check)) {
            $job['status'] = 'finished'; $job['finished'] = date('Y-m-d H:i:s');
            updateJob($job);
        }
    }
    $log = '';
    if (!empty($job['logFile']) && file_exists($job['logFile'])) {
        $lines = file($job['logFile']) ?: [];
        $log   = implode('', array_slice($lines, -30));
    }
    return array_merge($job, ['log' => $log]);
}

function getJobs(): array {
    $jobs = loadJobs();
    foreach ($jobs as &$job) {
        if ($job['status'] === 'running' && !empty($job['pid'])) {
            $check = trim(shell_exec("ps -p {$job['pid']} -o pid= 2>/dev/null") ?? '');
            if (empty($check)) { $job['status'] = 'finished'; $job['finished'] = date('Y-m-d H:i:s'); }
        }
    }
    saveAllJobs($jobs);
    return array_values($jobs);
}

function loadJobs(): array {
    if (!file_exists(JOBS_FILE)) return [];
    return json_decode(file_get_contents(JOBS_FILE), true) ?? [];
}
function saveJob(array $job): void { $jobs = loadJobs(); $jobs[$job['id']] = $job; saveAllJobs($jobs); }
function updateJob(array $job): void { saveJob($job); }
function saveAllJobs(array $jobs): void { file_put_contents(JOBS_FILE, json_encode($jobs, JSON_PRETTY_PRINT)); }
