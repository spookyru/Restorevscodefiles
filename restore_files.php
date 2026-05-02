<?php
/**
 * VS Code History Browser — restore_files.php
 * Доступен только с localhost.
 */
declare(strict_types=1);

// ── Защита: только с локала ─────────────────────────────────────────────────
if (!in_array($_SERVER['REMOTE_ADDR'] ?? '', ['127.0.0.1', '::1', '::ffff:127.0.0.1'], true)) {
    http_response_code(403);
    die('403 Forbidden');
}

const HISTORY_ROOT = 'C:/Users/_USERNAMES_/AppData/Roaming/Code/User/History';

// ── Скачивание файла ─────────────────────────────────────────────────────────
if (($_GET['action'] ?? '') === 'download') {
    $dirName  = basename((string)($_GET['dir']  ?? ''));
    $fileId   = basename((string)($_GET['id']   ?? ''));
    $origName = basename(rawurldecode((string)($_GET['name'] ?? 'file.txt')));

    $filePath = HISTORY_ROOT . '/' . $dirName . '/' . $fileId;
    $realFile = realpath($filePath);
    $realRoot = realpath(HISTORY_ROOT);

    if (!$realFile || !$realRoot || strncmp($realFile, $realRoot, strlen($realRoot)) !== 0 || !is_file($realFile)) {
        http_response_code(404);
        die('File not found');
    }

    // Безопасное имя файла
    $origName = preg_replace('/[^\w\.\-]/', '_', $origName);
    if ($origName === '') $origName = 'file.txt';

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $origName . '"');
    header('Content-Length: ' . filesize($realFile));
    header('Cache-Control: no-cache');
    readfile($realFile);
    exit;
}

// ── Сбор всех записей из History ────────────────────────────────────────────
$allEntries = [];

if (is_dir(HISTORY_ROOT)) {
    foreach (scandir(HISTORY_ROOT) as $dirName) {
        if ($dirName === '.' || $dirName === '..') continue;
        $fullDir  = HISTORY_ROOT . '/' . $dirName;
        if (!is_dir($fullDir)) continue;

        $jsonFile = $fullDir . '/entries.json';
        if (!file_exists($jsonFile)) continue;

        $json = json_decode(file_get_contents($jsonFile), true);
        if (!is_array($json)) continue;

        // Формат: { "version":1, "resource":"file:///...", "entries":[...] }
        $resource = $json['resource'] ?? '';
        $entries  = $json['entries']  ?? [];

        // Декодировать путь ресурса: file:///c%3A/... → C:/...
        $resPath = rawurldecode(preg_replace('#^file:///+#i', '', $resource));
        $resPath = ltrim(str_replace('\\', '/', $resPath), '/');
        // Windows: вернуть двоеточие после буквы диска
        $resPath = preg_replace('#^([a-zA-Z])/#', '$1:/', $resPath);

        $originalName = basename($resPath);
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));

        foreach ($entries as $entry) {
            if (!isset($entry['id'])) continue;

            $fileId   = $entry['id'];
            $histFile = $fullDir . '/' . $fileId;
            if (!file_exists($histFile)) continue;

            $ts       = isset($entry['timestamp']) ? (int)($entry['timestamp'] / 1000) : (int)filemtime($histFile);
            $source   = $entry['source'] ?? '';
            // Убрать "Chat Edit: " префикс для отображения описания
            $descShort = preg_replace("#^Chat Edit: '(.{0,120}).*#su", '$1…', $source);
            if ($descShort === $source) {
                $descShort = mb_substr($source, 0, 120);
                if (mb_strlen($source) > 120) $descShort .= '…';
            }

            $allEntries[] = [
                'dir'       => $dirName,
                'id'        => $fileId,
                'origName'  => $originalName,
                'origPath'  => $resPath,
                'ext'       => $ext,
                'ts'        => $ts,
                'date'      => date('Y-m-d', $ts),
                'datetime'  => date('d.m.Y H:i', $ts),
                'size'      => filesize($histFile),
                'source'    => $descShort,
                'sourceRaw' => $source,
            ];
        }
    }
}

// Сортировка: по умолчанию — новые сверху
usort($allEntries, static fn($a, $b) => $b['ts'] - $a['ts']);

// ── Уникальные расширения для фильтра ───────────────────────────────────────
$allExts = array_values(array_unique(array_filter(array_column($allEntries, 'ext'))));
sort($allExts);

