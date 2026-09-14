const { test } = require('node:test');
const assert = require('node:assert/strict');
const fs = require('node:fs');
const vm = require('node:vm');
const source = fs.readFileSync(require('node:path').join(__dirname, '../assets/editor.js'), 'utf8');

function setup() {
    let registered, cursor = 0;
    const slots = [], subscribers = [], edits = [];
    const current = { id: 7, title: 'Before', content: '<p>before</p>', excerpt: 'Before excerpt', mode: 'visual', saving: false, locked: false };
    const valid = { isValid: true, attributes: {}, innerBlocks: [] };
    let parse = content => [{ ...valid, content }];
    let respond = body => ({ before: body.fields, after: { post_title: 'After', post_content: '<p>after</p>', post_excerpt: 'After excerpt' }, changed: true, counts: {}, segments: {} });
    const context = {
        aicrConfig: { rules: { invisible: true, dashes: true } },
        window: { aicrUI: { api: async (path, body) => respond(body), ruleLabels: { invisible: 'Invisible', dashes: 'Dashes' } } },
        wp: {
            i18n: { __: text => text },
            element: {
                createElement: (type, props, ...children) => ({ type, props: props || {}, children }),
                useState: initial => { const i = cursor++; if (!(i in slots)) slots[i] = initial; return [slots[i], value => { slots[i] = value; }]; },
                useRef: initial => { const i = cursor++; if (!(i in slots)) slots[i] = { current: initial }; return slots[i]; },
                useEffect: () => {}
            },
            components: { Button: 'Button', CheckboxControl: 'Checkbox', Modal: 'Modal', Notice: 'Notice' },
            editor: { PluginDocumentSettingPanel: 'Panel' },
            blocks: { parse: content => parse(content), serialize: blocks => blocks.map(b => b.content).join('') },
            plugins: { registerPlugin: (name, options) => { registered = options.render; } },
            data: {
                subscribe: fn => { subscribers.push(fn); return () => {}; },
                select: () => ({
                    getCurrentPostId: () => current.id,
                    getEditedPostAttribute: key => current[key], getEditedPostContent: () => current.content,
                    isSavingPost: () => current.saving, isPostLocked: () => current.locked, getEditorMode: () => current.mode
                }),
                dispatch: () => ({ editPost: changes => { edits.push(changes); Object.assign(current, changes); } })
            }
        }
    };
    vm.runInNewContext(source, context);
    const render = () => { cursor = 0; return registered(); };
    function find(node, type, label) {
        if (!node || typeof node !== 'object') return undefined;
        if (node.type === type && (!label || node.children.includes(label))) return node;
        for (const child of node.children || []) { const found = find(child, type, label); if (found) return found; }
    }
    const click = async label => {
        const node = find(render(), 'Button', label);
        assert.ok(node, 'Button exists: ' + label);
        assert.ok(!node.props.disabled, 'Button enabled: ' + label);
        await node.props.onClick();
    };
    return { current, edits, click, render, find, setParse: fn => { parse = fn; }, setRespond: fn => { respond = fn; } };
}

test('preview sends current unsaved fields and apply edits all fields together without publishing', async () => {
    const app = setup();
    app.current.title = 'Unsaved title';
    let received;
    app.setRespond(body => {
        received = body;
        return { before: body.fields, after: { post_title:'Clean title',post_content:'<p>clean</p>',post_excerpt:'Clean excerpt' }, changed:true };
    });
    await app.click('Preview cleanup');
    assert.equal(received.fields.post_title, 'Unsaved title');
    assert.equal(app.edits.length, 0);
    await app.click('Apply to editor');
    assert.equal(app.edits.length, 1);
    assert.deepEqual(Object.keys(app.edits[0]).sort(), ['content','excerpt','title']);
});

test('editing after preview blocks applying the stale response', async () => {
    const app = setup();
    await app.click('Preview cleanup');
    app.current.content = '<p>New user edit</p>';
    await app.click('Apply to editor');
    assert.equal(app.edits.length, 0);
    assert.match(app.find(app.render(), 'Notice').children[0], /changed after the preview/);
});

test('editing while server response is pending discards that response', async () => {
    const app = setup();
    let finish;
    app.setRespond(body => new Promise(resolve => { finish = () => resolve({ after:body.fields, changed:false }); }));
    const pending = app.click('Preview cleanup');
    app.current.title = 'New user edit';
    finish(); await pending;
    assert.equal(app.find(app.render(), 'Modal'), undefined);
    assert.match(app.find(app.render(), 'Notice').children[0], /changed during the scan/);
});

test('a changed post ID invalidates the preview', async () => {
    const app = setup(); await app.click('Preview cleanup'); app.current.id = 8;
    await app.click('Apply to editor'); assert.equal(app.edits.length, 0);
});

for (const [label, overrides] of [['code editor',{mode:'text'}], ['active save',{saving:true}], ['post lock',{locked:true}]]) {
    test('rejects ' + label, async () => {
        const app = setup(); Object.assign(app.current, overrides); await app.click('Preview cleanup');
        assert.equal(app.find(app.render(),'Modal'), undefined);
        assert.match(app.find(app.render(),'Notice').children[0], /visual block editor/);
    });
}

for (const [label, make] of [
    ['invalid blocks', content => [{content,isValid:false,attributes:{}}]],
    ['bound blocks', content => [{content,isValid:true,attributes:{metadata:{bindings:{content:{source:'x'}}}}}]],
    ['parser normalization', () => [{content:'<p>Different markup</p>',isValid:true,attributes:{}}]]
]) {
    test('rejects ' + label + ' without editing', async () => {
        const app = setup(); app.setParse(make); await app.click('Preview cleanup');
        assert.equal(app.edits.length,0); assert.equal(app.find(app.render(),'Modal'),undefined);
        assert.match(app.find(app.render(),'Notice').children[0], /block structure/);
    });
}

test('failed request reports error and enables another preview', async () => {
    const app = setup(); app.setRespond(() => { throw new Error('Session expired'); });
    await app.click('Preview cleanup');
    assert.equal(app.find(app.render(),'Notice').children[0],'Session expired');
    assert.equal(app.find(app.render(),'Button','Preview cleanup').props.disabled,false);
});

test('no findings keeps Apply disabled', async () => {
    const app = setup(); app.setRespond(body => ({ after:body.fields,changed:false }));
    await app.click('Preview cleanup');
    assert.equal(app.find(app.render(),'Button','Apply to editor').props.disabled,true);
});
