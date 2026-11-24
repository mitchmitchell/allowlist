// Runs assets/js/allowlist.js against a minimal jQuery stand-in and checks what it
// posts and renders. Run: node tests/unit/allowlist_js.test.js
'use strict';
const fs = require('fs');
const path = require('path');
const vm = require('vm');
const assert = require('assert');

const root = path.join(__dirname, '../..');
const pageJs = fs.readFileSync(path.join(root, 'assets/js/allowlist.js'), 'utf8');
// views/algrid.php defines alEscapeHtml on the same page; allowlist.js relies on it.
const gridScript = fs.readFileSync(path.join(root, 'views/algrid.php'), 'utf8')
	.match(/<script type="text\/javascript">([\s\S]*?)<\/script>/)[1];

// jQuery's .data() converts "1e3" to 1000, "true" to true, JSON to objects; .attr() never does.
function jqDataCoerce(v) {
	if (v === undefined || v === null) return v;
	if (v === 'true') return true;
	if (v === 'false') return false;
	if (v === 'null') return null;
	if (String(+v) === v || (/^[+-]?\d+(\.\d+)?(e[+-]?\d+)?$/i.test(v) && !isNaN(+v))) return +v;
	if (/^(?:\{[\w\W]*\}|\[[\w\W]*\])$/.test(v)) { try { return JSON.parse(v); } catch (e) { return v; } }
	return v;
}

function makePage() {
	const elements = {};
	const delegated = [];
	const posts = [];
	const toasts = [];
	const confirms = [];
	const prompts = [];
	const tables = [];
	let reloads = 0;
	let promptAnswer = null;

	function el(key, attrs) {
		if (!elements[key]) {
			elements[key] = { key, attrs: Object.assign({}, attrs || {}), value: '', handlers: {}, classes: new Set(), items: [] };
		}
		return elements[key];
	}
	function wrap(e) {
		const w = {
			on(evt, a, b) {
				if (typeof a === 'function') { (e.handlers[evt] = e.handlers[evt] || []).push(a); }
				else if (e.key === 'document') { delegated.push({ evt, sel: a, fn: b }); }
				return w;
			},
			val(v) { if (v === undefined) return e.value; e.value = v === undefined || v === null ? '' : String(v); return w; },
			attr(n) { return e.attrs[n]; },
			data(n) { return jqDataCoerce(e.attrs['data-' + n]); },
			text() { return w; }, prop() { return w; }, blur() { return w; },
			addClass(c) { e.classes.add(c); return w; }, removeClass(c) { e.classes.delete(c); return w; },
			after() { return w; }, modal() { return w; }, is() { return false; }, change() { return w; },
			each(fn) { e.items.forEach((item, i) => fn.call(item, i, item)); return w; },
			bootstrapTable(cmd, opts) { tables.push({ table: e.key, cmd, opts }); return w; },
		};
		return w;
	}
	function $(x) {
		if (x && typeof x === 'object' && x.key) return wrap(x);
		if (x === documentObj) return wrap(el('document'));
		return wrap(el(String(x).trim()));
	}
	const documentObj = {};
	$.post = function (url, data, cb) {
		const req = { url, data, done: [], fail: [] };
		posts.push(req);
		const p = { done(fn) { req.done.push(fn); return p; }, fail(fn) { req.fail.push(fn); return p; } };
		if (cb) req.done.push(cb);
		return p;
	};
	const ctx = {
		$, jQuery: $, document: documentObj,
		window: { FreePBX: { ajaxurl: 'ajax.php' }, location: { reload() { reloads++; } } },
		fpbxToast(msg, title, level) { toasts.push({ msg, level }); },
		fpbxConfirm(msg, yes, no, ok) { confirms.push(msg); ok(); },
		prompt(msg, def) { prompts.push({ msg, def }); return promptAnswer; },
		alert() {}, warnInvalid() {},
		_(s) { return s; },
		sprintf(fmt, ...args) { let i = 0; return fmt.replace(/%s/g, () => String(args[i++])); },
		encodeURIComponent,
	};
	vm.createContext(ctx);
	vm.runInContext(gridScript, ctx);
	vm.runInContext(pageJs, ctx);

	return {
		ctx, el, posts, toasts, confirms, prompts, tables,
		get reloads() { return reloads; },
		setPrompt(v) { promptAnswer = v; },
		fire(key, evt, arg) { (el(key).handlers[evt] || []).forEach((fn) => fn.call(el(key), arg || {})); },
		clickDelegated(sel, target) {
			const hit = delegated.filter((d) => d.evt === 'click' && d.sel === sel);
			assert(hit.length > 0, 'no delegated click handler for ' + sel);
			hit.forEach((d) => d.fn.call(target));
		},
		lastPost() { return posts[posts.length - 1]; },
		respond(req, data) { req.done.forEach((fn) => fn(data)); },
		failReq(req) { req.fail.forEach((fn) => fn()); },
	};
}

let n = 0;
function t(name, fn) { fn(); n++; console.log('ok   ' + name); }

const hostile = '<img src=x onerror=alert(1)> & "q"';

t('picker change reloads the grid for the chosen list', () => {
	const p = makePage();
	p.el('#allowlist_list').value = '3';
	p.fire('#allowlist_list', 'change');
	const r = p.tables.filter((x) => x.table === '#blGrid' && x.cmd === 'refresh').pop();
	assert(r && r.opts.url.endsWith('jdata=grid&listId=3'), JSON.stringify(r));
});

