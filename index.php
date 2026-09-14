<?php
/**
 * tokps — retrospective throughput (tokens/s) dashboard for opencode LLM calls.
 *
 * Source: ~/.local/share/opencode/opencode.db (WAL; read-only, never blocks the writer).
 * Metric (empirically verified Aug-2026, see tokps.py):
 *   - The time.created→time.completed span of an assistant message INCLUDES
 *     tool execution (bash/read/MCP...), which happens at the end of the span.
 *   - Pure LLM window = created → first tool start (or completed when no tools).
 *   - Real generation = tokens.output + tokens.reasoning (reasoning dominates
 *     in thinking models; ignoring it underestimates ~2x).
 *   - tok/s = generation / pure_LLM_window. Tool time is reported separately.
 * Forensic CLI: tokps.py (same directory).
 */
declare(strict_types=1);

function db(): PDO {
    $pdo = new PDO('sqlite:file:' . DB . '?mode=ro', null, null, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec('PRAGMA busy_timeout=3000');
    $pdo->exec('PRAGMA query_only=1');
    return $pdo;
}

/** Mensajes assistant completados con ventana LLM pura y tool_ms agregados. */
function fetch_msgs(PDO $pdo, int $days): array {
    $sql = "WITH a AS (
      SELECT id, session_id, time_created tc, data
      FROM message
      WHERE json_extract(data,'$.role')='assistant'
        AND json_extract(data,'$.time.completed') IS NOT NULL
        AND time_created > (strftime('%s','now')-:days*86400)*1000
    ),
    tools AS (
      SELECT message_id,
             MIN(CAST(json_extract(data,'$.state.time.start') AS INTEGER)) t_first,
             SUM(MAX(CAST(json_extract(data,'$.state.time.end') AS INTEGER),0)
               - MAX(CAST(json_extract(data,'$.state.time.start') AS INTEGER),0)) tool_ms,
             COUNT(*) n_tools
      FROM part
      WHERE json_extract(data,'$.type')='tool'
        AND json_extract(data,'$.state.time.start') IS NOT NULL
      GROUP BY message_id
    )
    SELECT a.session_id sid, a.id, a.data, a.tc,
           COALESCE(t.t_first,0) t_first, COALESCE(t.tool_ms,0) tool_ms,
           COALESCE(t.n_tools,0) n_tools
    FROM a LEFT JOIN tools t ON t.message_id = a.id
    ORDER BY a.tc";
    $st = $pdo->prepare($sql);
    $st->execute([':days' => $days]);
    $out = [];
    foreach ($st as $r) {
        $d = json_decode($r['data'], true);
        $t = $d['time'] ?? null;
        if (!$t || empty($t['completed'])) continue;
        $tok = $d['tokens'] ?? [];
        $gen = ($tok['output'] ?? 0) + ($tok['reasoning'] ?? 0);
        $c0 = $t['created']; $c1 = $t['completed'];
        $llm_ms = $r['t_first'] > 0 ? $r['t_first'] - $c0 : $c1 - $c0;
        if ($llm_ms <= 0) continue;
        $out[] = [
            'model'   => ($d['providerID'] ?? '?') . '/' . ($d['modelID'] ?? '?'),
            'finish'  => $d['finish'] ?? '?',
            'gen'     => $gen,
            'input'   => $tok['input'] ?? 0,
            'cache'   => $tok['cache']['read'] ?? 0,
            'llm_s'   => $llm_ms / 1000,
            'span_s'  => ($c1 - $c0) / 1000,
            'tool_ms' => (int)$r['tool_ms'],
            'n_tools' => (int)$r['n_tools'],
            'tok_s'   => $gen / ($llm_ms / 1000),
            'ts'      => intdiv($c0, 1000),
            'session' => $r['sid'],
        ];
    }
    return $out;
}

/** pct lineal (percentil_disc aproximado sobre array ordenado). */
function pct(array $v, float $p): float {
    if (!$v) return 0.0;
    sort($v);
    $i = (int)floor($p * (count($v) - 1));
    return $v[$i];
}

/** Resumen por modelo a partir de filas ya calculadas. */
function by_model(array $msgs): array {
    $by = [];
    foreach ($msgs as $m) $by[$m['model']][] = $m;
    $out = [];
    foreach ($by as $k => $v) {
        $sp = array_column($v, 'tok_s');
        $out[] = [
            'model' => $k, 'msgs' => count($v),
            'p50' => pct($sp, .5), 'p90' => pct($sp, .9), 'max' => max($sp),
            'llm_p50' => pct(array_column($v, 'llm_s'), .5),
            'tool_p50' => pct(array_column($v, 'tool_ms'), .5),
            'gen' => array_sum(array_column($v, 'gen')),
        ];
    }
    usort($out, fn($a, $b) => $b['msgs'] <=> $a['msgs']);
    return $out;
}

function fk(float $n, int $d = 1): string { return number_format($n, $d, '.', ','); }
function fk0(float $n): string { return number_format((int)round($n), 0, '.', ','); }

