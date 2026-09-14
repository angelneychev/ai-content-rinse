/* global wp, aicrConfig */
(() => {
    'use strict';
    const { __ } = wp.i18n;
    const { createElement: h, useState, useEffect, useRef } = wp.element;
    const { Button, CheckboxControl, Modal, Notice } = wp.components;
    const Panel = wp.editor.PluginDocumentSettingPanel || wp.editPost.PluginDocumentSettingPanel;
    const { api, comparison, el, ruleLabels } = window.aicrUI;
    const fieldLabels = {
        post_title: __('Title', 'ai-content-rinse'),
        post_content: __('Content', 'ai-content-rinse'),
        post_excerpt: __('Excerpt', 'ai-content-rinse')
    };
    function snapshot() {
        const editor = wp.data.select('core/editor');
        return {
            id: editor.getCurrentPostId(),
            fields: {
                post_title: editor.getEditedPostAttribute('title') || '',
                post_content: editor.getEditedPostContent() || '',
                post_excerpt: editor.getEditedPostAttribute('excerpt') || ''
            }
        };
    }
    const same = (a, b) => a.id === b.id && Object.keys(fieldLabels).every(key => a.fields[key] === b.fields[key]);
    function ensureEditable() {
        const editor = wp.data.select('core/editor');
        if (editor.isSavingPost() || editor.isPostLocked() || editor.getEditorMode() !== 'visual') {
            throw new Error(__('Use the visual block editor and wait for any save or editing lock to finish.', 'ai-content-rinse'));
        }
    }
    function checkedBlocks(content) {
        const blocks = wp.blocks.parse(content);
        const valid = nodes => nodes.every(block => block.isValid !== false
            && !block.attributes?.metadata?.bindings && valid(block.innerBlocks || []));
        if (!valid(blocks) || wp.blocks.serialize(blocks) !== content) {
            throw new Error(__('This content cannot be cleaned in the editor without changing its block structure. No changes were applied.', 'ai-content-rinse'));
        }
        return blocks;
    }
    function Preview({ result }) {
        const ref = useRef(null);
        useEffect(() => {
            const target = ref.current;
            target.replaceChildren();
            for (const [key, label] of Object.entries(fieldLabels)) {
                const count = Object.values(result.counts[key]).reduce((sum, n) => sum + n, 0);
                if (count) target.append(el('h3', label + ': ' + count), comparison(result.segments[key]));
            }
            if (!result.changed) target.append(el('p', __('No configured cleanup characters found.', 'ai-content-rinse')));
        }, [result]);
        return h('div', { ref, className: 'aicr' });
    }
    function RinsePanel() {
        const [rules, setRules] = useState({ ...aicrConfig.rules });
        const [busy, setBusy] = useState(false);
        const [message, setMessage] = useState('');
        const [failed, setFailed] = useState(false);
        const [proposal, setProposal] = useState(null);
        const [stale, setStale] = useState(false);
        const working = useRef(false);
        useEffect(() => {
            if (!proposal) return undefined;
            return wp.data.subscribe(() => {
                if (!same(snapshot(), proposal.source)) setStale(true);
            });
        }, [proposal]);
        async function run(action) {
            if (working.current) return;
            working.current = true; setBusy(true); setMessage(''); setFailed(false);
            try { await action(); }
            catch (error) { setMessage(error.message); setFailed(true); }
            finally { working.current = false; setBusy(false); }
        }
        async function scan() {
            setProposal(null); setStale(false); ensureEditable();
            const source = snapshot();
            checkedBlocks(source.fields.post_content);
            const result = await api('editor-preview', { ...source, rules });
            if (!same(snapshot(), source)) throw new Error(__('The text changed during the scan. Preview it again.', 'ai-content-rinse'));
            checkedBlocks(result.after.post_content);
            setProposal({ source, result });
        }
        function apply() {
            ensureEditable();
            if (stale || !same(snapshot(), proposal.source)) {
                throw new Error(__('The text changed after the preview. Preview it again.', 'ai-content-rinse'));
            }
            const { after } = proposal.result;
            checkedBlocks(after.post_content);
            // One entity edit keeps title, content and excerpt in the same undo step.
            wp.data.dispatch('core/editor').editPost({
                title: after.post_title, content: after.post_content, excerpt: after.post_excerpt
            });
            setProposal(null);
            setMessage(__('Changes applied in the editor. Use WordPress Undo to revert, or Save to keep them. Normal editor autosave still applies.', 'ai-content-rinse'));
        }
        const notice = message ? h(Notice, { status: failed ? 'error' : 'success', isDismissible: false }, message) : null;
        return h(Panel, { name: 'ai-content-rinse', title: 'AI Content Rinse', className: 'aicr-editor-panel' },
            h('p', null, __('Review the current title, content and excerpt, including unsaved edits.', 'ai-content-rinse')),
            ...Object.entries(ruleLabels).map(([key, label]) => h(CheckboxControl, {
                key, label, checked: rules[key], disabled: busy,
                onChange: value => run(async () => {
                    const saved = await api('settings', { rules: { ...rules, [key]: value } });
                    setRules(saved); setProposal(null);
                })
            })),
            h(Button, { variant: 'primary', disabled: busy, isBusy: busy, onClick: () => run(scan) }, __('Preview cleanup', 'ai-content-rinse')),
            !proposal && notice,
            proposal && h(Modal, {
                title: __('Review text cleanup', 'ai-content-rinse'),
                className: 'aicr-editor-modal', onRequestClose: () => { if (!busy) setProposal(null); }
            },
            h(Preview, { result: proposal.result }),
            stale && h(Notice, { status: 'warning', isDismissible: false }, __('The text changed after the preview. Close this window and scan again.', 'ai-content-rinse')),
            notice,
            h('p', null, __('Apply returns the reviewed changes to the editor. This action does not publish the post or create a History recovery record.', 'ai-content-rinse')),
            h(Button, { variant: 'primary', disabled: busy || stale || !proposal.result.changed, onClick: () => run(apply) }, __('Apply to editor', 'ai-content-rinse')),
            h(Button, { variant: 'secondary', disabled: busy, onClick: () => setProposal(null) }, __('Cancel', 'ai-content-rinse')))
        );
    }
    wp.plugins.registerPlugin('ai-content-rinse', { render: RinsePanel, icon: 'editor-removeformatting' });
})();
