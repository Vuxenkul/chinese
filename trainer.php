<?php
/* 
  Single-file Chinese Trainer (PHP + HTML + JS)
  ------------------------------------------------
  - Upload your CSV or place data.csv beside this file.
  - Expected headers (case-insensitive):
    Type, Chinese, Pinyin, English, Example Sentence, Pinyin (Sentence), English (Sentence), Literal Translation
  - Handles comma, tab, or semicolon delimiters.
  - Stores uploaded data in PHP session; you can "Reset dataset" to switch files.

  Author: You + ChatGPT :)
*/

mb_internal_encoding("UTF-8");
header('Content-Type: text/html; charset=utf-8');
session_start();

/* ---------------------- Utilities ---------------------- */
function clean_bom($s) {
  if (substr($s, 0, 3) === "\xEF\xBB\xBF") return substr($s, 3);
  return $s;
}
function detect_delimiter_from_sample($line) {
  $candidates = ["\t", ",", ";", "|"];
  $bestDelim = ",";
  $bestCount = 0;
  foreach ($candidates as $d) {
    $count = substr_count($line, $d);
    if ($count > $bestCount) { $bestCount = $count; $bestDelim = $d; }
  }
  return $bestDelim;
}
function normalize_header($h) {
  $h = trim(mb_strtolower($h));
  $h = preg_replace('/\s+/', ' ', $h);
  return $h;
}
function parse_csv_file($path) {
  $fh = fopen($path, 'r');
  if (!$fh) return [];

  // Peek first line to detect delimiter
  $first = fgets($fh);
  if ($first === false) { fclose($fh); return []; }
  $first = clean_bom($first);
  $delim = detect_delimiter_from_sample($first);

  // Rewind and use fgetcsv
  rewind($fh);
  $headers = fgetcsv($fh, 0, $delim);
  if (!$headers) { fclose($fh); return []; }

  // Normalize headers map
  $headers = array_map('clean_bom', $headers);
  $norm = array_map('normalize_header', $headers);

  // Build index map to canonical keys
  $map = []; // canonical => index
  $canon = [
    'type' => ['type','category'],
    'chinese' => ['chinese','hanzi','han zi','character','characters','word'],
    'pinyin' => ['pinyin'],
    'english' => ['english','meaning','gloss','definition'],
    'example' => ['example sentence','example','chinese sentence','sentence'],
    'example_pinyin' => ['pinyin (sentence)','sentence pinyin','pinyin sentence'],
    'example_english' => ['english (sentence)','sentence english','translation','sentence translation'],
    'literal' => ['literal translation','literal','gloss (literal)']
  ];
  foreach ($canon as $key => $alts) {
    foreach ($alts as $alt) {
      $idx = array_search($alt, $norm, true);
      if ($idx !== false) { $map[$key] = $idx; break; }
    }
  }

  $rows = [];
  $i = 0;
  while (($row = fgetcsv($fh, 0, $delim)) !== false) {
    // Skip completely empty lines
    if (count(array_filter($row, fn($v)=>trim((string)$v)!==''))===0) continue;

    $item = [
      'id' => $i++,
      'type' => $map['type'] ?? null,
      'chinese' => $map['chinese'] ?? null,
      'pinyin' => $map['pinyin'] ?? null,
      'english' => $map['english'] ?? null,
      'example' => $map['example'] ?? null,
      'example_pinyin' => $map['example_pinyin'] ?? null,
      'example_english' => $map['example_english'] ?? null,
      'literal' => $map['literal'] ?? null,
    ];
    foreach ($item as $k => $idx) {
      if ($k==='id') continue;
      $item[$k] = ($idx !== null && isset($row[$idx])) ? trim((string)$row[$idx]) : '';
    }
    // Basic sanity: require at least chinese & english to keep
    if ($item['chinese'] !== '' && $item['english'] !== '') {
      $rows[] = $item;
    }
  }
  fclose($fh);
  return $rows;
}
function sample_data() {
  return [
    [
      'id'=>0,'type'=>'Adjective','chinese'=>'大','pinyin'=>'dà','english'=>'big',
      'example'=>'这个苹果很大。','example_pinyin'=>'Zhè ge píngguǒ hěn dà.',
      'example_english'=>'This apple is very big.','literal'=>'This apple very big.'
    ],
    [
      'id'=>1,'type'=>'Adjective','chinese'=>'多','pinyin'=>'duō','english'=>'many',
      'example'=>'桌子上有很多书。','example_pinyin'=>'Zhuōzi shàng yǒu hěn duō shū.',
      'example_english'=>'There are many books on the table.','literal'=>'Table on has very many books.'
    ]
  ];
}

/* ---------------------- Routing: upload/reset ---------------------- */
if (isset($_GET['clear'])) {
  unset($_SESSION['csv_rows']);
  header('Location: '. strtok($_SERVER['REQUEST_URI'],'?'));
  exit;
}

if (!empty($_FILES['csv']['tmp_name'])) {
  $rows = parse_csv_file($_FILES['csv']['tmp_name']);
  if (!empty($rows)) {
    $_SESSION['csv_rows'] = $rows;
  }
  header('Location: '. strtok($_SERVER['REQUEST_URI'],'?'));
  exit;
}

/* ---------------------- Data source selection ---------------------- */
$dataSource = 'sample';
$rows = [];
if (!empty($_SESSION['csv_rows'])) {
  $rows = $_SESSION['csv_rows'];
  $dataSource = 'uploaded';
} elseif (file_exists(__DIR__ . '/data.csv')) {
  $rows = parse_csv_file(__DIR__ . '/data.csv');
  if (!empty($rows)) $dataSource = 'data.csv';
}
if (empty($rows)) $rows = sample_data();

