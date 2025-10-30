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
    --bg:#020817;--bg-secondary:#0a1d3a;--card:#0d1730;--muted:#9ca3af;--fg:#f8fafc;--acc:#3b82f6;--acc2:#22d3ee;--good:#22c55e;--bad:#ef4444;
    --warn:#f59e0b;--chip:#102041;--chip2:#0b1630;
  }
  *{box-sizing:border-box}
  body{margin:0;background:radial-gradient(circle at top,var(--bg-secondary),var(--bg));color:var(--fg);font-family:"Nunito",system-ui,-apple-system,Segoe UI,Roboto,Inter,Arial,sans-serif;min-height:100vh}
  header{padding:16px;border-bottom:1px solid rgba(59,130,246,.2);background:rgba(3,8,24,.9);backdrop-filter:blur(12px);position:sticky;top:0;z-index:40}
  .wrap{max-width:1100px;margin:0 auto;padding:18px}
  h1{margin:0;font-size:26px;letter-spacing:.4px;display:flex;align-items:center;gap:10px}
  h1 span{display:inline-flex;align-items:center;justify-content:center;width:30px;height:30px;border-radius:12px;background:linear-gradient(135deg,var(--acc),var(--acc2));color:#fff;font-size:18px}
  .row{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
  .card{position:relative;background:linear-gradient(160deg,rgba(17,31,67,.9),rgba(9,20,44,.85));border:1px solid rgba(59,130,246,.2);border-radius:20px;padding:20px;box-shadow:0 18px 40px rgba(15,23,42,.35);overflow:hidden}
  .card::after{content:"";position:absolute;inset:0;border-radius:20px;border:1px solid rgba(56,189,248,.08);pointer-events:none}
  .pill{display:inline-flex;align-items:center;gap:8px;background:rgba(15,118,255,.12);border:1px solid rgba(37,99,235,.4);border-radius:999px;padding:7px 12px;color:#dbeafe;font-size:12px;text-transform:uppercase;letter-spacing:.08em}
  input[type=file]{color:#e0f2fe}
  .btn{background:linear-gradient(135deg,var(--acc),var(--acc2));color:#fff;border:0;border-radius:12px;padding:11px 16px;font-weight:700;cursor:pointer;box-shadow:0 10px 24px rgba(59,130,246,.35);transition:transform .15s ease,box-shadow .15s ease}
  .btn:hover{transform:translateY(-2px);box-shadow:0 16px 30px rgba(59,130,246,.45)}
  .btn:disabled{opacity:.6;cursor:not-allowed;box-shadow:none;transform:none}
  .btn.secondary{background:linear-gradient(135deg,#0f172a,#1e293b);box-shadow:none;border:1px solid rgba(148,163,184,.3)}
  .select, select, input[type=number], input[type=text]{background:rgba(13,23,48,.85);border:1px solid rgba(59,130,246,.25);border-radius:12px;padding:11px 14px;color:#e0f2fe}
  .flex{display:flex}.col{display:flex;flex-direction:column}.grow{flex:1}
  .gap-8{gap:8px}.gap-12{gap:12px}.gap-16{gap:16px}.gap-24{gap:24px}
  .grid{display:grid;gap:18px}
  .g-2{grid-template-columns:repeat(2,1fr)} .g-3{grid-template-columns:repeat(3,1fr)}
  .center{text-align:center}
  .tag{display:inline-flex;align-items:center;background:rgba(15,118,255,.08);border:1px solid rgba(59,130,246,.25);border-radius:999px;padding:6px 12px;font-size:12px;color:#bfdbfe;margin-right:8px;margin-bottom:8px}
  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:12px;margin-top:10px}
  .stat{position:relative;background:rgba(8,20,45,.95);border:1px solid rgba(94,234,212,.15);border-radius:16px;padding:12px 14px;font-size:13px;color:#cbd5e1;box-shadow:0 12px 24px rgba(13,42,80,.35)}
  .stat .label{font-size:12px;text-transform:uppercase;letter-spacing:.1em;color:rgba(226,232,240,.7)}
  .stat .value{margin-top:6px;font-size:18px;font-weight:700;color:#fff}
  .stat .value span{font-size:15px;font-weight:600;color:#bae6fd}
  .exercise{min-height:240px}
  .option{display:block;background:rgba(13,23,48,.85);border:1px solid rgba(59,130,246,.25);border-radius:14px;padding:14px 16px;margin:10px 0;cursor:pointer;transition:transform .15s ease,box-shadow .15s ease}
  .option:hover{transform:translateY(-1px);box-shadow:0 12px 20px rgba(37,99,235,.25)}
  .option.correct{border-color:rgba(34,197,94,.8);background:rgba(22,163,74,.25);box-shadow:0 12px 24px rgba(34,197,94,.25)}
  .option.wrong{border-color:rgba(239,68,68,.7);background:rgba(239,68,68,.2);box-shadow:none}
  .toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:rgba(15,23,42,.9);border:1px solid rgba(59,130,246,.3);color:#e5e7eb;padding:12px 18px;border-radius:14px;box-shadow:0 18px 30px rgba(15,23,42,.5)}
  .hidden{display:none}
  .chips{display:flex;flex-wrap:wrap;gap:10px}
  .chip{background:linear-gradient(160deg,var(--chip),var(--chip2));border:1px solid rgba(37,99,235,.4);border-radius:12px;padding:7px 12px;cursor:pointer;transition:transform .15s ease,opacity .15s ease}
  .chip:hover{transform:translateY(-2px)}
  .chip.disabled{opacity:.4;pointer-events:none;transform:none}
  kbd{background:#0b1224;border:1px solid #1b2847;border-radius:6px;padding:2px 6px;font-size:12px}
  .audio{cursor:pointer;padding:8px 12px;border-radius:999px;border:1px solid rgba(59,130,246,.4);background:rgba(14,23,45,.9);color:#93c5fd;transition:transform .15s ease}
  .audio:hover{transform:translateY(-2px);color:#e0f2fe}
  .muted{color:var(--muted)}
  .foot{font-size:12px;color:var(--muted);margin-top:8px}
  .divider{height:1px;background:rgba(59,130,246,.18);margin:10px 0}
  .hearts{display:flex;gap:6px;font-size:20px;color:#fda4af}
  .hearts .full{filter:drop-shadow(0 0 6px rgba(244,114,182,.6));}
  .hearts .empty{color:rgba(248,113,113,.35)}
  .progress{background:rgba(148,163,184,.25);border-radius:999px;height:10px;overflow:hidden;margin-top:8px}
  .progress-bar{height:100%;width:0;background:linear-gradient(90deg,var(--acc),var(--acc2));transition:width .3s ease}
  .fill-slots{display:flex;gap:8px;margin:14px 0 12px;flex-wrap:wrap}
  .slot{width:46px;height:52px;border-radius:12px;border:2px dashed rgba(148,163,184,.4);display:flex;align-items:center;justify-content:center;font-size:26px;font-weight:700;background:rgba(13,23,48,.6);cursor:pointer;transition:border-color .2s ease,background .2s ease}
  .slot.filled{border-style:solid;border-color:rgba(59,130,246,.6);background:rgba(37,99,235,.2)}
  .sentence-card{background:rgba(15,23,42,.5);border-radius:14px;padding:12px 14px;margin-top:8px;line-height:1.5}
  .exercise-title{font-size:14px;letter-spacing:.08em;text-transform:uppercase;color:rgba(190,227,248,.8)}
</style>
</head>
<body>
<header>
  <div class="wrap row">
    <h1 class="grow"><span>🎮</span>Chinese Trainer</h1>
    <span class="pill">Loaded: <strong style="margin-left:6px"><?php echo htmlspecialchars($dataSource) ?></strong> · <?php echo $total ?> rows · dataset #<?php echo $hash ?></span>
    <form method="post" enctype="multipart/form-data" class="row">
      <input type="file" name="csv" accept=".csv,.tsv,text/csv,text/tab-separated-values">
      <button class="btn">Upload CSV</button>
    </form>
    <form method="get" class="row">
      <input type="hidden" name="clear" value="1">
      <button class="btn secondary" title="Forget uploaded dataset from session">Reset dataset</button>
    </form>
  </div>
</header>

<div class="wrap">
  <div class="grid g-2">
    <div class="card">
      <h3 style="margin:0 0 8px">Mission Control</h3>
      <div class="divider"></div>
      <div class="row gap-16">
        <div class="col">
          <label class="muted">Filter by Type</label>
          <select id="typeFilter" class="select"></select>
        </div>
        <div class="col">
          <label class="muted">Questions</label>
          <input id="qCount" type="number" min="5" max="50" value="10">
        </div>
        <div class="col">
          <label class="muted">Exercise Types</label>
          <div class="row gap-12">
            <label><input type="checkbox" class="et" value="mc_cn_en" checked> CN→EN</label>
            <label><input type="checkbox" class="et" value="type_en_cn" checked> EN→CN</label>
            <label><input type="checkbox" class="et" value="type_pinyin" checked> Pinyin</label>
            <label><input type="checkbox" class="et" value="fill_blank" checked> Fill-in</label>
            <label><input type="checkbox" class="et" value="order_pinyin" checked> Re-order</label>
          </div>
        </div>
        <div class="col">
          <label class="muted">Audio</label>
          <div class="row gap-12">
            <button id="testAudio" type="button" class="btn secondary">🔊 Test</button>
            <span class="muted">Uses browser TTS (zh-CN)</span>
          </div>
        </div>
      </div>
      <div style="margin-top:14px" class="row gap-12">
        <button id="startBtn" class="btn">Launch Lesson</button>
        <button id="reviewBtn" class="btn secondary">Quick Sprint (5)</button>
      </div>
      <p class="foot">Tip: You can also keep a <code>data.csv</code> in this folder. Uploading a new file replaces the in-session dataset.</p>
    </div>

    <div class="card">
      <h3 style="margin:0 0 8px">Player HUD</h3>
      <div class="divider"></div>
      <div class="stats">
        <div class="stat">
          <div class="label">Hearts</div>
          <div class="value hearts" id="hearts"></div>
        </div>
        <div class="stat">
          <div class="label">XP</div>
          <div class="value"><span id="xp">0</span> pts</div>
        </div>
        <div class="stat">
          <div class="label">Question</div>
          <div class="value"><span id="qpos">0/0</span></div>
        </div>
        <div class="stat">
          <div class="label">Streak</div>
          <div class="value"><span id="streak">0</span> 🔥</div>
        </div>
      </div>
      <div class="divider"></div>
      <div>
        <div class="label" style="font-size:12px;text-transform:uppercase;letter-spacing:.08em;color:rgba(148,163,184,.9)">Lesson Progress</div>
        <div class="progress"><div class="progress-bar" id="progressBar"></div></div>
      </div>
      <div class="divider"></div>
      <div class="row gap-12">
        <span class="tag">Dataset hash: <?php echo $hash ?></span>
        <span class="tag">Saved locally with key <code>cntrainer-<?php echo $hash ?></code></span>
      </div>
    </div>
  </div>

  <div id="card" class="card" style="margin-top:16px">
    <div id="exercise" class="exercise"></div>
    <div id="controls" class="row gap-12">
      <button id="skipBtn" class="btn secondary">Skip</button>
      <button id="nextBtn" class="btn" disabled>Next</button>
    </div>
    <div id="explain" class="foot"></div>
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
  $('#exercise').innerHTML = `
    <div class="exercise-title">Type the Pinyin</div>
    <div class="sentence-card" style="margin-bottom:12px">
      <div style="font-size:30px;line-height:1.2">${escapeHTML(item.chinese)}</div>
      ${item.english ? `<div class="muted" style="margin-top:8px">${escapeHTML(item.english)}</div>`:''}
    </div>
    <input id="ans" class="select" type="text" placeholder="e.g. zhe ge pingguo hen da" autocomplete="off" autofocus>
    <div style="margin-top:14px" class="row gap-12">
      <button class="btn" id="checkBtn">Check</button>
      <button class="audio" onclick="speakCN(${JSON.stringify(item.chinese)})">🔊 Hear</button>
    </div>
  `;
  $('#checkBtn').addEventListener('click', ()=>{
    const val = normalizePinyin($('#ans').value||'');
    const target = normalizePinyin(item.pinyin||'');
    const ok = val === target;
    showResult(ok, item, `Pinyin: <b>${escapeHTML(item.pinyin||'')}</b>`);
  });
  $('#ans').addEventListener('keydown', e=>{ if (e.key==='Enter'){ e.preventDefault(); $('#checkBtn').click(); } });
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