// Диапазон дат
$minDate = $allEntries ? date('Y-m-d', min(array_column($allEntries, 'ts'))) : '';
$maxDate = $allEntries ? date('Y-m-d', max(array_column($allEntries, 'ts'))) : '';

$totalCount = count($allEntries);

// Передать данные в JS как JSON (экранируем для безопасной вставки в <script>)
$jsonData = json_encode($allEntries, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>VS Code History Browser</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:system-ui,sans-serif;font-size:13px;background:#1a1a2e;color:#e0e0e0;min-height:100vh}
header{background:#16213e;padding:14px 20px;border-bottom:2px solid #0f3460;display:flex;align-items:center;gap:16px;flex-wrap:wrap}
header h1{font-size:16px;color:#e94560;font-weight:700;white-space:nowrap}
.badge{background:#0f3460;color:#a8d8ea;padding:3px 8px;border-radius:12px;font-size:11px;white-space:nowrap}
.filters{padding:12px 20px;background:#16213e;border-bottom:1px solid #0f3460;display:flex;gap:10px;flex-wrap:wrap;align-items:flex-end}
.filter-group{display:flex;flex-direction:column;gap:4px}
.filter-group label{font-size:11px;color:#a0a0b0;text-transform:uppercase;letter-spacing:.5px}
input[type=text],input[type=date],select{background:#0d0d1a;border:1px solid #0f3460;color:#e0e0e0;padding:5px 9px;border-radius:5px;font-size:12px;outline:none}
input[type=text]:focus,input[type=date]:focus,select:focus{border-color:#e94560}
input[type=text]{width:220px}
input[type=date]{width:140px}
select{min-width:100px}
.btn{padding:5px 14px;border-radius:5px;border:none;cursor:pointer;font-size:12px;font-weight:600;transition:background .15s}
.btn-reset{background:#2a2a3e;color:#a0a0b0}.btn-reset:hover{background:#3a3a5e}
.status-bar{padding:7px 20px;background:#12122a;font-size:11px;color:#607090;border-bottom:1px solid #0f3460}
.status-bar span{color:#a8d8ea;font-weight:600}

.table-wrap{overflow-x:auto;padding:0 20px 20px}
table{width:100%;border-collapse:collapse;margin-top:12px}
th{background:#0f3460;color:#a8d8ea;text-align:left;padding:8px 10px;font-size:11px;text-transform:uppercase;letter-spacing:.5px;position:sticky;top:0;z-index:10;white-space:nowrap;cursor:pointer;user-select:none}
th:hover{background:#1a4a80}
th .sort-icon{opacity:.5;font-size:9px;margin-left:4px}
th.active .sort-icon{opacity:1;color:#e94560}
td{padding:7px 10px;border-bottom:1px solid #1a1a3a;vertical-align:top}
tr:hover td{background:#1e1e3a}
.filename{font-weight:700;color:#e8e8ff;font-family:monospace;font-size:12px;white-space:nowrap}
.filepath{color:#607090;font-size:10px;word-break:break-all;max-width:320px;line-height:1.4}
.ext-badge{display:inline-block;padding:1px 6px;border-radius:3px;font-size:10px;font-weight:700;font-family:monospace;margin-left:6px}
.ext-php{background:#4f3a6e;color:#d4a6ff}
.ext-js{background:#3d3a10;color:#f0d060}
.ext-css{background:#0f3a3a;color:#60d4d4}
.ext-html{background:#3a1a1a;color:#f06060}
.ext-other{background:#2a2a3a;color:#909090}
.datetime{white-space:nowrap;color:#a8d8ea;font-size:11px}
.size{color:#607090;font-size:11px;white-space:nowrap}
.source-text{color:#808098;font-size:10px;max-width:280px;line-height:1.4;overflow:hidden;display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical}
.source-text.undo{color:#505060;font-style:italic}
.btn-dl{background:#0f3460;color:#a8d8ea;padding:4px 12px;font-size:11px;border-radius:4px;white-space:nowrap;text-decoration:none;display:inline-block;transition:background .15s}
.btn-dl:hover{background:#e94560;color:#fff}
.no-results{text-align:center;padding:40px;color:#607090;font-size:14px}
.path-full{display:none;color:#505070;font-size:10px;word-break:break-all}

/* Modal для полного источника */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.7);z-index:1000;justify-content:center;align-items:center}
.modal-overlay.open{display:flex}
.modal{background:#1a1a2e;border:1px solid #0f3460;border-radius:8px;max-width:640px;width:90%;max-height:80vh;display:flex;flex-direction:column}
.modal-header{padding:12px 16px;border-bottom:1px solid #0f3460;display:flex;justify-content:space-between;align-items:center}
.modal-header h3{color:#a8d8ea;font-size:13px}
.modal-close{background:none;border:none;color:#607090;cursor:pointer;font-size:18px;line-height:1}
.modal-close:hover{color:#e94560}
.modal-body{padding:16px;overflow-y:auto;font-size:12px;color:#c0c0d0;line-height:1.6;white-space:pre-wrap;word-break:break-word}
</style>
</head>
<body>

<header>
    <h1>VS Code History Browser</h1>
    <span class="badge"><?= $totalCount ?> записей</span>
    <span class="badge"><?= HISTORY_ROOT ?></span>
</header>

<div class="filters">
    <div class="filter-group">
        <label>Поиск по файлу</label>
        <input type="text" id="f-name" placeholder="filename или путь…" oninput="applyFilters()">
    </div>
    <div class="filter-group">
        <label>Расширение</label>
        <select id="f-ext" onchange="applyFilters()">
            <option value="">Все</option>
            <?php foreach ($allExts as $e): ?>
            <option value="<?= htmlspecialchars($e) ?>">.<?= htmlspecialchars($e) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="filter-group">
        <label>Дата с</label>
        <input type="date" id="f-date-from" value="<?= $minDate ?>" oninput="applyFilters()">
    </div>
    <div class="filter-group">
        <label>Дата по</label>
        <input type="date" id="f-date-to" value="<?= $maxDate ?>" oninput="applyFilters()">
    </div>
    <div class="filter-group">
        <label>&nbsp;</label>
        <button class="btn btn-reset" onclick="resetFilters()">Сбросить</button>
    </div>
</div>

<div class="status-bar" id="status-bar">
    Показано: <span id="count-shown"><?= $totalCount ?></span> из <?= $totalCount ?>
</div>

<div class="table-wrap">
<table id="hist-table">
<thead>
<tr>
    <th data-col="origName" onclick="sortBy(this)">Файл <span class="sort-icon">▼</span></th>
    <th data-col="origPath" onclick="sortBy(this)">Путь <span class="sort-icon">▼</span></th>
    <th data-col="ts" onclick="sortBy(this)" class="active">Дата/Время <span class="sort-icon">▼</span></th>
    <th data-col="size" onclick="sortBy(this)">Размер <span class="sort-icon">▼</span></th>
    <th>Описание изменения</th>
    <th>Скачать</th>
</tr>
</thead>
<tbody id="hist-body"></tbody>
</table>
<div class="no-results" id="no-results" style="display:none">Ничего не найдено по заданным фильтрам</div>
</div>

<!-- Modal -->
<div class="modal-overlay" id="modal" onclick="if(event.target===this)closeModal()">
<div class="modal">
    <div class="modal-header">
        <h3 id="modal-title">Описание изменения</h3>
        <button class="modal-close" onclick="closeModal()">✕</button>
    </div>
    <div class="modal-body" id="modal-body"></div>
</div>
</div>

<script>
const DATA = <?= $jsonData ?>;
const BASE_URL = '<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>';

let currentData = [...DATA];
let sortCol = 'ts';
let sortAsc  = false;

function extClass(ext) {
    const m = {php:'ext-php',js:'ext-js',css:'ext-css',html:'ext-html',scss:'ext-css'};
    return m[ext] || 'ext-other';
}

function fmtSize(bytes) {
    if (bytes < 1024) return bytes + ' b';
    if (bytes < 1048576) return (bytes/1024).toFixed(1) + ' KB';
    return (bytes/1048576).toFixed(1) + ' MB';
}

function buildRow(item) {
    const ec = extClass(item.ext);
    const nameEnc = encodeURIComponent(item.origName);
    const dlUrl = `${BASE_URL}?action=download&dir=${encodeURIComponent(item.dir)}&id=${encodeURIComponent(item.id)}&name=${nameEnc}`;

    const srcClass = (item.sourceRaw === 'undoRedo.source' || item.sourceRaw === '') ? 'source-text undo' : 'source-text';
    const srcText = item.sourceRaw === 'undoRedo.source' ? 'undo/redo' : (item.source || '—');
    const hasLong = item.sourceRaw && item.sourceRaw.length > 100 && item.sourceRaw !== 'undoRedo.source';

    // Путь: показываем только относительную часть от OSPanelNew
    let displayPath = item.origPath;
    const m = displayPath.match(/home[/\\](.+)/i);
    if (m) displayPath = m[1];

    return `<tr data-ts="${item.ts}" data-name="${item.origName.toLowerCase()}" data-path="${item.origPath.toLowerCase()}" data-ext="${item.ext}" data-date="${item.date}">
      <td>
        <span class="filename">${escHtml(item.origName)}</span>
        <span class="ext-badge ${ec}">.${escHtml(item.ext)}</span>
      </td>
      <td><span class="filepath" title="${escHtml(item.origPath)}">${escHtml(displayPath)}</span></td>
      <td><span class="datetime">${escHtml(item.datetime)}</span></td>
      <td><span class="size">${fmtSize(item.size)}</span></td>
      <td>
        <span class="${srcClass}">${escHtml(srcText)}</span>
        ${hasLong ? `<br><a href="#" style="font-size:10px;color:#607090" onclick="showSource(${DATA.indexOf(item)});return false">подробнее</a>` : ''}
      </td>
      <td><a class="btn-dl" href="${dlUrl}" download="${escHtml(item.origName)}">⬇ Скачать</a></td>
    </tr>`;
}

function escHtml(s) {
    return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}

function render(data) {
    const tbody = document.getElementById('hist-body');
    const noRes = document.getElementById('no-results');
    if (!data.length) {
        tbody.innerHTML = '';
        noRes.style.display = '';
    } else {
        tbody.innerHTML = data.map(buildRow).join('');
        noRes.style.display = 'none';
    }
    document.getElementById('count-shown').textContent = data.length;
}

function applyFilters() {
    const name  = document.getElementById('f-name').value.toLowerCase().trim();
    const ext   = document.getElementById('f-ext').value.toLowerCase();
    const from  = document.getElementById('f-date-from').value;
    const to    = document.getElementById('f-date-to').value;

    let filtered = DATA.filter(item => {
        if (name && !item.origName.toLowerCase().includes(name) && !item.origPath.toLowerCase().includes(name)) return false;
        if (ext  && item.ext !== ext) return false;
        if (from && item.date < from) return false;
        if (to   && item.date > to)   return false;
        return true;
    });

    // Применить текущую сортировку
    filtered = doSort(filtered, sortCol, sortAsc);
    currentData = filtered;
    render(filtered);
}

function doSort(data, col, asc) {
    return [...data].sort((a, b) => {
        const va = a[col], vb = b[col];
        if (typeof va === 'number') return asc ? va - vb : vb - va;
        return asc ? String(va).localeCompare(String(vb)) : String(vb).localeCompare(String(va));
    });
}

function sortBy(th) {
    const col = th.dataset.col;
    if (sortCol === col) {
        sortAsc = !sortAsc;
    } else {
        sortCol = col;
        sortAsc = false;
    }
    document.querySelectorAll('th').forEach(t => t.classList.remove('active'));
    th.classList.add('active');
    th.querySelector('.sort-icon').textContent = sortAsc ? '▲' : '▼';
    currentData = doSort(currentData, sortCol, sortAsc);
    render(currentData);
}

function resetFilters() {
    document.getElementById('f-name').value = '';
    document.getElementById('f-ext').value = '';
    document.getElementById('f-date-from').value = '<?= $minDate ?>';
    document.getElementById('f-date-to').value = '<?= $maxDate ?>';
    applyFilters();
}

function showSource(idx) {
    const item = DATA[idx];
    document.getElementById('modal-title').textContent = item.origName + ' — ' + item.datetime;
    document.getElementById('modal-body').textContent = item.sourceRaw || '(нет описания)';
    document.getElementById('modal').classList.add('open');
}

function closeModal() {
    document.getElementById('modal').classList.remove('open');
}

// Инициальный рендер (данные уже отсортированы по ts desc на PHP)
currentData = [...DATA];
render(currentData);
</script>
</body>
</html>
