<?php

function loadLines(string $filename): array
{
    $raw = file_get_contents(__DIR__ . DIRECTORY_SEPARATOR . $filename);
    if ($raw === false) {
        throw new RuntimeException("Cannot read file: {$filename}");
    }
    $raw = preg_replace('/^\xEF\xBB\xBF/', '', $raw);
    $lines = preg_split('/\r?\n/', $raw);
    return array_values(array_filter(array_map('trim', $lines)));
}

function loadNames(): array
{
    return loadLines('name.txt');
}

function loadDomains(): array
{
    return loadLines('domain.txt');
}

/**
 * Parse uszips.txt: zip,city,state_id,state_name
 * Returns array of [ 'zip' => ..., 'city' => ..., 'state_id' => ..., 'state_name' => ... ]
 */
function loadUsZips(): array
{
    $lines = loadLines('uszips.txt');
    $header = preg_replace('/^\xEF\xBB\xBF/u', '', $lines[0]);
    if (strpos($header, 'zip,city,state_id,state_name') !== 0) {
        throw new RuntimeException('Unexpected uszips.txt header: ' . $header);
    }
    $rows = [];
    for ($i = 1, $n = count($lines); $i < $n; $i++) {
        $parts = explode(',', $lines[$i], 4);
        $rows[] = [
            'zip' => $parts[0] ?? '',
            'city' => $parts[1] ?? '',
            'state_id' => $parts[2] ?? '',
            'state_name' => $parts[3] ?? '',
        ];
    }
    return $rows;
}

/**
 * Parse phone.txt: StateName,area1,area2,...
 * State name can contain comma (e.g. Washington,DC). Area codes are 3-digit.
 * Returns [ state_name => [ area_codes... ] ]
 */
function loadPhoneByState(): array
{
    $lines = loadLines('phone.txt');
    $map = [];
    foreach ($lines as $line) {
        $parts = explode(',', $line);
        $areaCodes = [];
        $i = count($parts) - 1;
        while ($i >= 0 && preg_match('/^\d{3}$/', trim($parts[$i]))) {
            array_unshift($areaCodes, trim($parts[$i]));
            $i--;
        }
        $stateName = trim(implode(',', array_slice($parts, 0, $i + 1)));
        if ($stateName !== '' && count($areaCodes) > 0) {
            $map[$stateName] = $areaCodes;
        }
    }
    return $map;
}

function pickRandom(array $arr)
{
    return $arr[array_rand($arr)];
}

function randomDigits(int $len): string
{
    $s = '';
    for ($i = 0; $i < $len; $i++) {
        $s .= (string) random_int(0, 9);
    }
    return $s;
}

function randomInt(int $min, int $max): int
{
    return random_int($min, $max);
}

/**
 * Insert random numbers into username for email.
 */
function insertEmailSeparator(string $username): string
{
    $sep = randomDigits(randomInt(1, 3));
    $len = strlen($username);
    if ($len <= 2) {
        return $username . $sep . randomDigits(1);
    }

    $digitStart = $len;
    while ($digitStart > 0 && ctype_digit($username[$digitStart - 1])) {
        $digitStart--;
    }

    $insertPositions = [1, 2];
    if ($digitStart < $len && $digitStart > 0) {
        $insertPositions[] = $digitStart;
    }
    $pos = $insertPositions[array_rand($insertPositions)];
    return substr($username, 0, $pos) . $sep . substr($username, $pos);
}

/**
 * Generate one user detail array.
 */
function generateUser(): array
{
    $names = loadNames();
    $domains = loadDomains();
    $zips = loadUsZips();
    $phoneByState = loadPhoneByState();

    $first = pickRandom($names);
    $last = pickRandom($names);

    $lastClean = strtolower(preg_replace('/\s+/', '', $last));
    $firstClean = strtolower(preg_replace('/\s+/', '', $first));
    $usernameBase = (mt_rand() / mt_getrandmax()) < 0.5
        ? strtolower($first[0]) . $lastClean
        : strtolower($last[0]) . $firstClean;
    $username = $usernameBase . randomDigits(randomInt(2, 4));

    $emailLocal = insertEmailSeparator($username);
    $domain = pickRandom($domains);
    $email = $emailLocal . '@' . $domain;

    $row = pickRandom($zips);
    $city = $row['city'];
    $state1 = $row['state_name'];
    $state2 = $row['state_id'];
    $zip = $row['zip'];

    $streetNum = randomInt(100, 9999);
    $randomCity = pickRandom($zips)['city'];
    $street = $streetNum . ' ' . $randomCity . ' ' . $city;

    $areaCodes = $phoneByState[$state1] ?? null;
    $areaCode = $areaCodes ? pickRandom($areaCodes) : randomDigits(3);
    $rest = randomDigits(7);
    $phone = $areaCode . $rest;
    $phone2 = $areaCode . '-' . substr($rest, 0, 3) . '-' . substr($rest, 3);

    return [
        'country' => 'United States',
        'first' => $first,
        'last' => $last,
        'email' => $email,
        'phone' => $phone,
        'phone2' => $phone2,
        'street' => $street,
        'city' => $city,
        'state1' => $state1,
        'state2' => $state2,
        'zip' => $zip,
        'username' => $username,
    ];
}

