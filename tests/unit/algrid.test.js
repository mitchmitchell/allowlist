// A3 (SEC-05): grid action links escape stored values. Run: node tests/unit/algrid.test.js
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');

const src = fs.readFileSync(path.join(__dirname, '../../views/algrid.php'), 'utf8');
const script = src.match(/<script type="text\/javascript">([\s\S]*?)<\/script>/)[1];
const ctx = {};
vm.createContext(ctx);
vm.runInContext(script, ctx);

let n = 0;
function t(name, fn) { fn(); n++; console.log('ok   ' + name); }

const payloads = [
	'"><img src=x onerror=alert(1)>',
	"' onmouseover='alert(1)",
	'<script>alert(1)</script>',
	'1&quot; onfocus=&quot;x',
];
for (const p of payloads) {
	t('number payload escaped: ' + p, () => {
		const html = ctx.linkFormatter(null, { number: p, description: 'd' }, 3);
		assert(!html.includes('<img') && !html.includes('<script'), 'raw tag in output');
		assert(!/"\s*on\w+=/.test(html) && !/'\s*on\w+=/.test(html), 'attribute break-out');
		// every attribute value is a single quoted token: count of " is even and no raw < inside values
		const attrs = html.match(/data-number="([^"]*)"/g);
		assert.strictEqual(attrs.length, 4);
		for (const a of attrs) assert(!a.slice(13, -1).includes('<'), 'raw < in attribute');
	});
	t('description payload escaped: ' + p, () => {
		const html = ctx.linkFormatter(null, { number: '+12025550114', description: p }, 0);
		assert(!html.includes('<img') && !html.includes('<script'));
		assert(!/"\s*on\w+=/.test(html));
	});
}
t('ids carry only the row index', () => {
	const html = ctx.linkFormatter(null, { number: '+12025550114', description: 'x' }, 7);
	const ids = (html.match(/id="([^"]*)"/g) || []).map((s) => s.slice(4, -1));
	assert.deepStrictEqual(ids, ['del7', 'block7', 'report7']);
	assert(html.includes('data-number="+12025550114"'));
});
t('stored entity-encoded description round-trips once', () => {
	const html = ctx.linkFormatter(null, { number: '911', description: 'Tom &amp; Jerry &quot;x&quot;' }, 0);
	assert(html.includes('data-description="Tom &amp; Jerry &quot;x&quot;"'));
});
t('non-numeric idx is neutralised', () => {
	const html = ctx.linkFormatter(null, { number: '911', description: '' }, '"><b>');
	assert(html.includes('id="del0"') && !html.includes('<b>'));
});
console.log(`\n${n} checks, 0 failed`);
