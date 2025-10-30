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
    --bg:#0f172a;--card:#111827;--muted:#94a3b8;--fg:#e5e7eb;--acc:#22c55e;--bad:#ef4444;--warn:#f59e0b;
    --chip:#1f2937;--chip2:#0b1224;
  }
  *{box-sizing:border-box}
  body{margin:0;background:linear-gradient(180deg,#0b1021,#0f172a);color:var(--fg);font-family:system-ui,-apple-system,Segoe UI,Roboto,Inter,Arial,sans-serif;min-height:100vh}
  header{padding:14px 16px;border-bottom:1px solid #1f2937;background:#0b1021;position:sticky;top:0;z-index:40}
  .wrap{max-width:1100px;margin:0 auto;padding:18px}
  h1{margin:0;font-size:22px;letter-spacing:.3px}
  .row{display:flex;gap:12px;flex-wrap:wrap;align-items:center}
  .card{background:linear-gradient(180deg,#0e162d,#0b1224);border:1px solid #1f2937;border-radius:16px;padding:16px}
  .pill{display:inline-flex;align-items:center;gap:8px;background:#0a1226;border:1px solid #14203b;border-radius:999px;padding:6px 10px;color:#cbd5e1;font-size:12px}
  input[type=file]{color:#cbd5e1}
  .btn{background:linear-gradient(180deg,#1a2b52,#132242);color:#fff;border:1px solid #1f3b6b;border-radius:10px;padding:10px 14px;font-weight:600;cursor:pointer}
  .btn:hover{filter:brightness(1.05)}
  .btn:disabled{opacity:.5;cursor:not-allowed}
  .btn.secondary{background:transparent;border:1px solid #334155}
  .select, select, input[type=number], input[type=text]{background:#0b1224;border:1px solid #19233f;border-radius:10px;padding:10px 12px;color:#e5e7eb}
  .flex{display:flex}.col{display:flex;flex-direction:column}.grow{flex:1}
  .gap-8{gap:8px}.gap-12{gap:12px}.gap-16{gap:16px}.gap-24{gap:24px}
  .grid{display:grid;gap:14px}
  .g-2{grid-template-columns:repeat(2,1fr)} .g-3{grid-template-columns:repeat(3,1fr)}
  .center{text-align:center}
  .tag{display:inline-flex;align-items:center;background:#0a1224;border:1px solid #18223b;border-radius:999px;padding:6px 10px;font-size:12px;color:#cbd5e1;margin-right:8px;margin-bottom:8px}
  .stat{background:#0b1224;border:1px solid #1b2847;border-radius:12px;padding:10px 12px;font-size:13px;color:#cbd5e1}
  .stat strong{color:#fff}
  .exercise{min-height:220px}
  .option{display:block;background:#0b1224;border:1px solid #1b2847;border-radius:12px;padding:12px 14px;margin:8px 0;cursor:pointer}
  .option.correct{border-color:#128541;background:rgba(34,197,94,.15)}
  .option.wrong{border-color:#8b1c1c;background:rgba(239,68,68,.15)}
  .toast{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#0b1224;border:1px solid #1b2847;color:#e5e7eb;padding:10px 14px;border-radius:10px}
  .hidden{display:none}
  .chips{display:flex;flex-wrap:wrap;gap:8px}
  .chip{background:#0f1a33;border:1px solid #1c2a4b;border-radius:10px;padding:6px 10px;cursor:pointer}
  .chip.disabled{opacity:.5;pointer-events:none}
  kbd{background:#0b1224;border:1px solid #1b2847;border-radius:6px;padding:2px 6px;font-size:12px}
  .audio{cursor:pointer;padding:6px 10px;border-radius:999px;border:1px solid #1b2a4a;background:#0a1224}
  .muted{color:#94a3b8}
  .foot{font-size:12px;color:#94a3b8;margin-top:8px}
  .divider{height:1px;background:#162142;margin:10px 0}
</style>
</head>
<body>
<header>
  <div class="wrap row">
    <h1 class="grow">Chinese Trainer</h1>
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
      <h3 style="margin:0 0 8px">Lesson Settings</h3>
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
        <button id="startBtn" class="btn">Start Lesson</button>
        <button id="reviewBtn" class="btn secondary">Quick Review (5)</button>
      </div>
      <p class="foot">Tip: You can also keep a <code>data.csv</code> in this folder. Uploading a new file replaces the in-session dataset.</p>
    </div>

    <div class="card">
      <h3 style="margin:0 0 8px">Stats</h3>
      <div class="divider"></div>
      <div class="row gap-12">
        <div class="stat">Hearts: <strong id="hearts">3</strong></div>
        <div class="stat">XP: <strong id="xp">0</strong></div>
        <div class="stat">Question: <strong id="qpos">0/0</strong></div>
        <div class="stat">Streak: <strong id="streak">0</strong></div>
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

/* ---------------------- UI Populate ---------------------- */
const TYPES = ['All', ...uniq(DATASET.map(x=>x.type||'Other'))].sort();
const typeFilter = $('#typeFilter');
typeFilter.innerHTML = TYPES.map(t=>`<option value="${escapeHTML(t)}">${escapeHTML(t)}</option>`).join('');
typeFilter.value = 'All';

$('#testAudio').addEventListener('click', ()=> speak('你好！开始上课。', 'zh-CN'));

/* ---------------------- State ---------------------- */
let pool = DATASET.slice();
let lesson = [];
let qIndex = 0;
let hearts = 3;
let xp = 0;
let streak = 0;
const SAVEKEY = `cntrainer-${DATA_HASH}`;
loadSave();

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
  qIndex = 0;
  $('#qpos').textContent = `0/${lesson.length}`;
  $('#hearts').textContent = hearts;
  $('#nextBtn').disabled = true;
  $('#explain').textContent = '';
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
  if (!cur){ $('#exercise').innerHTML = '<p class="muted">Click <b>Start Lesson</b> to begin.</p>'; return; }
  $('#qpos').textContent = `${qIndex+1}/${lesson.length}`;
  $('#nextBtn').disabled = true;
  $('#explain').textContent = '';

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
    <div class="row gap-12" style="align-items:center;justify-content:space-between">
      <div>
        <div style="font-size:32px;line-height:1.2">${escapeHTML(item.chinese)}</div>
        <div class="muted" style="margin-top:6px">${escapeHTML(item.pinyin||'')}</div>
      </div>
      <button class="audio" title="Play audio" onclick="speak('${item.chinese.replace(/'/g,'\\\'')}', 'zh-CN')">🔊</button>
    </div>
    <div style="margin-top:8px">${options.map(o=>`<div class="option" data-val="${escapeHTML(o)}">${escapeHTML(o)}</div>`).join('')}</div>
  `;
  bindOptions(val => {
    const ok = val === item.english;
    showResult(ok, item, `“${item.chinese}” → <b>${item.english}</b>`);
  });
}

function render_type_en_cn(item){
  $('#exercise').innerHTML = `
    <div class="muted">Type the Chinese for:</div>
    <div style="font-size:22px;margin:6px 0 12px"><b>${escapeHTML(item.english)}</b></div>
    <input id="ans" class="select" type="text" placeholder="输入汉字…" autocomplete="off" autofocus>
    <div style="margin-top:10px" class="row gap-12">
      <button class="btn" id="checkBtn">Check</button>
      <button class="audio" onclick="speak('${item.chinese.replace(/'/g,'\\\'')}', 'zh-CN')">🔊 Hear</button>
    </div>
  `;
  $('#checkBtn').addEventListener('click', ()=>{
    const val = normalizeCN($('#ans').value||'');
    const ok = val === normalizeCN(item.chinese);
    showResult(ok, item, `Correct: <b>${escapeHTML(item.chinese)}</b> (${escapeHTML(item.pinyin||'')})`);
  });
}

function render_type_pinyin(item){
  $('#exercise').innerHTML = `
    <div class="muted">Type the <b>Pinyin</b> for:</div>
    <div style="font-size:32px;margin:6px 0 12px">${escapeHTML(item.chinese)}</div>
    <input id="ans" class="select" type="text" placeholder="e.g. zhe ge pingguo hen da" autocomplete="off" autofocus>
    <div style="margin-top:10px" class="row gap-12">
      <button class="btn" id="checkBtn">Check</button>
      <button class="audio" onclick="speak('${item.chinese.replace(/'/g,'\\\'')}', 'zh-CN')">🔊 Hear</button>
    </div>
  `;
  $('#checkBtn').addEventListener('click', ()=>{
    const val = normalizePinyin($('#ans').value||'');
    const target = normalizePinyin(item.pinyin||'');
    const ok = val === target;
    showResult(ok, item, `Pinyin: <b>${escapeHTML(item.pinyin||'')}</b>`);
  });
}

function render_fill_blank(item){
  let s = item.example || '';
  // Try replacing an occurrence of the target word
  if (s && item.chinese && s.includes(item.chinese)){
    s = s.replace(item.chinese, '____');
  } else if (s) {
    // No direct match—still show sentence
  } else {
    s = '____';
  }
  $('#exercise').innerHTML = `
    <div class="muted">Fill in the missing Chinese word</div>
    <div style="font-size:26px;margin:6px 0 6px">${escapeHTML(s)}</div>
    ${item.example_english ? `<div class="muted" style="margin-bottom:10px"><i>${escapeHTML(item.example_english)}</i></div>`:''}
    <input id="ans" class="select" type="text" placeholder="汉字…" autocomplete="off" autofocus>
    <div style="margin-top:10px" class="row gap-12">
      <button class="btn" id="checkBtn">Check</button>
      <button class="audio" onclick="speak('${(item.example||item.chinese).replace(/'/g,'\\\'')}', 'zh-CN')">🔊 Hear sentence</button>
    </div>
  `;
  $('#checkBtn').addEventListener('click', ()=>{
    const val = normalizeCN($('#ans').value||'');
    const ok = val === normalizeCN(item.chinese);
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
    <div class="muted">Tap tokens to build the sentence in order</div>
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
    xp += 10; streak += 1;
    toast('✅ Correct! +10 XP');
    $('#xp').textContent = xp; $('#streak').textContent = streak;
    save();
    // Mark correct option if present
    document.querySelectorAll('.option').forEach(el=>{
      const val = el.getAttribute('data-val');
      if (val && (val===item.english)) el.classList.add('correct');
    });
  } else {
    hearts -= 1; streak = 0;
    toast('❌ Not quite.');
    $('#hearts').textContent = Math.max(hearts,0); $('#streak').textContent = streak;
    // Reveal correct if MC
    document.querySelectorAll('.option').forEach(el=>{
      const val = el.getAttribute('data-val');
      if (val && (val===item.english)) el.classList.add('correct');
      else el.classList.add('wrong');
    });
  }
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
  toast('⏭ Skipped.');
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
      <p class="muted">XP earned: <b>+${Math.max(0, xp - (parseInt($('#xp').textContent)||0))}</b> (session total shown above)</p>
      <div style="margin:10px 0" class="row gap-12 center">
        <button class="btn" onclick="startLesson(10)">Again</button>
      </div>
      <div class="divider"></div>
      <p class="muted">Review a few: <button class="btn secondary" onclick="startLesson(5)">Review 5</button></p>
    </div>
  `;
  $('#nextBtn').disabled = true;
}

function toast(msg){
  const t = $('#toast');
  t.textContent = msg;
  t.classList.remove('hidden');
  clearTimeout(t._tid);
  t._tid = setTimeout(()=> t.classList.add('hidden'), 1200);
}

/* ---------------------- Kickoff ---------------------- */
renderCurrent();
</script>
</body>
</html>
