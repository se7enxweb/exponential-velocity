<?php

/**
 * The dashboard reads at a glance.
 *
 * The owner asked for a handful of layout details that are easy to undo by
 * accident when the page is edited: the footer spells out "Documentation" and
 * capitalises "Powered", the status-code and worker-memory cards list their
 * figures one per line instead of a dotted run-on, and the header reads
 * "OS · PHP x · Live · Up 15s", capitalised, uptime last. The page is one heredoc of HTML
 * and JS, so this asserts against the source.
 *
 * The cards are filled live over the WebSocket by U(), so the structure has to
 * hold in both places: the markup served first and the string U() writes.
 *
 *   php tests/unit-dashboard-layout.php
 */

$pass = 0;
$fail = 0;

function check($what, $got, $want)
{
	global $pass, $fail;
	if ($got === $want) { ++$pass; return; }
	++$fail;
	printf("  FAIL  %s\n        got  %s\n        want %s\n", $what,
		var_export($got, true), var_export($want, true));
}

// The dashboard's PHP and its page, which is a design on disk now
// (designs/default/dashboard: page.html, style.css, script.js).
$dash = file_get_contents(__DIR__ . '/../src/Q/WebServer/Dashboard.php');
foreach (array('page.html', 'style.css', 'script.js') as $__f) $dash .= "\n" . file_get_contents(__DIR__ . '/../designs/default/dashboard/' . $__f);

// ── Footer wording ──────────────────────────────────────────────

check('the footer links "Documentation"',
	strpos($dash, '<a href="/Q/docs">Documentation</a>') !== false, true);
check('...not the old "docs"',
	strpos($dash, '<a href="/Q/docs">docs</a>'), false);
check('"Powered" is capitalised',
	strpos($dash, '&#183; Powered by the Qbix engine') !== false, true);
check('...and the lower-case form is gone',
	strpos($dash, 'powered by'), false);

// ── Footer structure: Documentation on its own centred line ─────
// Brand and version stay on the first line; the maintainer line follows;
// "Documentation · Powered by ..." sits on its own line below both.

check('the brand line carries only the brand and version',
	strpos($dash, '<div class="foot">{{brandName}} <span style="opacity:.6">{{verLabel}}</span></div>') !== false, true);
check('Documentation opens its own footer line',
	strpos($dash, '<div class="foot-docs"><a href="/Q/docs">Documentation</a> &#183; Powered by the Qbix engine</div>') !== false, true);
check('...which comes after the brand and maintainer lines',
	strpos($dash, '{{verLabel}}</span></div>') < strpos($dash, "{{maintainedBy}}\n<div class=\"foot-docs\">")
	and strpos($dash, "{{maintainedBy}}\n<div class=\"foot-docs\">") !== false, true);
check('...and is centred',
	(bool) preg_match('/\.foot-docs\{[^}]*text-align:center/', $dash), true);
check('...and wraps rather than scrolling at phone width',
	(bool) preg_match('/\.foot-docs\{[^}]*overflow-wrap:anywhere/', $dash), true);
check('the Documentation link is not on the brand line any more',
	strpos($dash, '$verLabel</span> &#183; <a href="/Q/docs">'), false);

// ── Status codes: one entry per line ────────────────────────────

foreach (array('s2' => 'ok', 's3' => 'redir', 's4' => '4xx', 's5' => '5xx') as $id => $label) {
	check("status $label is its own row",
		strpos($dash, "<div><span>$label</span><b class=\"$id\" id=\"$id\">0</b></div>") !== false, true);
	check("...and U() still fills #$id",
		(bool) preg_match("/el\('$id',s\.status\dxx\)/", $dash), true);
}
check('the status card is a vertical list',
	strpos($dash, '<div class="l">Status codes</div><div class="vl">') !== false, true);
check('...not the dotted single line',
	strpos($dash, '</span> ok &#183;'), false);
check('the list style exists',
	(bool) preg_match('/\.vl div\{display:flex/', $dash), true);

// ── Worker memory: one item per line ────────────────────────────

check('the COW detail is a vertical list',
	strpos($dash, 'class="s vl" id="cow-detail"') !== false, true);
check('...and U() writes one <div> per item',
	strpos($dash, "el('cow-detail','<div>'+ws.idle+'/'+ws.count+' idle</div><div>'") !== false
	and strpos($dash, "/worker</div><div>fpm would use ~'+fpmEquiv+'MB</div>')") !== false, true);
check('...not the dotted single line',
	strpos($dash, "' idle \\u00B7 '"), false);

// ── Header: OS · PHP · live status · Up ─────────────────────────

check('the header starts with the OS, then PHP, then the status badge',
	strpos($dash, "el('sub',(S.os||'')+' \\u00B7 PHP '+(S.php||'')+' \\u00B7 '+wsBadge()+' \\u00B7 Up '+fmtUp(upSec))") !== false, true);
check('...the status words are capitalised, one per state',
	strpos($dash, "WSL={live:'Live',connecting:'Connecting',reconnecting:'Reconnecting',signedout:'Signed out',offline:'Offline'}") !== false, true);
check('the lowercase status is gone',
	strpos($dash, "(wsLive?'live':'connecting')"), false);
check('the lowercase uptime label is gone',
	strpos($dash, "\\u00B7 up '+fmtUp") === false && strpos($dash, "'up '+fmtUp") === false, true);
check('the older orders are gone',
	strpos($dash, "el('sub','PHP '") === false && strpos($dash, "el('sub','up '") === false, true);
check('the status dot is green when live, amber while connecting, red otherwise',
	strpos($dash, "var c=wsState==='live'?' on':(wsState==='connecting'||wsState==='reconnecting'?' wait':'')") !== false
	&& strpos($dash, '.wd.on{background:var(--grn)}.wd.wait{background:var(--yel)}') !== false, true);
check('...and the state is on the badge for scripts and tests (data-state)',
	strpos($dash, 'data-state="\'+wsState+\'"') !== false, true);
check('signed out offers a sign-in link',
	strpos($dash, "(wsState==='signedout'?' <a id=\"wsin\" href=\"'+wsSignIn+'\">Sign in</a>':'')") !== false, true);

// ── Still usable at phone width ─────────────────────────────────

check('the narrow-screen breakpoint is kept',
	strpos($dash, '@media(max-width:700px)') !== false, true);

// ── Workers card ─────────────────────────────────────────────────
// "590/590 · reqs: 5 PHP / 7 static" was read as "only 5 PHP workers": the
// value was idle/total with nothing saying so, and the counts under it were
// requests served. Now: the total, then labelled rows.
check('the workers card lists idle workers on a labelled row',
	strpos($dash, '<span>idle</span><b id="swi">') !== false, true);
check('...busy workers on another',
	strpos($dash, '<span>busy</span><b id="swb">') !== false, true);
check('...and says the PHP and static counts are requests served',
	strpos($dash, '<span>PHP requests served</span><b id="phpn">') !== false
	and strpos($dash, '<span>static files served</span><b id="stn">') !== false, true);
check('the ambiguous "reqs: N PHP / N static" line is gone',
	strpos($dash, "'reqs: '"), false);
check('the value is split into idle and busy rather than shown as "idle/total"',
	(bool) preg_match("/split\\('\\/'\\)/", $dash), true);

if ($fail) {
	printf("\n  FAIL - %d of %d case(s)\n", $fail, $pass + $fail);
	exit(1);
}
printf("  PASS - %d case(s)\n", $pass);