/* --- configuration: copy .env.php.example to .env.php and adjust --- */
$CFG_FILE = __DIR__ . '/.env.php';
if (!is_file($CFG_FILE)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    exit("tokps: configuration missing. Copy .env.php.example to .env.php and edit it.\n");
}
$CFG = require $CFG_FILE;
define('DB', $CFG['db']);
define('CACHE_DIR', $CFG['cache_dir']);
define('CACHE_FILE', CACHE_DIR . '/page.html');
define('TTL', (int)($CFG['ttl'] ?? 300));
if (!is_dir(CACHE_DIR)) {
    @mkdir(CACHE_DIR, 0775, true);   // best effort; on failure the app runs cacheless (live queries)
}

function serve_cached(string $label): never {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: public, max-age=' . TTL);
    header('X-Tokps-Cache: ' . $label);
    readfile(CACHE_FILE);
    exit;
}

/* --- cache logic (before any DB work) --- */
$tailnet = (bool)preg_match('/^100\.(6[4-9]|[7-9]\d|1[01]\d|12[0-7])\./', $_SERVER['REMOTE_ADDR'] ?? '');
$force   = $tailnet && isset($_GET['refresh']);   // ?refresh=1 only honored from the tailnet

if (!$force && is_file(CACHE_FILE) && time() - filemtime(CACHE_FILE) < TTL) {
    serve_cached('hit');
}
$lock = is_writable(CACHE_DIR) ? fopen(CACHE_DIR . '/lock', 'c') : false;
if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
    if (is_file(CACHE_FILE)) serve_cached('stale');   // someone else is regenerating
    flock($lock, LOCK_EX);                            // no cache yet: wait our turn
}
if (!$force && is_file(CACHE_FILE) && time() - filemtime(CACHE_FILE) < TTL) {
    serve_cached('hit');
}
ob_start();

$pdo = db();
$m7  = fetch_msgs($pdo, 7);
$m30 = fetch_msgs($pdo, 30);
$s7  = by_model($m7);
$s30 = by_model($m30);

/* actividad diaria (14d) sobre la ventana 30d ya cargada */
$cut14 = time() - 14 * 86400;
$daily = [];
foreach ($m30 as $m) {
    if ($m['ts'] < $cut14) continue;
    $day = date('Y-m-d', $m['ts']);
    $daily[$day]['msgs'] = ($daily[$day]['msgs'] ?? 0) + 1;
    $daily[$day]['gen']  = ($daily[$day]['gen'] ?? 0) + $m['gen'];
    $daily[$day]['sp'][] = $m['tok_s'];
    $daily[$day]['tool'] = ($daily[$day]['tool'] ?? 0) + $m['tool_ms'];
}
krsort($daily);

$recent = array_slice(array_reverse($m7), 0, 40);

function pill(string $finish): string {
    $cls = match ($finish) {
        'stop' => 'ok', 'tool-calls' => 'info',
        'length' => 'warn', default => 'err',
    };
    return "<span class=\"pill $cls\">" . htmlspecialchars($finish) . "</span>";
}

