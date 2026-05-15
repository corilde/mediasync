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

    $srcRemote = !empty($rsync['sourceHost']);
    $dstRemote = !empty($rsync['destHost']);

    // ── Validació prèvia ────────────────────────────────────────────
    // Si el path de Radarr/Sonarr ja és absolut, el fem servir tal qual.
    // Si és relatiu, hi posem davant sourceBasePath.
    $srcPath = (str_starts_with($itemPath, '/'))
        ? $itemPath
        : rtrim($rsync['sourceBasePath'],'/').'/'.$itemPath;

    $subDir   = ($type === 'movie') ? 'movies' : 'series';
    $destPath = rtrim($rsync['destBasePath'],'/')."/{$subDir}";

    if ($srcRemote && empty($rsync['sourceUser'])) {
        return ['error' => "Origen remot però sense usuari SSH (SOURCE_USER buit). Revisa ⚙ CONFIG."];
    }
    if ($dstRemote && empty($rsync['destUser'])) {
        return ['error' => "Destí remot però sense usuari SSH (DEST_USER buit). Revisa ⚙ CONFIG."];
    }
    if (!$srcRemote && !file_exists($srcPath)) {
        return ['error' => "Ruta origen local no existeix: {$srcPath}. ¿Has muntat el volum correctament? ¿Has deixat SOURCE_HOST buit per error?"];
    }
    if (!$dstRemote && !is_dir($destPath)) {
        if (!@mkdir($destPath, 0755, true)) {
            return ['error' => "No s'ha pogut crear el directori destí local: {$destPath}"];
        }
    }

    // ── Opcions SSH ─────────────────────────────────────────────────
    $sshKeyOpt = '';
    if (!empty($rsync['sshKey']) && file_exists($rsync['sshKey'])) {
        $sshKeyOpt = ' -i '.escapeshellarg($rsync['sshKey']);
    }
    $sshArgs = 'ssh'.$sshKeyOpt.' -o StrictHostKeyChecking=no -o BatchMode=yes';
    $sshE    = '-e '.escapeshellarg($sshArgs);

    // ── Construcció de la comanda segons topologia ──────────────────
    $jobId    = uniqid('job_', true);
    $logFile  = LOGS_DIR."/rsync_{$jobId}.log";
    $exitFile = $logFile.'.exit';

    if ($srcRemote && $dstRemote) {
        // Remot ↔ Remot: rsync no ho permet en una sola comanda.
        // Solució: SSH a l'origen i executar rsync des d'allà cap al destí.
        // (Requereix que l'origen pugui SSH-ar al destí amb una clau autoritzada.)
        $srcFull = $srcPath;
        $dstFull = "{$rsync['destUser']}@{$rsync['destHost']}:{$destPath}";
        $remoteCmd = sprintf('rsync %s %s %s %s',
            $rsync['extraOptions'],
            $sshE,
            escapeshellarg($srcFull),
            escapeshellarg($dstFull)
        );
        $rsyncCmd = sprintf('ssh%s -o StrictHostKeyChecking=no -o BatchMode=yes %s %s',
            $sshKeyOpt,
            escapeshellarg("{$rsync['sourceUser']}@{$rsync['sourceHost']}"),
            escapeshellarg($remoteCmd)
        );
        $srcDisplay = "{$rsync['sourceUser']}@{$rsync['sourceHost']}:{$srcFull}";
        $dstDisplay = $dstFull;
    } else {
        if ($srcRemote) {
            $srcFull = "{$rsync['sourceUser']}@{$rsync['sourceHost']}:{$srcPath}";
        } else {
            $srcFull = $srcPath;
        }
        if ($dstRemote) {
            $dstFull = "{$rsync['destUser']}@{$rsync['destHost']}:{$destPath}";
        } else {
            $dstFull = $destPath;
        }
        $rsyncCmd = sprintf('rsync %s %s %s %s',
            $rsync['extraOptions'],
            ($srcRemote || $dstRemote) ? $sshE : '',
            escapeshellarg($srcFull),
            escapeshellarg($dstFull)
        );
        $srcDisplay = $srcFull;
        $dstDisplay = $dstFull;
    }

    // ── Llançament en background capturant exit code ───────────────
    // Wrapper: ( cmd > log 2>&1 ; echo $? > exitfile ) & echo $!
    $wrapper = sprintf('( %s > %s 2>&1 ; echo $? > %s ) & echo $!',
        $rsyncCmd,
        escapeshellarg($logFile),
        escapeshellarg($exitFile)
    );

    // Cal forçar bash perquè /bin/sh en alguns sistemes no gestiona
    // bé el subshell + & + echo $! retornant el PID correcte.
    $cmd = '/bin/bash -c '.escapeshellarg($wrapper);

    $pid = trim(shell_exec($cmd) ?? '');
    if (!is_numeric($pid)) {
        return ['error' => 'No s\'ha pogut iniciar rsync (PID invàlid)'];
    }

    $job = [
        'id'       => $jobId, 'pid' => $pid,
        'title'    => $title, 'type' => $type,
        'source'   => $srcDisplay, 'dest' => $dstDisplay,
        'status'   => 'running',
        'started'  => date('Y-m-d H:i:s'), 'finished' => null,
        'logFile'  => $logFile,
        'exitFile' => $exitFile,
        'exitCode' => null,
    ];
    saveJob($job);
    return ['success' => true, 'jobId' => $jobId, 'pid' => $pid];
}