t('row delete posts the picker list id', () => {
	const p = makePage();
	p.el('#allowlist_list').value = '4';
	p.clickDelegated('[id^="del"]', p.el('del0', { 'data-number': '2025550100' }));
	const req = p.lastPost();
	assert.strictEqual(req.data.command, 'del');
	assert.strictEqual(String(req.data.number), '2025550100');
	assert.strictEqual(req.data.listId, '4');
});

t('row delete with no picker on the page falls back to Default (1)', () => {
	const p = makePage();
	p.clickDelegated('[id^="del"]', p.el('del0', { 'data-number': '911' }));
	assert.strictEqual(p.lastPost().data.listId, '1');
});

t('row block posts the picker list id', () => {
	const p = makePage();
	p.el('#allowlist_list').value = '4';
	p.clickDelegated('[id^="block"]', p.el('block0', { 'data-number': '911', 'data-description': 'x' }));
	assert.strictEqual(p.lastPost().data.command, 'block');
	assert.strictEqual(p.lastPost().data.listId, '4');
});

t('bulk delete posts the picker list id', () => {
	const p = makePage();
	p.el('#allowlist_list').value = '5';
	p.ctx.cbrows[0] = '2025550100';
	p.ctx.cbrows[1] = '2025550101';
	p.el('input[name="btSelectItem"]:checked').items = [p.el('cb0', { 'data-index': '0' }), p.el('cb1', { 'data-index': '1' })];
	p.fire('#blkDelete', 'click', { preventDefault() {} });
	const req = p.posts.find((x) => x.data.command === 'bulkdelete');
	assert(req, 'bulkdelete was posted');
	assert.strictEqual(req.data.listId, '5');
	assert.deepStrictEqual(JSON.parse(req.data.numbers), ['2025550100', '2025550101']);
});

t('add posts the dialog list and no oldlistId', () => {
	const p = makePage();
	p.el('#allowlist_list').value = '2';
	p.fire('#addNumber', 'show.bs.modal', { relatedTarget: p.el('addbtn') });
	assert.strictEqual(p.el('#oldlistid').value, '');
	assert.strictEqual(p.el('#numberlist').value, '2');
	p.el('#number').value = '2025550101';
	p.fire('#submitnumber', 'click');
	const d = p.lastPost().data;
	assert.strictEqual(d.listId, '2');
	assert(!('oldlistId' in d), 'add must not send oldlistId');
});

t('edit remembers the source list and posts the dialog target list', () => {
	const p = makePage();
	p.el('#allowlist_list').value = '3';
	p.fire('#addNumber', 'show.bs.modal', { relatedTarget: p.el('edit0', { 'data-number': '2025550102', 'data-description': 'd' }) });
	assert.strictEqual(p.el('#oldlistid').value, '3');
	p.el('#numberlist').value = '1';
	p.el('#allowlist_list').value = '9';
	p.fire('#submitnumber', 'click');
	const d = p.lastPost().data;
	assert.strictEqual(d.oldval, '2025550102');
	assert.strictEqual(d.oldlistId, '3', 'source list comes from the dialog, not the picker');
	assert.strictEqual(d.listId, '1');
});

t('list create posts the name with the CSRF token and reloads on success', () => {
	const p = makePage();
	p.el('#allowlist_csrf').value = 'tok';
	p.el('#newlistname').value = hostile;
	p.fire('#listcreate', 'click');
	const req = p.lastPost();
	assert.deepStrictEqual([req.data.command, req.data.name, req.data.token], ['listcreate', hostile, 'tok']);
	p.respond(req, { status: true });
	assert.strictEqual(p.reloads, 1);
});

t('list error toast escapes the server message', () => {
	const p = makePage();
	p.el('#newlistname').value = 'x';
	p.fire('#listcreate', 'click');
	p.respond(p.lastPost(), { status: false, message: 'still assigned (inbound <b onmouseover=x>5550100</b>)' });
	const msg = p.toasts.pop().msg;
	assert(!msg.includes('<b'), msg);
	assert(msg.includes('&lt;b onmouseover=x&gt;5550100&lt;/b&gt;'), msg);
});

t('list request failure shows a plain error toast', () => {
	const p = makePage();
	p.el('#newlistname').value = 'x';
	p.fire('#listcreate', 'click');
	p.failReq(p.lastPost());
	const toast = p.toasts.pop();
	assert.strictEqual(toast.level, 'error');
	assert.strictEqual(p.reloads, 0);
});

t('rename prefills the stored name as a string, never type-coerced', () => {
	const p = makePage();
	p.setPrompt('New');
	p.clickDelegated('[data-list-action="rename"]', p.el('ren', { 'data-list-id': '7', 'data-list-name': '1e3' }));
	assert.strictEqual(p.prompts.pop().def, '1e3');
	const d = p.lastPost().data;
	assert.deepStrictEqual([d.command, d.listId, d.name], ['listrename', '7', 'New']);
});

t('rename cancelled posts nothing', () => {
	const p = makePage();
	p.setPrompt(null);
	p.clickDelegated('[data-list-action="rename"]', p.el('ren', { 'data-list-id': '7', 'data-list-name': 'Kids' }));
	assert.strictEqual(p.posts.length, 0);
});

t('delete confirm escapes the list name and posts the list id', () => {
	const p = makePage();
	p.clickDelegated('[data-list-action="delete"]', p.el('del-list', { 'data-list-id': '8', 'data-list-name': hostile }));
	const msg = p.confirms.pop();
	assert(!msg.includes('<img'), msg);
	assert(msg.includes('&lt;img src=x onerror=alert(1)&gt; &amp; &quot;q&quot;'), msg);
	const d = p.lastPost().data;
	assert.deepStrictEqual([d.command, d.listId], ['listdelete', '8']);
});

console.log(`\n${n} checks, 0 failed`);
