<?php
declare(strict_types=1);

namespace Portal;

/**
 * Small server-rendered HTML pages for guests and staff. They call the same /v1 API as any client.
 * A per-response nonce lets the one inline script run under a strict Content-Security-Policy.
 */
final class Pages
{
    /** @return array{0:int,1:string,2:array<string,string>} */
    public static function render(string $title, string $body, string $csrf, ?array $user, string $script = ''): array
    {
        $nonce = base64_encode(random_bytes(12));
        $e = static fn (string $s): string => htmlspecialchars($s, ENT_QUOTES, 'UTF-8');
        $who = $user === null ? '<a href="/login">Sign in</a>' : $e($user['role']) . ' &middot; <a href="#" id="logout">Sign out</a>';
        $html = <<<HTML
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="csrf" content="{$e($csrf)}"><title>{$e($title)} - MA Hotel</title>
<style>body{font:16px/1.5 system-ui,sans-serif;max-width:56rem;margin:0 auto;padding:1rem}nav{display:flex;gap:1rem;flex-wrap:wrap;justify-content:space-between;border-bottom:1px solid #ccc;padding-bottom:.5rem}
input,select,button{font:inherit;padding:.4rem;margin:.2rem 0}label{display:block}pre{background:#f4f4f4;padding:.75rem;overflow:auto}.row{display:flex;gap:.5rem;flex-wrap:wrap;align-items:end}fieldset{margin:1rem 0}</style></head>
<body><nav><strong><a href="/">MA Hotel</a></strong><span><a href="/staff">Staff</a> &middot; {$who}</span></nav>
<main><h1>{$e($title)}</h1>{$body}</main>
<script nonce="{$nonce}">
const csrf=document.querySelector('meta[name=csrf]').content;
async function api(method,path,body,idem){const h={'Content-Type':'application/json'};if(csrf)h['X-CSRF-Token']=csrf;if(idem)h['Idempotency-Key']=crypto.randomUUID();
 const r=await fetch(path,{method,headers:h,body:body?JSON.stringify(body):undefined,credentials:'same-origin'});return{status:r.status,data:await r.json().catch(()=>({}))}}
const show=(id,x)=>{document.getElementById(id).textContent=JSON.stringify(x,null,2)};
const money=m=>'PHP '+(m/100).toLocaleString('en-PH',{minimumFractionDigits:2});
document.getElementById('logout')?.addEventListener('click',async e=>{e.preventDefault();await api('POST','/v1/logout');location='/'});
{$script}
</script></body></html>
HTML;
        return [200, $html, [
            'Content-Type'            => 'text/html; charset=utf-8',
            'Content-Security-Policy' => "default-src 'none'; script-src 'nonce-{$nonce}'; style-src 'unsafe-inline'; connect-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'",
            'X-Content-Type-Options'  => 'nosniff',
            'Cache-Control'           => 'no-store',
        ]];
    }

    public static function index(string $csrf, ?array $user): array
    {
        $today = gmdate('Y-m-d');
        $body = <<<HTML
<form id="f" class="row"><label>Check-in<input type="date" name="checkIn" value="{$today}" required></label>
<label>Check-out<input type="date" name="checkOut" required></label>
<label>Rooms<input type="number" name="quantity" min="1" max="5" value="1"></label><button>Search</button></form>
<div id="results"></div><pre id="out"></pre>
HTML;
        $script = <<<'JS'
document.getElementById('f').addEventListener('submit',async e=>{e.preventDefault();const q=new URLSearchParams(new FormData(e.target));
 const r=await api('GET','/v1/availability?'+q);const box=document.getElementById('results');box.textContent='';
 if(r.status!==200){show('out',r.data);return}
 for(const o of r.data.options){const d=document.createElement('p');d.textContent=`${o.name}: ${o.available} left, ${money(o.totalMinor)} for ${r.data.nights} night(s) `;
  if(o.quoteId){const b=document.createElement('button');b.textContent='Hold for 10 minutes';b.onclick=async()=>{
   const h=await api('POST','/v1/reservations',{quoteId:o.quoteId,roomTypeId:o.roomTypeId,checkIn:r.data.checkIn,checkOut:r.data.checkOut,quantity:r.data.quantity},true);
   if(h.status===201)location='/reservations/'+h.data.reservationId;else show('out',h.data)};d.append(b)}
  box.append(d)}});
JS;
        return self::render('Find a room', $body, $csrf, $user, $script);
    }

    public static function login(string $csrf): array
    {
        $body = <<<'HTML'
<form id="f"><label>Email<input name="email" type="email" required autocomplete="username"></label>
<label>Password<input name="password" type="password" required autocomplete="current-password"></label>
<label>6-digit code (staff only)<input name="otp" inputmode="numeric" autocomplete="one-time-code"></label><button>Sign in</button></form><pre id="out"></pre>
HTML;
        $script = <<<'JS'
document.getElementById('f').addEventListener('submit',async e=>{e.preventDefault();const r=await api('POST','/v1/login',Object.fromEntries(new FormData(e.target)));
 if(r.status===200)location='/';else show('out',r.data)});
JS;
        return self::render('Sign in', $body, $csrf, null, $script);
    }

    public static function reservation(string $id, string $csrf, ?array $user): array
    {
        $body = '<p>Status is read from the booking service. A payment page cannot confirm a booking; only the verified payment notice can.</p>'
            . '<div class="row"><button id="pay">Pay with PayMongo (sandbox)</button><button id="cancel">Cancel</button></div><pre id="out"></pre>';
        $script = 'const id=' . json_encode($id) . <<<'JS'
;async function load(){const r=await api('GET','/v1/reservations/'+id);show('out',r.data);return r.data.status}
document.getElementById('pay').onclick=async()=>{const r=await api('POST','/v1/reservations/'+id+'/checkout',{},true);r.status===201?location=r.data.checkoutUrl:show('out',r.data)};
document.getElementById('cancel').onclick=async()=>{show('out',(await api('POST','/v1/reservations/'+id+'/cancel',{})).data)};
load();const t=setInterval(async()=>{if(['CONFIRMED','CANCELLED','EXPIRED'].includes(await load()))clearInterval(t)},3000);
JS;
        return self::render('Your reservation', $body, $csrf, $user, $script);
    }

    /** Stand-in for PayMongo's hosted page. Only shown when PAYMONGO_MODE=mock. */
    public static function mockCheckout(string $sessionId, string $csrf, ?array $user): array
    {
        $body = '<p>This is a demo stand-in for the PayMongo hosted checkout. No card is used.</p><button id="pay">Pay now (mock)</button><pre id="out"></pre>';
        $script = 'const sid=' . json_encode($sessionId) . <<<'JS'
;document.getElementById('pay').onclick=async()=>{const r=await api('POST','/v1/mock/paymongo/pay',{sessionId:sid});show('out',r.data);if(r.status===200)setTimeout(()=>history.back(),800)};
JS;
        return self::render('Mock payment', $body, $csrf, $user, $script);
    }

    public static function staff(string $csrf, ?array $user): array
    {
        $body = <<<'HTML'
<p>Each button calls the /v1 API with your role; the server decides what is allowed.</p>
<div class="row"><button data-get="/v1/arrivals">Arrivals</button><button data-get="/v1/cleaning-tasks">Cleaning tasks</button><button data-get="/v1/rooms">Rooms</button><button data-get="/v1/reports/summary">Report</button></div>
<fieldset><legend>Check in</legend><form data-post="/v1/stays/check-in" class="row"><label>Reservation ID<input name="reservationId" required></label>
<label>Document<select name="identityDocType"><option>PASSPORT</option><option>DRIVERS_LICENSE</option><option>NATIONAL_ID</option><option>OTHER</option></select></label>
<label><input type="checkbox" name="identityVerified"> identity verified</label><button>Check in</button></form></fieldset>
<fieldset><legend>Check out</legend><form data-post="/v1/stays/check-out" class="row"><label>Reservation ID<input name="reservationId" required></label>
<label><input type="checkbox" name="settleOnSite"> take balance now</label><button>Check out</button></form></fieldset>
<fieldset><legend>Room status (housekeeping)</legend><form id="rs" class="row"><label>Room ID<input name="id" required></label>
<label>Status<select name="status"><option>CLEANING</option><option>READY</option><option>DIRTY</option><option>OUT_OF_SERVICE</option></select></label>
<label>Version<input name="expectedVersion" type="number" required></label><button>Set</button></form></fieldset>
<fieldset><legend>Manager</legend><form id="gu" class="row"><label>Reservation ID<input name="id" required></label><button>Approve pay-at-hotel</button></form></fieldset>
<pre id="out"></pre>
HTML;
        $script = <<<'JS'
const flat=f=>{const o=Object.fromEntries(new FormData(f));for(const c of f.querySelectorAll('input[type=checkbox]'))o[c.name]=c.checked;return o};
document.querySelectorAll('[data-get]').forEach(b=>b.onclick=async()=>show('out',(await api('GET',b.dataset.get)).data));
document.querySelectorAll('form[data-post]').forEach(f=>f.onsubmit=async e=>{e.preventDefault();show('out',(await api('POST',f.dataset.post,flat(f),true)).data)});
document.getElementById('rs').onsubmit=async e=>{e.preventDefault();const o=flat(e.target);show('out',(await api('PATCH','/v1/rooms/'+o.id+'/status',{status:o.status,expectedVersion:+o.expectedVersion})).data)};
document.getElementById('gu').onsubmit=async e=>{e.preventDefault();show('out',(await api('POST','/v1/reservations/'+flat(e.target).id+'/approve-guarantee',{})).data)};
JS;
        return self::render('Staff', $body, $csrf, $user, $script);
    }
}