/**
 * Comprova si una feina ha acabat i, si és així, llegeix el seu exit code
 * per decidir si ha estat 'success' (0) o 'failed' (≠0).
 * Retorna la $job modificada (no la desa).
 */
function refreshJobStatus(array $job): array {
    if ($job['status'] !== 'running') return $job;
    if (empty($job['pid'])) return $job;

    $check = trim(shell_exec("ps -p ".escapeshellarg($job['pid'])." -o pid= 2>/dev/null") ?? '');
    if (!empty($check)) return $job; // encara corre

    // Procés acabat — intentar llegir l'exit code
    $code = null;
    if (!empty($job['exitFile']) && file_exists($job['exitFile'])) {
        $raw = trim(file_get_contents($job['exitFile']));
        if ($raw !== '' && is_numeric($raw)) $code = (int)$raw;
    }

    $job['exitCode'] = $code;
    $job['finished'] = date('Y-m-d H:i:s');
    if ($code === 0) {
        $job['status'] = 'success';
    } else if ($code === null) {
        // PID s'ha mort però no s'ha escrit exit code: probablement matat.
        $job['status'] = 'failed';
    } else {
        $job['status'] = 'failed';
    }
    return $job;
}

function getJobStatus(string $jobId): array {
    if (empty($jobId)) return ['error' => 'ID buit'];
    $jobs = loadJobs();
    if (!isset($jobs[$jobId])) return ['error' => 'Job no trobat'];

    $job    = $jobs[$jobId];
    $before = $job['status'];
    $job    = refreshJobStatus($job);
    if ($job['status'] !== $before) updateJob($job);

    $log = '';
    if (!empty($job['logFile']) && file_exists($job['logFile'])) {
        $lines = file($job['logFile']) ?: [];
        $log   = implode('', array_slice($lines, -30));
    }
    return array_merge($job, ['log' => $log]);
}

function getJobs(): array {
    $jobs    = loadJobs();
    $changed = false;
    foreach ($jobs as $id => $job) {
        $updated = refreshJobStatus($job);
        if ($updated['status'] !== $job['status']) {
            $jobs[$id] = $updated;
            $changed   = true;
        }
    }
    if ($changed) saveAllJobs($jobs);
    return array_values($jobs);
}

function loadJobs(): array {
    if (!file_exists(JOBS_FILE)) return [];
    return json_decode(file_get_contents(JOBS_FILE), true) ?? [];
}
function saveJob(array $job): void { $jobs = loadJobs(); $jobs[$job['id']] = $job; saveAllJobs($jobs); }
function updateJob(array $job): void { saveJob($job); }
function saveAllJobs(array $jobs): void { file_put_contents(JOBS_FILE, json_encode($jobs, JSON_PRETTY_PRINT)); }