// Preserve the original command-line interface.
if (php_sapi_name() === 'cli') {
    $count = isset($argv[1]) ? max(1, (int) $argv[1]) : 1;
    $users = [];
    for ($i = 0; $i < $count; $i++) $users[] = generateUser();
    echo $count === 1 ? json_encode($users[0], JSON_UNESCAPED_SLASHES) . "\n" : json_encode($users, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit;
}
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $count = filter_input(INPUT_POST, 'count', FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 20]]);
        if ($count === false || $count === null) throw new InvalidArgumentException('Count must be between 1 and 20.');
        $users = [];
        for ($i = 0; $i < $count; $i++) $users[] = generateUser();
        echo json_encode(['ok' => true, 'users' => $users], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    } catch (Throwable $error) {
        http_response_code($error instanceof InvalidArgumentException ? 422 : 500);
        echo json_encode(['ok' => false, 'message' => $error instanceof InvalidArgumentException ? $error->getMessage() : 'Generation failed. Check that the data files are available and try again.'], JSON_UNESCAPED_SLASHES);
    }
    exit;
}
?>
<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Identity Generator</title><style>:root{color-scheme:light;--ink:#17201d;--muted:#61706a;--line:#d9e0dc;--canvas:#f4f7f5;--accent:#087f5b;--dark:#056044;--soft:#e4f5ee;--danger:#a61b1b}*{box-sizing:border-box}body{margin:0;min-height:100vh;background:var(--canvas);color:var(--ink);font-family:Inter,ui-sans-serif,system-ui,-apple-system,"Segoe UI",sans-serif}button,input{font:inherit}.shell{width:min(1080px,calc(100% - 32px));margin:auto;padding:56px 0 72px}header{display:flex;align-items:end;justify-content:space-between;gap:28px;margin-bottom:24px}.eyebrow{margin:0 0 7px;color:var(--dark);font-size:.76rem;font-weight:800;letter-spacing:.12em;text-transform:uppercase}h1{margin:0;font-size:clamp(2rem,5vw,3.4rem);line-height:1;letter-spacing:0}.subtitle{max-width:480px;margin:12px 0 0;color:var(--muted);line-height:1.6}.controls{display:flex;align-items:end;gap:10px;flex:none}.count-control{display:grid;gap:7px}label{color:var(--muted);font-size:.78rem;font-weight:700}input{width:76px;height:52px;border:1px solid var(--line);border-radius:6px;background:#fff;padding:0 12px;font-weight:700}input:focus-visible,button:focus-visible{outline:3px solid rgba(8,127,91,.26);outline-offset:2px}.generate{height:52px;border:0;border-radius:6px;background:var(--accent);color:#fff;padding:0 25px;font-weight:850;letter-spacing:.04em;cursor:pointer;box-shadow:0 8px 20px rgba(8,127,91,.2)}.generate:hover{background:var(--dark)}.generate:disabled{cursor:wait;opacity:.62}.status{min-height:24px;margin:0 0 10px;color:var(--muted);font-size:.9rem}.status.error{border:1px solid #f0b8b8;border-radius:6px;background:#fff0f0;color:var(--danger);padding:12px 14px}.results{display:grid;gap:16px}.record{overflow:hidden;border:1px solid var(--line);border-radius:8px;background:#fff;box-shadow:0 18px 50px rgba(23,32,29,.09)}.record-head{display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--line);padding:15px 18px}.record-head h2{margin:0;font-size:1rem}.hint{color:var(--muted);font-size:.78rem}.fields{display:grid;grid-template-columns:repeat(3,minmax(0,1fr))}.field{position:relative;min-width:0;border:0;border-right:1px solid var(--line);border-bottom:1px solid var(--line);background:transparent;padding:16px 18px;text-align:left;cursor:copy}.field:nth-child(3n){border-right:0}.field:nth-last-child(-n+3){border-bottom:0}.field:hover{background:#f7faf8}.field.copied{background:var(--soft)}.field-label{display:block;margin-bottom:6px;color:var(--muted);font-size:.7rem;font-weight:800;text-transform:uppercase}.field-value{display:block;overflow-wrap:anywhere;color:var(--ink);font-family:ui-monospace,Consolas,monospace;font-size:.91rem;line-height:1.45}.copy-note{position:absolute;top:8px;right:10px;color:var(--dark);font-size:.69rem;font-weight:800;opacity:0}.field.copied .copy-note{opacity:1}.empty{border:1px dashed #bbc7c1;border-radius:8px;background:rgba(255,255,255,.52);color:var(--muted);padding:50px 24px;text-align:center}.sr-only{position:absolute;width:1px;height:1px;overflow:hidden;clip:rect(0,0,0,0)}@media(max-width:760px){.shell{width:min(100% - 24px,560px);padding-top:32px}header{align-items:stretch;flex-direction:column}.controls{width:100%}.count-control{flex:1}input{width:100%}.generate{flex:1.6;padding:0 16px}.fields{grid-template-columns:1fr}.field,.field:nth-child(3n),.field:nth-last-child(-n+3){border-right:0;border-bottom:1px solid var(--line)}.field:last-child{border-bottom:0}.hint{display:none}}@media(prefers-reduced-motion:reduce){*{transition:none!important}}</style></head><body>
<main class="shell"><header><div><p class="eyebrow">Utility / US Profiles</p><h1>Identity Generator</h1><p class="subtitle">Generate realistic test records, then select any field to copy its exact value.</p></div><form class="controls" id="generator-form"><div class="count-control"><label for="count">Records</label><input id="count" name="count" type="number" min="1" max="20" value="1" inputmode="numeric" required></div><button class="generate" id="generate-button" type="submit">GENERATE</button></form></header>
<p class="status" id="status" role="status" aria-live="polite">Ready to generate.</p><section class="results" id="results" aria-label="Generated records"><div class="empty">Your generated records will appear here.</div></section><div class="sr-only" id="copy-status" role="status" aria-live="polite"></div></main><script>const form=document.getElementById('generator-form'),countInput=document.getElementById('count'),generateButton=document.getElementById('generate-button'),results=document.getElementById('results'),status=document.getElementById('status'),copyStatus=document.getElementById('copy-status');
const labels={country:'Country',first:'First name',last:'Last name',email:'Email',phone:'Phone',phone2:'Formatted phone',street:'Street',city:'City',state1:'State',state2:'State code',zip:'ZIP code',username:'Username'};
function createField(key,value){const field=document.createElement('button');field.type='button';field.className='field';field.dataset.value=String(value??'');field.setAttribute('aria-label',`Copy ${labels[key]||key}: ${field.dataset.value}`);const label=document.createElement('span');label.className='field-label';label.textContent=labels[key]||key;const val=document.createElement('span');val.className='field-value';val.textContent=field.dataset.value;const note=document.createElement('span');note.className='copy-note';note.textContent='COPIED';note.setAttribute('aria-hidden','true');field.append(label,val,note);field.addEventListener('click',()=>copyValue(field));return field}
function renderUsers(users){results.replaceChildren();users.forEach((user,index)=>{const record=document.createElement('article');record.className='record';const head=document.createElement('div');head.className='record-head';const title=document.createElement('h2');title.textContent=`Record ${index+1}`;const hint=document.createElement('span');hint.className='hint';hint.textContent='Select a field to copy';head.append(title,hint);const fields=document.createElement('div');fields.className='fields';Object.entries(user).forEach(([key,value])=>fields.append(createField(key,value)));record.append(head,fields);results.append(record)})}
function fallbackCopy(text){const area=document.createElement('textarea');area.value=text;area.setAttribute('readonly','');area.style.cssText='position:fixed;opacity:0';document.body.append(area);area.select();const copied=document.execCommand('copy');area.remove();if(!copied)throw new Error('Copy rejected')}
async function copyValue(field){try{if(navigator.clipboard&&window.isSecureContext)await navigator.clipboard.writeText(field.dataset.value);else fallbackCopy(field.dataset.value);document.querySelectorAll('.field.copied').forEach(item=>item.classList.remove('copied'));field.classList.add('copied');copyStatus.textContent=`${field.querySelector('.field-label').textContent} copied.`;setTimeout(()=>field.classList.remove('copied'),1500)}catch(error){copyStatus.textContent='Copy failed. Select the value and copy it manually.';status.textContent='Could not access the clipboard. Your browser may be blocking copy access.';status.className='status error'}}
form.addEventListener('submit',async event=>{event.preventDefault();if(!form.reportValidity())return;generateButton.disabled=true;generateButton.textContent='GENERATING...';status.className='status';status.textContent='Generating records...';try{const response=await fetch(window.location.href,{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:new URLSearchParams({count:countInput.value})});const payload=await response.json();if(!response.ok||!payload.ok)throw new Error(payload.message||'Generation failed.');if(!Array.isArray(payload.users)||!payload.users.length)throw new Error('No records were generated.');renderUsers(payload.users);status.textContent=`${payload.users.length} ${payload.users.length===1?'record':'records'} generated.`}catch(error){results.replaceChildren();const empty=document.createElement('div');empty.className='empty';empty.textContent='No records available. Please try again.';results.append(empty);status.className='status error';status.textContent=error instanceof Error?error.message:'Generation failed. Please try again.'}finally{generateButton.disabled=false;generateButton.textContent='GENERATE'}});</script></body></html>