$json = json_encode(array_values($rows), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
$total = count($rows);
$hash = substr(sha1($json), 0, 8);
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Chinese Trainer</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
  :root{
    --bg:#f3f7f5;
    --bg-accent:#dff3eb;
    --surface:#ffffff;
    --surface-soft:#f7fbf9;
    --primary:#1d9a6c;
    --primary-dark:#147555;
    --accent:#f4a261;
    --accent-soft:rgba(244,162,97,.16);
    --text:#15323b;
    --muted:#5f7a83;
    --outline:rgba(29,154,108,.18);
    --shadow:0 22px 40px rgba(34,76,57,.16);
    --radius:26px;
    --font:'Inter','SF Pro Display',-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;
  }
  *{box-sizing:border-box}
  body.app-shell{margin:0;background:linear-gradient(180deg,#f6fffb 0%,#e8f2ff 100%);font-family:var(--font);color:var(--text);min-height:100vh;display:flex;justify-content:center;padding:28px 16px}
  main.app-content{display:flex;flex-direction:column;gap:20px}
  .app-frame{width:min(420px,100%);display:flex;flex-direction:column;gap:20px}
  header.app-header{display:flex;align-items:center;justify-content:space-between;padding:4px 4px 0}
  .brand{display:flex;align-items:center;gap:12px}
  .brand-icon{display:inline-flex;width:48px;height:48px;border-radius:18px;background:linear-gradient(145deg,#1d9a6c,#4cc28b);color:#fff;font-size:24px;align-items:center;justify-content:center;box-shadow:0 15px 30px rgba(29,154,108,.25)}
  .brand-text{display:flex;flex-direction:column}
  .brand-text strong{font-size:18px}
  .brand-text span{font-size:13px;color:var(--muted)}
  .dataset-pill{background:var(--accent-soft);border:1px solid rgba(244,162,97,.35);color:#a65b1e;font-size:12px;padding:10px 14px;border-radius:18px;display:flex;flex-direction:column;align-items:flex-end;gap:2px;text-align:right}
  .dataset-pill span{font-weight:600;font-size:13px}
  .card{background:var(--surface);border-radius:var(--radius);box-shadow:var(--shadow);padding:24px;position:relative;overflow:hidden}
  .card::after{content:"";position:absolute;inset:0;border-radius:var(--radius);border:1px solid rgba(255,255,255,.6);pointer-events:none;mix-blend-mode:soft-light;opacity:.5}
  .hero-card{display:flex;flex-direction:column;gap:20px;background:linear-gradient(160deg,#1d9a6c 0%,#40c09d 60%,#90f1b8 110%);color:#f6fffb;overflow:hidden}
  .hero-card::after{border:none}
  .hero-top{display:flex;flex-direction:column;gap:10px}
  .eyebrow{text-transform:uppercase;letter-spacing:.16em;font-size:12px;opacity:.7}
  .hero-top h1{margin:0;font-size:26px;line-height:1.2}
  .hero-top p{margin:0;font-size:14px;opacity:.85}
  .hero-actions{display:flex;flex-direction:column;gap:10px}
  .btn{border:0;border-radius:18px;padding:14px 18px;font-weight:600;font-size:15px;cursor:pointer;transition:transform .18s ease,box-shadow .18s ease}
  .btn.primary{background:#fff;color:#177957;box-shadow:0 18px 28px rgba(5,52,35,.2)}
  .btn.primary:hover{transform:translateY(-1px);box-shadow:0 22px 34px rgba(5,52,35,.3)}
  .btn.primary:disabled{opacity:.6;box-shadow:none;cursor:not-allowed;transform:none}
  .btn.ghost{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.4)}
  .btn.ghost:hover{transform:translateY(-1px);background:rgba(255,255,255,.2)}
  .btn.secondary{background:rgba(24,126,89,.1);color:#177957;border:1px solid rgba(24,126,89,.35);box-shadow:none}
  .btn.secondary:hover{transform:translateY(-1px);background:rgba(24,126,89,.16)}
  .setup-card h2{margin:0 0 10px;font-size:18px}
  .setup-card p.subtitle{margin:0 0 18px;font-size:13px;color:var(--muted)}
  .form-grid{display:flex;flex-direction:column;gap:16px}
  .field{display:flex;flex-direction:column;gap:6px;font-size:13px;color:var(--muted)}
  .field span{font-weight:600;color:var(--text);font-size:14px}
  select,input[type=number],input[type=text],input[type=file]{border-radius:16px;border:1px solid var(--outline);padding:12px 14px;font-size:15px;background:var(--surface-soft);color:var(--text);box-shadow:inset 0 1px 2px rgba(20,60,43,.05)}
  input[type=number]{-moz-appearance:textfield}
  input::-webkit-outer-spin-button,input::-webkit-inner-spin-button{margin:0;-webkit-appearance:none}
  .checklist{display:flex;flex-wrap:wrap;gap:10px}
  .toggle{background:var(--surface-soft);border:1px solid var(--outline);border-radius:16px;padding:10px 12px;font-size:13px;color:var(--text);display:flex;align-items:center;gap:8px}
  .toggle input{accent-color:var(--primary)}
  .audio-hint{display:flex;flex-direction:column;gap:10px;background:var(--surface-soft);border-radius:18px;padding:12px 14px;margin-top:4px}
  .audio-row{display:flex;align-items:center;gap:10px}
  .upload-actions{display:flex;flex-direction:column;gap:12px;margin-top:20px}
  .upload-actions form{display:flex;flex-direction:column;gap:12px}
  .upload-actions .btn{width:100%}
  .stat-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(160px,1fr));gap:16px}
  .stat-card{padding:20px;border-radius:22px;background:var(--surface);box-shadow:var(--shadow);display:flex;flex-direction:column;gap:6px;position:relative;overflow:hidden}
  .stat-card::after{content:"";position:absolute;inset:0;border-radius:22px;border:1px solid rgba(255,255,255,.5);pointer-events:none;mix-blend-mode:soft-light;opacity:.4}
  .stat-card h3{margin:0;font-size:13px;text-transform:uppercase;letter-spacing:.14em;color:var(--muted)}
  .stat-card .value{font-size:22px;font-weight:700;color:var(--text);display:flex;align-items:center;gap:6px}
  .stat-card .note{font-size:12px;color:var(--muted)}
  .progress-shell{margin-top:8px;background:var(--surface-soft);border-radius:999px;height:10px;overflow:hidden;border:1px solid rgba(21,50,59,.08)}
  .progress-bar{height:100%;width:0;background:linear-gradient(90deg,#1d9a6c,#4cc28b);transition:width .3s ease}
  .lesson-card{display:flex;flex-direction:column;gap:20px}
  .exercise{min-height:250px}
  .control-buttons{display:flex;flex-direction:column;gap:12px}
  .row{display:flex;flex-direction:column;gap:12px}
  .row.center{align-items:center;justify-content:center}
  .gap-12{gap:12px}
  .center{text-align:center}
  .foot{font-size:12px;color:var(--muted);margin-top:-4px}
  .divider{height:1px;background:rgba(21,50,59,.08);margin:12px 0}
  .exercise-title{font-size:13px;text-transform:uppercase;letter-spacing:.14em;color:var(--muted);margin-bottom:12px}
  .chips{display:flex;flex-wrap:wrap;gap:10px}
  .chip{background:var(--surface-soft);border:1px solid var(--outline);border-radius:14px;padding:9px 12px;font-weight:600;font-size:16px;color:var(--text);cursor:pointer;transition:transform .15s ease,background .15s ease}
  .chip:hover{transform:translateY(-1px)}
  .chip.disabled{opacity:.4;pointer-events:none;transform:none}
  .option{display:block;background:var(--surface-soft);border:1px solid var(--outline);border-radius:18px;padding:14px 16px;margin:10px 0;font-size:16px;cursor:pointer;transition:transform .15s ease,box-shadow .15s ease,background .15s ease}
  .option:hover{transform:translateY(-1px);box-shadow:0 14px 24px rgba(24,76,55,.1)}
  .option.correct{border-color:rgba(29,154,108,.7);background:rgba(29,154,108,.12);box-shadow:0 18px 24px rgba(29,154,108,.18)}
  .option.wrong{border-color:rgba(239,68,68,.45);background:rgba(239,68,68,.12);box-shadow:none}
  .audio{cursor:pointer;padding:10px 14px;border-radius:16px;border:1px solid rgba(23,121,87,.35);background:rgba(24,126,89,.12);color:#177957;transition:transform .15s ease}
  .audio:hover{transform:translateY(-1px)}
  .sentence-card{background:var(--surface-soft);border-radius:18px;padding:14px 16px;line-height:1.5}
  .fill-slots{display:flex;gap:8px;margin:14px 0 12px;flex-wrap:wrap}
  .slot{width:52px;height:56px;border-radius:16px;border:2px dashed rgba(21,50,59,.2);display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:700;background:rgba(255,255,255,.65);cursor:pointer;transition:border-color .2s ease,background .2s ease}
  .slot.filled{border-style:solid;border-color:rgba(29,154,108,.6);background:rgba(29,154,108,.1)}
  .tone-pad{display:grid;gap:12px;margin-top:18px;background:var(--surface-soft);border:1px solid var(--outline);border-radius:22px;padding:16px}
  .tone-group{display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  .tone-label{font-size:12px;text-transform:uppercase;letter-spacing:.14em;color:var(--muted);padding-right:6px}
  .tone-btn{background:#fff;border:1px solid rgba(23,121,87,.25);border-radius:14px;padding:8px 12px;color:#177957;font-weight:600;font-size:16px;cursor:pointer;transition:transform .15s ease,box-shadow .15s ease}
  .tone-btn:active{transform:scale(.95)}
  .toast{position:fixed;bottom:24px;left:50%;transform:translateX(-50%);background:#16323b;color:#f8fffb;padding:14px 18px;border-radius:16px;box-shadow:0 24px 42px rgba(14,42,32,.3);border:1px solid rgba(255,255,255,.1);z-index:999}
  .hidden{display:none}
  .muted{color:var(--muted)}
  .sentence-card .muted{color:var(--muted)}
  .bottom-info{font-size:12px;color:var(--muted);text-align:center;padding-bottom:20px}
  .hearts{display:flex;gap:6px;font-size:20px;color:#ff6f91}
  .hearts .empty{opacity:.35}
  code{background:var(--surface-soft);padding:2px 6px;border-radius:8px}
  @media (min-width:540px){
    body.app-shell{padding:40px 24px}
    .row{flex-direction:row}
    .control-buttons{flex-direction:row}
    .control-buttons .btn{flex:1}
    .hero-actions{flex-direction:row}
    .hero-actions .btn{flex:1}
  }
  @media (min-width:768px){
    .app-frame{width:min(960px,100%)}
    header.app-header{padding:8px 12px}
    .hero-card{flex-direction:row;align-items:center;gap:30px}
    .hero-top{flex:1}
    .hero-actions{width:220px}
    .setup-card{display:grid;grid-template-columns:1.4fr 1fr;gap:28px;align-items:flex-start}
    .upload-actions{margin-top:0}
    .stat-grid{grid-template-columns:repeat(4,1fr)}
    .lesson-card{padding:32px}
  }
</style>
</head>
<body class="app-shell">
  <div class="app-frame">
    <header class="app-header">
      <div class="brand">
        <span class="brand-icon">🈴</span>
        <div class="brand-text">
          <strong>Chinese Trainer</strong>
          <span>Language journey dashboard</span>
        </div>
      </div>
      <div class="dataset-pill">
        <span><?php echo $total ?> prompts</span>
        <small>Loaded: <?php echo htmlspecialchars($dataSource) ?></small>
        <small>ID #<?php echo $hash ?></small>
      </div>
    </header>

    <main class="app-content">
      <section class="card hero-card">
        <div class="hero-top">
          <span class="eyebrow">Daily journey</span>
          <h1>Build your Mandarin flow</h1>
          <p>Practice with <?php echo $total ?> cards from the <?php echo htmlspecialchars($dataSource) ?> deck. Upload your own list whenever you're ready.</p>
        </div>
        <div class="hero-actions">
          <button id="startBtn" class="btn primary">Start lesson</button>
          <button id="reviewBtn" class="btn ghost">Quick sprint (5)</button>
        </div>
      </section>

      <section class="card setup-card">
        <div>
          <h2>Lesson setup</h2>
          <p class="subtitle">Customize the run before you dive in.</p>
          <div class="form-grid">
            <label class="field">
              <span>Filter by type</span>
              <select id="typeFilter"></select>
            </label>
            <label class="field">
              <span>Questions per run</span>
              <input id="qCount" type="number" min="5" max="50" value="10">
            </label>
            <div class="field">
              <span>Exercise focus</span>
              <div class="checklist">
                <label class="toggle"><input type="checkbox" class="et" value="mc_cn_en" checked> CN→EN</label>
                <label class="toggle"><input type="checkbox" class="et" value="type_en_cn" checked> EN→CN</label>
                <label class="toggle"><input type="checkbox" class="et" value="type_pinyin" checked> Pinyin</label>
                <label class="toggle"><input type="checkbox" class="et" value="fill_blank" checked> Fill-in</label>
                <label class="toggle"><input type="checkbox" class="et" value="order_pinyin" checked> Re-order</label>
              </div>
            </div>
            <div class="field">
              <span>Audio coach</span>
              <div class="audio-hint">
                <div class="audio-row">
                  <button id="testAudio" type="button" class="btn secondary">🔊 Test voice</button>
                  <span class="muted">Uses browser TTS (zh-CN)</span>
                </div>
              </div>
            </div>
          </div>
        </div>
        <div class="upload-actions">
          <form method="post" enctype="multipart/form-data">
            <label class="field">
              <span>Import new deck</span>
              <input type="file" name="csv" accept=".csv,.tsv,text/csv,text/tab-separated-values">
            </label>
            <button class="btn primary" type="submit">Upload CSV</button>
          </form>
          <form method="get">
            <input type="hidden" name="clear" value="1">
            <button class="btn secondary" type="submit" title="Forget uploaded dataset from session">Reset dataset</button>
          </form>
          <p class="foot">Tip: You can also keep a <code>data.csv</code> in this folder. Uploading a new file replaces the in-session dataset.</p>
        </div>
      </section>

      <section class="stat-grid">
        <div class="stat-card">
          <h3>Hearts</h3>
          <div class="value hearts" id="hearts"></div>
        </div>
        <div class="stat-card">
          <h3>XP</h3>
          <div class="value"><span id="xp">0</span> pts</div>
        </div>
        <div class="stat-card">
          <h3>Question</h3>
          <div class="value"><span id="qpos">0/0</span></div>
          <div class="progress-shell"><div class="progress-bar" id="progressBar"></div></div>
          <span class="note">Lesson progress</span>
        </div>
        <div class="stat-card">
          <h3>Streak</h3>
          <div class="value"><span id="streak">0</span> 🔥</div>
        </div>
      </section>

      <section id="card" class="card lesson-card">
        <div id="exercise" class="exercise"></div>
        <div id="controls" class="control-buttons">
          <button id="skipBtn" class="btn secondary">Skip</button>
          <button id="nextBtn" class="btn primary" disabled>Next</button>
        </div>
        <div id="explain" class="foot"></div>
      </section>
    </main>

    <div class="bottom-info">
      Dataset hash: <?php echo $hash ?> · Saved locally with key <code>cntrainer-<?php echo $hash ?></code>
    </div>
  </div>

  <div id="toast" class="toast hidden"></div>

<script>
/* ---------------------- Data bootstrap ---------------------- */
window.DATASET = <?php echo $json; ?>;
const DATA_SOURCE = "<?php echo $dataSource; ?>";
const DATA_HASH = "<?php echo $hash; ?>";

/* ---------------------- Helpers ---------------------- */
const $ = sel => document.querySelector(sel);
function shuffle(a){ for(let i=a.length-1;i>0;i--){const j=Math.floor(Math.random()*(i+1));[a[i],a[j]]=[a[j],a[i]]} return a;}
function choice(a){return a[Math.floor(Math.random()*a.length)]}
function uniq(a){return Array.from(new Set(a))}
function escapeHTML(s){return s.replace(/[&<>"']/g,m=>({ '&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[m]))}
function speak(text, lang='zh-CN'){
  if (!window.speechSynthesis) return;
  const u = new SpeechSynthesisUtterance(text);
  u.lang = lang;
  u.rate = 1; u.pitch = 1; u.volume = 1;
  window.speechSynthesis.speak(u);
}
function speakCN(text){ speak(text, 'zh-CN'); }
function stripDiacritics(str){
  return str.normalize('NFD').replace(/[\u0300-\u036f]/g,'').replace(/ü/g,'u').toLowerCase();
}
function normalizeCN(str){return str.replace(/\s+/g,'').trim();}
function normalizePinyin(str){return stripDiacritics(str).replace(/\s+/g,'').replace(/[^\w]/g,'');}
function sampleWrongOptions(correct, pool, key, n=3){
  const opts = uniq(pool.map(x=>x[key])).filter(x=>x && x!==correct);
  shuffle(opts);
  return opts.slice(0,n);
}

const TONE_GROUPS = [
  {base:'a', tones:['ā','á','ǎ','à']},
  {base:'e', tones:['ē','é','ě','è']},
  {base:'i', tones:['ī','í','ǐ','ì']},
  {base:'o', tones:['ō','ó','ǒ','ò']},
  {base:'u', tones:['ū','ú','ǔ','ù']},
  {base:'ü', tones:['ǖ','ǘ','ǚ','ǜ']}
];
const TONE_GROUP_MAP = {};
TONE_GROUPS.forEach(group => { TONE_GROUP_MAP[group.base] = group; });
TONE_GROUP_MAP['v'] = TONE_GROUP_MAP['ü'];
const VOWEL_REGEX = /[aeiouvüAEIOUVÜ]/;

function buildTonePad(){
  return `
    <div class="tone-pad" id="tonePad" role="group" aria-label="Pinyin tone helpers">
      ${TONE_GROUPS.map(group => `
        <div class="tone-group">
          <span class="tone-label">${group.base.toUpperCase()}</span>
          ${[group.base, ...group.tones].map(ch => `<button type="button" class="tone-btn" data-char="${ch}">${ch}</button>`).join('')}
        </div>
      `).join('')}
      <div class="tone-group tone-actions">
        <span class="tone-label">Tone #</span>
        ${[1,2,3,4].map(n=>`<button type="button" class="tone-btn tone-number" data-tone="${n}">${n}</button>`).join('')}
        <button type="button" class="tone-btn tone-number" data-tone="0">Reset</button>
      </div>
    </div>
  `;
}

function insertAtCursor(input, text, selection){
  if (!input) return 0;
  const start = selection ? selection.start : (input.selectionStart ?? input.value.length);
  const end = selection ? selection.end : (input.selectionEnd ?? input.value.length);
  const value = input.value;
  const nextValue = value.slice(0,start) + text + value.slice(end);
  const pos = start + text.length;
  input.value = nextValue;
  input.focus();
  input.setSelectionRange(pos, pos);
  input.dispatchEvent(new Event('input', {bubbles:true}));
  return pos;
}

function applyToneNumber(input, tone, selection){
  if (!input || Number.isNaN(tone)) return 0;
  let start = selection ? selection.start : (input.selectionStart ?? input.value.length);
  let end = selection ? selection.end : (input.selectionEnd ?? input.value.length);
  if (end !== start){
    start = end;
  }
  let value = input.value;
  if (start > 0 && /[1-5]/.test(value[start-1])){
    value = value.slice(0,start-1) + value.slice(start);
    input.value = value;
    start -= 1;
    input.setSelectionRange(start, start);
  }
  let targetIndex = -1;
  for (let i = start-1; i >= 0; i--){
    if (VOWEL_REGEX.test(value[i])){
      targetIndex = i;
      break;
    }
  }
  if (targetIndex === -1){
    input.focus();
    input.setSelectionRange(start, start);
    return start;
  }
  const targetChar = value[targetIndex];
  const lower = targetChar.toLowerCase() === 'v' ? 'ü' : targetChar.toLowerCase();
  const group = TONE_GROUP_MAP[lower];
  if (!group){
    input.focus();
    input.setSelectionRange(start, start);
    return start;
  }
  let replacement = tone === 0 ? group.base : group.tones[tone-1];
  if (!replacement){
    input.focus();
    input.setSelectionRange(start, start);
    return start;
  }
  if (targetChar === targetChar.toUpperCase() && targetChar.toLowerCase() !== targetChar.toUpperCase()){
    replacement = replacement.toUpperCase();
  }
  const updated = value.slice(0,targetIndex) + replacement + value.slice(targetIndex+1);
  input.value = updated;
  input.focus();
  input.setSelectionRange(start, start);
  input.dispatchEvent(new Event('input', {bubbles:true}));
  return start;
}

function attachTonePad(input){
  const pad = document.querySelector('#tonePad');
  if (!pad || !input) return;
  const selection = {start: input.value.length, end: input.value.length};
  const capture = () => {
    selection.start = input.selectionStart ?? selection.start;
    selection.end = input.selectionEnd ?? selection.end;
  };
  ['keyup','click','input','select'].forEach(evt => input.addEventListener(evt, capture));
  pad.querySelectorAll('button[data-char]').forEach(btn => {
    btn.addEventListener('click', () => {
      selection.start = selection.end = insertAtCursor(input, btn.dataset.char, selection);
      capture();
    });
  });
  pad.querySelectorAll('button[data-tone]').forEach(btn => {
    btn.addEventListener('click', () => {
      const next = applyToneNumber(input, parseInt(btn.dataset.tone, 10), selection);
      selection.start = selection.end = next;
      capture();
    });
  });
}

function buildCharacterBank(word, sourcePool){
  const normalized = normalizeCN(word || '');
  const targetChars = Array.from(normalized);
  if (!targetChars.length) return null;
  const extras = [];
  (sourcePool || DATASET).forEach(entry => {
    const chars = Array.from(normalizeCN(entry.chinese || ''));
    chars.forEach(ch => {
      if (ch && !targetChars.includes(ch)) extras.push(ch);
    });
  });
  const uniqueExtras = uniq(extras);
  shuffle(uniqueExtras);
  const extrasNeeded = Math.min(uniqueExtras.length, Math.max(targetChars.length, 3));
  const bank = targetChars.map((ch, idx) => ({char: ch, key: `t${idx}`}));
  for (let i = 0; i < extrasNeeded; i++){
    bank.push({char: uniqueExtras[i], key: `e${i}`});
  }
  return {targetChars, bank: shuffle(bank)};
}

function renderHeartsDisplay(count){
  const wrap = $('#hearts');
  if (!wrap) return;
  const total = 3;
  let html = '';
  for (let i = 0; i < total; i++){
    const full = i < Math.max(0, count);
    html += `<span class="${full ? 'full' : 'empty'}">${full ? '❤' : '♡'}</span>`;
  }
  wrap.innerHTML = html;
}

function updateProgressBar(forceIndex = null){
  const bar = $('#progressBar');
  if (!bar) return;
  if (!lesson.length){
    bar.style.width = '0%';
    return;
  }
  const idx = forceIndex !== null ? forceIndex : qIndex;
  const percent = Math.min(100, Math.max(0, (idx/lesson.length)*100));
  bar.style.width = `${percent}%`;
}

/* ---------------------- UI Populate ---------------------- */
const TYPES = ['All', ...uniq(DATASET.map(x=>x.type||'Other'))].sort();
const typeFilter = $('#typeFilter');
typeFilter.innerHTML = TYPES.map(t=>`<option value="${escapeHTML(t)}">${escapeHTML(t)}</option>`).join('');
typeFilter.value = 'All';

$('#testAudio').addEventListener('click', ()=> speakCN('你好！开始上课。'));

/* ---------------------- State ---------------------- */
let pool = DATASET.slice();
let lesson = [];
let qIndex = 0;
let hearts = 3;
let xp = 0;
let streak = 0;
let lessonXP = 0;
const SAVEKEY = `cntrainer-${DATA_HASH}`;
loadSave();
renderHeartsDisplay(hearts);
updateProgressBar(0);

function save(){
  localStorage.setItem(SAVEKEY, JSON.stringify({xp,streak}));
}
function loadSave(){
  try{
    const s = JSON.parse(localStorage.getItem(SAVEKEY)||'{}');
    xp = s.xp||0; streak = s.streak||0;
    $('#xp').textContent = xp; $('#streak').textContent = streak;
  }catch{}
}

/* ---------------------- Lesson flow ---------------------- */
function buildPool(){
  const t = typeFilter.value;
  pool = DATASET.filter(x => t==='All' ? true : (x.type||'Other')===t);
}
buildPool();

$('.et[value="mc_cn_en"]').checked = true;

function selectedExerciseTypes(){
  return Array.from(document.querySelectorAll('.et:checked')).map(x=>x.value);
}

function startLesson(qNum){
  buildPool();
  const types = selectedExerciseTypes();
  if (pool.length===0 || types.length===0){ toast('No data or exercise types selected.'); return; }
  lesson = [];
  const N = Math.min(qNum, pool.length*2); // cap
  for (let i=0;i<N;i++){
    const item = choice(pool);
    const et = choice(types);
    lesson.push({item, et});
  }
  hearts = 3;
  lessonXP = 0;
  qIndex = 0;
  $('#qpos').textContent = `0/${lesson.length}`;
  renderHeartsDisplay(hearts);
  $('#nextBtn').disabled = true;
  $('#explain').textContent = '';
  updateProgressBar(0);
  renderCurrent();
}

$('#startBtn').addEventListener('click', ()=> {
  const n = Math.max(5, Math.min(50, parseInt($('#qCount').value||10,10)));
  startLesson(n);
});
$('#reviewBtn').addEventListener('click', ()=> startLesson(5));
$('#skipBtn').addEventListener('click', ()=> revealAndNext(false));
$('#nextBtn').addEventListener('click', ()=> next());

function renderCurrent(){
  const cur = lesson[qIndex];
  if (!cur){
    updateProgressBar(0);
    $('#exercise').innerHTML = '<p class="muted">Press <b>Start Lesson</b> to jump into a new round! 🚀</p>';
    return;
  }
  $('#qpos').textContent = `${qIndex+1}/${lesson.length}`;
  $('#nextBtn').disabled = true;
  $('#explain').textContent = '';
  updateProgressBar(qIndex);

  const et = cur.et;
  const item = cur.item;
  if (et==='mc_cn_en') render_mc_cn_en(item);
  else if (et==='type_en_cn') render_type_en_cn(item);
  else if (et==='type_pinyin') render_type_pinyin(item);
  else if (et==='fill_blank') render_fill_blank(item);
  else if (et==='order_pinyin') render_order_pinyin(item);
  else render_mc_cn_en(item);
}

/* ---------------------- Exercises ---------------------- */
function render_mc_cn_en(item){
  const wrongs = sampleWrongOptions(item.english, pool, 'english', 3);
  const options = shuffle([item.english, ...wrongs]);
  $('#exercise').innerHTML = `
    <div class="exercise-title">Match the meaning</div>
    <div class="row gap-12" style="align-items:center;justify-content:space-between">
      <div>
        <div style="font-size:32px;line-height:1.2">${escapeHTML(item.chinese)}</div>
        <div class="muted" style="margin-top:6px">${escapeHTML(item.pinyin||'')} ${item.type ? `· <span style="color:#38bdf8">${escapeHTML(item.type)}</span>` : ''}</div>
      </div>
      <button class="audio" title="Play audio" onclick="speakCN(${JSON.stringify(item.chinese)})">🔊</button>
    </div>
    <div style="margin-top:12px">${options.map(o=>`<div class="option" data-val="${escapeHTML(o)}">${escapeHTML(o)}</div>`).join('')}</div>
  `;
  bindOptions(val => {
    const ok = val === item.english;
    showResult(ok, item, `“${item.chinese}” → <b>${item.english}</b>`);
  });
}

function render_type_en_cn(item){
  $('#exercise').innerHTML = `
    <div class="exercise-title">Type the Chinese</div>
    <div class="sentence-card" style="margin-bottom:12px">
      <div class="muted" style="font-size:13px;margin-bottom:4px">Prompt</div>
      <div style="font-size:22px;font-weight:700">${escapeHTML(item.english)}</div>
    </div>
    <input id="ans" class="select" type="text" placeholder="点击或输入汉字…" autocomplete="off" autofocus>
    <div style="margin-top:14px" class="row gap-12">
      <button class="btn" id="checkBtn">Check</button>
      <button class="audio" onclick="speakCN(${JSON.stringify(item.chinese)})">🔊 Hear</button>
    </div>
  `;
  $('#checkBtn').addEventListener('click', ()=>{
    const val = normalizeCN($('#ans').value||'');
    const ok = val === normalizeCN(item.chinese);
    showResult(ok, item, `Correct: <b>${escapeHTML(item.chinese)}</b> (${escapeHTML(item.pinyin||'')})`);
  });
  $('#ans').addEventListener('keydown', e=>{ if (e.key==='Enter'){ e.preventDefault(); $('#checkBtn').click(); } });
}

function render_type_pinyin(item){
  const tonePad = buildTonePad();
  $('#exercise').innerHTML = `
    <div class="exercise-title">Type the Pinyin</div>
    <div class="sentence-card" style="margin-bottom:12px">
      <div style="font-size:30px;line-height:1.2">${escapeHTML(item.chinese)}</div>
      ${item.english ? `<div class="muted" style="margin-top:8px">${escapeHTML(item.english)}</div>`:''}
    </div>
    <input id="ans" class="select" type="text" placeholder="e.g. zhe ge pingguo hen da" autocomplete="off" autofocus>
    <p class="muted" style="font-size:12px;margin:6px 0 0">Tap a vowel or tone number to insert accents without typing them.</p>
    ${tonePad}
    <div style="margin-top:14px" class="row gap-12">
      <button class="btn" id="checkBtn">Check</button>
      <button class="audio" onclick="speakCN(${JSON.stringify(item.chinese)})">🔊 Hear</button>
    </div>
  `;
  const ans = $('#ans');
  attachTonePad(ans);
  $('#checkBtn').addEventListener('click', ()=>{
    const val = normalizePinyin(ans.value||'');
    const target = normalizePinyin(item.pinyin||'');
    const ok = val === target;
    showResult(ok, item, `Pinyin: <b>${escapeHTML(item.pinyin||'')}</b>`);
  });
  ans.addEventListener('keydown', e=>{ if (e.key==='Enter'){ e.preventDefault(); $('#checkBtn').click(); } });
}

function render_fill_blank(item){
  const target = normalizeCN(item.chinese||'');
  if (!target){
    return render_mc_cn_en(item);
  }
  let sentence = item.example || '';
  if (sentence && item.chinese && sentence.includes(item.chinese)){
    sentence = sentence.replace(item.chinese, '____');
  } else if (!sentence){
    sentence = '____';
  }
  const bankData = buildCharacterBank(item.chinese, pool);
  const targetChars = bankData ? bankData.targetChars : Array.from(target);
  const bank = bankData ? bankData.bank : targetChars.map((ch,idx)=>({char:ch,key:`t${idx}`}));
  $('#exercise').innerHTML = `
    <div class="exercise-title">Build the missing word</div>
    <div class="sentence-card">${escapeHTML(sentence).replace(/____/g,'<span style="letter-spacing:4px;color:#bfdbfe">____</span>')}</div>
    ${item.example_english ? `<div class="muted" style="margin:8px 0 4px"><i>${escapeHTML(item.example_english)}</i></div>`:''}
    <div class="fill-slots" id="blankSlots">${targetChars.map((_,i)=>`<div class="slot" data-slot="${i}"></div>`).join('')}</div>
    <div class="chips" id="charBank">${bank.map(b=>`<span class="chip fill-chip" data-i="${b.key}" data-char="${escapeHTML(b.char)}">${escapeHTML(b.char)}</span>`).join('')}</div>
    <div style="margin-top:14px" class="row gap-12">
      <button class="btn" id="checkBtn" disabled>Check</button>
      <button class="btn secondary" id="resetBtn">Reset</button>
      <button class="audio" onclick="speakCN(${JSON.stringify(item.example||item.chinese)})">🔊 Hear sentence</button>
    </div>
  `;

  const slots = Array.from(document.querySelectorAll('#blankSlots .slot'));
  const chips = Array.from(document.querySelectorAll('#charBank .fill-chip'));
  const checkBtn = $('#checkBtn');

  function updateCheckState(){
    const ready = slots.every(slot => slot.dataset.char && slot.dataset.char !== '');
    checkBtn.disabled = !ready;
  }

  updateCheckState();

  chips.forEach(chip => {
    chip.addEventListener('click', ()=>{
      const slot = slots.find(s => !s.dataset.char);
      if (!slot) return;
      slot.textContent = chip.dataset.char;
      slot.dataset.char = chip.dataset.char;
      slot.dataset.key = chip.dataset.i;
      slot.classList.add('filled');
      chip.classList.add('disabled');
      updateCheckState();
    });
  });

  slots.forEach(slot => {
    slot.addEventListener('click', ()=>{
      if (!slot.dataset.char) return;
      const key = slot.dataset.key;
      const chip = document.querySelector(`.fill-chip[data-i=\"${key}\"]`);
      if (chip) chip.classList.remove('disabled');
      slot.textContent = '';
      slot.classList.remove('filled');
      delete slot.dataset.char;
      delete slot.dataset.key;
      updateCheckState();
    });
  });

  $('#resetBtn').addEventListener('click', ()=>{
    slots.forEach(slot => {
      slot.textContent = '';
      slot.classList.remove('filled');
      delete slot.dataset.char;
      delete slot.dataset.key;
    });
    chips.forEach(chip => chip.classList.remove('disabled'));
    updateCheckState();
  });

  checkBtn.addEventListener('click', ()=>{
    const built = slots.map(s => s.dataset.char || '').join('');
    const ok = normalizeCN(built) === target;
    showResult(ok, item, `Sentence: <b>${escapeHTML(item.example||'')}</b><br>Pinyin: <span class="muted">${escapeHTML(item.example_pinyin||'')}</span>`);
  });
}
function render_order_pinyin(item){
  // Use pinyin sentence tokens (space separated). If absent, fallback to english tokens.
  let base = item.example_pinyin && item.example_pinyin.trim() ? item.example_pinyin : (item.example_english||'');
  const tokens = base.split(/\s+/).filter(Boolean);
  if (tokens.length<3){ // fallback to multiple choice if too short
    return render_mc_cn_en(item);
  }
  const shuffled = shuffle(tokens.slice());
  $('#exercise').innerHTML = `
    <div class="exercise-title">Rebuild the sentence</div>
    <div class="muted">Tap the glowing tiles in the correct order.</div>
    <div id="answer" class="chips" style="min-height:46px;margin:8px 0 10px"></div>
    <div id="bank" class="chips">${shuffled.map((t,i)=>`<span class="chip" data-i="${i}">${escapeHTML(t)}</span>`).join('')}</div>
    <div style="margin-top:10px" class="row gap-12">
      <button class="btn" id="checkBtn">Check</button>
      <button class="btn secondary" id="resetBtn">Reset</button>
    </div>
  `;
  const used = new Set();
  const ansEl = $('#answer');
  document.querySelectorAll('#bank .chip').forEach(ch=>{
    ch.addEventListener('click', ()=>{
      const idx = ch.getAttribute('data-i');
      if (used.has(idx)) return;
      used.add(idx);
      ch.classList.add('disabled');
      const span = document.createElement('span');
      span.className='chip';
      span.textContent = ch.textContent;
      span.dataset.i = idx;
      span.addEventListener('click', ()=>{
        used.delete(span.dataset.i);
        span.remove();
        ch.classList.remove('disabled');
      });
      ansEl.appendChild(span);
    });
  });
  $('#resetBtn').addEventListener('click', ()=>{
    used.clear();
    ansEl.innerHTML='';
    document.querySelectorAll('#bank .chip').forEach(ch=>ch.classList.remove('disabled'));
  });
  $('#checkBtn').addEventListener('click', ()=>{
    const built = Array.from(ansEl.querySelectorAll('.chip')).map(x=>x.textContent).join(' ').trim();
    const target = tokens.join(' ').trim();
    const ok = stripDiacritics(built) === stripDiacritics(target);
    showResult(ok, item, `Target: <b>${escapeHTML(target)}</b>`);
  });
}

/* ---------------------- Option binding + result ---------------------- */
function bindOptions(onPick){
  document.querySelectorAll('.option').forEach(el=>{
    el.addEventListener('click', ()=>{
      const val = el.getAttribute('data-val');
      const ok = onPick(val);
      if (ok===undefined) return; // When caller handles showResult itself
    });
  });
}

function showResult(ok, item, explainHtml=''){
  $('#nextBtn').disabled = false;
  if (ok){
    xp += 10; streak += 1; lessonXP += 10;
    toast('✅ Combo! +10 XP');
    $('#xp').textContent = xp; $('#streak').textContent = streak;
    save();
    // Mark correct option if present
    document.querySelectorAll('.option').forEach(el=>{
      const val = el.getAttribute('data-val');
      if (val && (val===item.english)) el.classList.add('correct');
    });
  } else {
    hearts -= 1; streak = 0;
    toast('❌ Missed it! -1 heart');
    $('#streak').textContent = streak;
    // Reveal correct if MC
    document.querySelectorAll('.option').forEach(el=>{
      const val = el.getAttribute('data-val');
      if (val && (val===item.english)) el.classList.add('correct');
      else el.classList.add('wrong');
    });
  }
  renderHeartsDisplay(hearts);
  updateProgressBar(qIndex + 1);
  $('#explain').innerHTML = `
    ${explainHtml}
    <div style="margin-top:6px" class="muted">EN: ${escapeHTML(item.english)} · CN: ${escapeHTML(item.chinese)} · Pinyin: ${escapeHTML(item.pinyin||'')}</div>
    ${item.literal ? `<div class="muted">Literal: ${escapeHTML(item.literal)}</div>`:''}
  `;
  if (hearts<=0){
    endLesson();
  }
}

function revealAndNext(awardXP=true){
  $('#nextBtn').disabled = false;
  toast('⏭ Skipped! No XP this time.');
  next();
}

function next(){
  if (qIndex < lesson.length-1){
    qIndex++;
    renderCurrent();
  } else {
    endLesson();
  }
}

function endLesson(){
  $('#exercise').innerHTML = `
    <div class="center">
      <h2 style="margin:6px 0 10px">Lesson Complete 🎉</h2>
      <p class="muted">XP earned this run: <b>+${Math.max(0, lessonXP)}</b> (session total shown above)</p>
      <div style="margin:10px 0" class="row gap-12 center">
        <button class="btn" onclick="startLesson(10)">Again</button>
      </div>
      <div class="divider"></div>
      <p class="muted">Review a few: <button class="btn secondary" onclick="startLesson(5)">Review 5</button></p>
    </div>
  `;
  $('#nextBtn').disabled = true;
  updateProgressBar(lesson.length);
  renderHeartsDisplay(hearts);
}

function toast(msg){
  const t = $('#toast');
  t.textContent = msg;
  t.classList.remove('hidden');
  clearTimeout(t._tid);
  t._tid = setTimeout(()=> t.classList.add('hidden'), 1600);
}

/* ---------------------- Kickoff ---------------------- */
renderCurrent();
</script>
</body>
</html>