function tabla_modelos(array $s): string {
    $h = "<div class=\"table-wrap\"><table><thead><tr><th>model</th><th>calls</th><th>tok/s p50</th><th>p90</th><th>max</th><th>llm p50</th><th>tool p50</th><th>total gen</th></tr></thead><tbody>";
    foreach ($s as $m) {
        $h .= "<tr><td class=\"mono\">" . htmlspecialchars($m['model']) . "</td><td class=\"num\">{$m['msgs']}</td>"
            . "<td class=\"num strong\">" . fk($m['p50']) . "</td><td class=\"num\">" . fk($m['p90']) . "</td>"
            . "<td class=\"num\">" . fk($m['max']) . "</td><td class=\"num\">" . fk($m['llm_p50']) . " s</td>"
            . "<td class=\"num\">" . fk0($m['tool_p50']) . " ms</td><td class=\"num\">" . fk0($m['gen']) . "</td></tr>";
    }
    return $h . "</tbody></table></div>";
}
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>tokps — LLM throughput from opencode</title>
<style>
  :root { --bg:#0b1020; --card:#131a2e; --line:#232c47; --tx:#dbe2f4; --dim:#8792b3;
          --ok:#4ade80; --info:#60a5fa; --warn:#fbbf24; --err:#f87171; --accent:#7dd3fc; }
  * { box-sizing:border-box; }
  body { margin:0; padding:2rem; background:var(--bg); color:var(--tx);
         font:15px/1.5 system-ui,-apple-system,"Segoe UI",Roboto,sans-serif; }
  main { max-width:1100px; margin:0 auto; }
  h1 { font-size:1.5rem; margin:0 0 .25rem; }
  h2 { font-size:1.05rem; margin:2.25rem 0 .5rem; color:var(--accent); }
  .sub { color:var(--dim); margin-bottom:1.5rem; }
  .card { background:var(--card); border:1px solid var(--line); border-radius:10px;
          padding:1rem 1.25rem; margin-bottom:1.5rem; }
  table { width:100%; border-collapse:collapse; font-size:.92rem; }
  th { text-align:left; color:var(--dim); font-weight:600; padding:.45rem .6rem;
       border-bottom:1px solid var(--line); white-space:nowrap; }
  td { padding:.4rem .6rem; border-bottom:1px solid var(--line); }
  tr:last-child td { border-bottom:none; }
  .num { text-align:right; font-variant-numeric:tabular-nums; }
  .strong { font-weight:700; color:var(--accent); }
  .mono { font-family:ui-monospace,SFMono-Regular,Menlo,monospace; font-size:.86rem; }
  .pill { display:inline-block; padding:.05rem .55rem; border-radius:999px; font-size:.78rem;
          border:1px solid var(--line); }
  .pill.ok { color:var(--ok); border-color:var(--ok); }
  .pill.info { color:var(--info); border-color:var(--info); }
  .pill.warn { color:var(--warn); border-color:var(--warn); }
  .pill.err { color:var(--err); border-color:var(--err); }
  .table-wrap { overflow-x:auto; -webkit-overflow-scrolling:touch; }
  table { min-width:560px; }
  @media (max-width:640px) { body { padding:1rem .75rem; } h1 { font-size:1.2rem; } }
  footer { color:var(--dim); font-size:.82rem; margin-top:3rem; line-height:1.6; }
  footer code { color:var(--tx); }
</style>
</head>
<body>
<main>
  <h1>tokps — retrospective throughput of opencode LLM calls</h1>
  <p class="sub">Generated live from <code>opencode.db</code> ·
     <?= date('Y-m-d H:i:s') ?> · <?= count($m7) ?> calls in 7d ·
     <?= count($m30) ?> in 30d · <span class="pill info">private</span></p>

  <h2>Last 7 days · by model</h2>
  <div class="card"><?= tabla_modelos($s7) ?></div>

  <h2>Last 30 days · by model</h2>
  <div class="card"><?= tabla_modelos($s30) ?></div>

  <h2>Daily activity · 14 days</h2>
  <div class="card"><div class="table-wrap">
    <table>
      <thead><tr><th>day</th><th>calls</th><th>tokens generated</th><th>tok/s p50</th><th>total tool time</th></tr></thead>
      <tbody>
      <?php foreach ($daily as $day => $d): ?>
        <tr>
          <td class="mono"><?= $day ?></td>
          <td class="num"><?= $d['msgs'] ?></td>
          <td class="num"><?= fk0($d['gen']) ?></td>
          <td class="num strong"><?= fk(pct($d['sp'], .5)) ?></td>
          <td class="num"><?= fk($d['tool'] / 1000, 1) ?> s</td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>

  <h2>Last 40 calls</h2>
  <div class="card"><div class="table-wrap">
    <table>
      <thead><tr><th>time</th><th>model</th><th>tok/s</th><th>gen</th><th>llm (s)</th><th>tools</th><th>tool (ms)</th><th>finish</th></tr></thead>
      <tbody>
      <?php foreach ($recent as $m): ?>
        <tr>
          <td class="mono"><?= date('m-d H:i:s', $m['ts']) ?></td>
          <td class="mono"><?= htmlspecialchars($m['model']) ?></td>
          <td class="num strong"><?= fk($m['tok_s']) ?></td>
          <td class="num"><?= fk0($m['gen']) ?></td>
          <td class="num"><?= fk($m['llm_s'], 2) ?></td>
          <td class="num"><?= $m['n_tools'] ?: '·' ?></td>
          <td class="num"><?= $m['n_tools'] ? fk0($m['tool_ms']) : '·' ?></td>
          <td><?= pill($m['finish']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div></div>

  <footer>
    <strong>Methodology.</strong> Generation tok/s = (output + reasoning tokens) ÷ pure LLM window,
    where window = <code>time.created → first tool start</code> (or <code>time.completed</code> when no tools).
    The raw span in the DB includes tool execution and is therefore not used directly.
    Reasoning tokens dominate in thinking models: ignoring them underestimates speed ~2x.
    The window still includes TTFT and any provider retries (they cannot be separated retroactively).
    Source: <code>~/.local/share/opencode/opencode.db</code> (tables <code>message</code> + <code>part</code>), read-only (WAL).
    Forensic CLI: <code>tokps.py</code> in this same directory.
  </footer>
</main>
</body>
</html>
<?php
$html = ob_get_clean();
if (is_writable(CACHE_DIR)) {
    file_put_contents(CACHE_FILE . '.tmp', $html);
    rename(CACHE_FILE . '.tmp', CACHE_FILE);   // atomic
}
header('X-Tokps-Cache: regen');
header('Cache-Control: public, max-age=' . TTL);
echo $html;
if ($lock) flock($lock, LOCK_UN);
