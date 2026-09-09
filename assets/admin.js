/* global aicrConfig, wp */
(() => {
    'use strict';
    const { __ } = wp.i18n;
    const list = document.getElementById('aicr-list');
    const status = document.getElementById('aicr-status');
    const preview = document.getElementById('aicr-preview');
    const pager = document.getElementById('aicr-pager');
    const controls = document.getElementById('aicr-controls');
    let mode = 'text', page = 1, generation = 0, search = '', findingsOnly = false, busy = false;
    const selected = new Map();
    const el = (tag, text, cls) => {
        const node = document.createElement(tag);
        if (text !== undefined) node.textContent = text;
        if (cls) node.className = cls;
        if (['button', 'input', 'select'].includes(tag)) node.disabled = busy;
        return node;
    };
    async function api(path, body) {
        const parts = path.split('?');
        const requestUrl = aicrConfig.root.includes('rest_route=')
            ? aicrConfig.root + encodeURIComponent(parts[0]) + (parts[1] ? '&' + parts[1] : '')
            : aicrConfig.root + path;
        const response = await fetch(requestUrl, {
            method: body ? 'POST' : 'GET',
            headers: { 'X-WP-Nonce': aicrConfig.nonce, 'Content-Type': 'application/json' },
            credentials: 'same-origin', body: body ? JSON.stringify(body) : undefined
        });
        const data = await response.json();
        if (!response.ok) throw new Error(data.message || __('Request failed.', 'ai-content-rinse'));
        return data;
    }
    async function run(action) {
        if (busy) return;
        busy = true;
        const disabled = Array.from(document.querySelectorAll('.aicr button, .aicr input, .aicr select'));
        disabled.forEach(node => { node.disabled = true; });
        status.textContent = __('Working…', 'ai-content-rinse');
        try { await action(); status.textContent = __('Ready.', 'ai-content-rinse'); }
        catch (error) { status.textContent = error.message; }
        finally {
            busy = false;
            document.querySelectorAll('.aicr button, .aicr input, .aicr select').forEach(node => { node.disabled = false; });
        }
    }
    function button(label, action, primary = false) {
        const node = el('button', label, 'button' + (primary ? ' button-primary' : ''));
        node.type = 'button';
        node.addEventListener('click', () => run(action));
        return node;
    }
    function reveal() { preview.hidden = false; preview.focus(); }
    function confirmInPreview(message, action) {
        const panel = el('div', undefined, 'aicr-confirm');
        panel.append(el('p', message), button(__('Confirm cleanup', 'ai-content-rinse'), async () => {
            panel.remove();
            await action();
        }, true), button(__('Cancel', 'ai-content-rinse'), async () => { panel.remove(); }));
        preview.querySelector('.aicr-confirm')?.remove();
        preview.append(panel);
        panel.scrollIntoView({ block: 'nearest' });
    }
    function comparison(segments) {
        const grid = el('div', undefined, 'aicr-comparison');
        for (const side of ['before', 'after']) {
            const column = el('div');
            const pre = el('pre');
            column.append(el('h4', side === 'before' ? __('Before — changes in red', 'ai-content-rinse') : __('After — replacements in green', 'ai-content-rinse')));
            for (const segment of segments) {
                if (!segment.label) { pre.append(document.createTextNode(segment[side])); continue; }
                if (side === 'after' && !segment.after) continue;
                const visible = side === 'before'
                    ? (/[—–]/u.test(segment.before) ? segment.before : '⟦' + segment.label + '⟧')
                    : segment.after;
                const mark = el('mark', visible, side === 'before' ? 'aicr-removed' : 'aicr-added');
                mark.title = segment.label + ' · ' + (side === 'before' ? __('Removed during cleanup', 'ai-content-rinse') : __('Inserted during cleanup', 'ai-content-rinse'));
                pre.append(mark);
            }
            column.append(pre);
            grid.append(column);
        }
        return grid;
    }
    function textDetails(result, target) {
        const labels = { post_title: __('Title', 'ai-content-rinse'), post_content: __('Content', 'ai-content-rinse'), post_excerpt: __('Excerpt', 'ai-content-rinse') };
        for (const key of Object.keys(labels)) {
            const count = Object.values(result.counts[key]).reduce((a, b) => a + b, 0);
            if (count) target.append(el('h3', labels[key] + ' · ' + count), comparison(result.segments[key]));
        }
        target.append(el('p', result.changed
            ? __('Only these text changes will be saved. A text recovery record is stored first.', 'ai-content-rinse')
            : __('No configured cleanup characters found.', 'ai-content-rinse')));
    }
    function mediaDetails(result, target) {
        target.append(el('p', __('Removable metadata blocks across all image sizes:', 'ai-content-rinse') + ' ' + result.removed));
        const files = el('ul');
        for (const file of result.files) files.append(el('li', file.name + ' · ' + file.removed + (file.hints.length ? ' · ' + file.hints.join('; ') : '')));
        target.append(files, el('p', __('Cleaning replaces these existing files, including available thumbnails and the saved original. Pixel data is preserved. EXIF and colour profiles are retained.', 'ai-content-rinse')));
        target.append(el('p', __('C2PA detection covers PNG caBX blocks. JPEG comments/XMP and WebP XMP are also cleaned. Other provenance formats and pixel watermarks are outside this check.', 'ai-content-rinse')));
    }
    function mediaOutcome(saved, target) {
        target.append(el('h3', saved.ok ? __('Cleaned and verified.', 'ai-content-rinse') : __('Cleaning was not completed for every file.', 'ai-content-rinse')));
        for (const file of saved.files) target.append(el('p', file.name + ' · ' + __('Removed:', 'ai-content-rinse') + ' ' + file.removed + ' · ' + __('Remaining supported blocks:', 'ai-content-rinse') + ' ' + file.remaining));
        for (const error of saved.errors) target.append(el('p', error.name + ': ' + error.message, 'aicr-error'));
        target.append(el('p', __('Remaining supported blocks across all files:', 'ai-content-rinse') + ' ' + (saved.remaining === null ? __('Could not verify every file.', 'ai-content-rinse') : saved.remaining)));
        if (saved.provenance.detected) target.append(el('p', saved.provenance.hints.join('; ')));
        target.append(el('p', __('File names, media IDs and URLs are unchanged. Cached images may need a cache refresh.', 'ai-content-rinse')));
    }
    const hasFindings = result => mode === 'media' ? result.removed > 0 : result.changed;
    const scanItem = item => api(mode === 'media' ? 'media-clean' : 'preview', { id: item.id });
    async function inspect(item) {
        const result = await scanItem(item);
        preview.replaceChildren(el('h2', item.title || __('Untitled', 'ai-content-rinse')));
        if (mode === 'media') {
            const img = el('img'); img.src = result.url; img.alt = item.title; img.className = 'aicr-image';
            preview.append(img);
            mediaDetails(result, preview);
            if (result.removed) preview.append(button(__('Clean metadata in this file', 'ai-content-rinse'), async () => {
                confirmInPreview(__('Remove the listed metadata from these existing image files?', 'ai-content-rinse'), async () => {
                const saved = await api('media-clean', { id: item.id, save: true, token: result.token });
                preview.replaceChildren(el('h2', item.title || __('Untitled', 'ai-content-rinse')));
                mediaOutcome(saved, preview);
                preview.append(button(__('Check again', 'ai-content-rinse'), () => inspect(item)));
                await load(true);
                });
            }, true));
        } else {
            textDetails(result, preview);
            if (result.changed) preview.append(button(__('Apply reviewed changes', 'ai-content-rinse'), async () => {
                await api('apply', { token: result.token });
                preview.replaceChildren(el('h2', __('Changes saved.', 'ai-content-rinse')), el('p', __('You can restore this content from History.', 'ai-content-rinse')));
                preview.append(button(__('Check again', 'ai-content-rinse'), () => inspect(item)));
                await load(true);
            }, true));
        }
        reveal();
    }
    async function reviewSelected() {
        const items = Array.from(selected.values());
        if (!items.length) throw new Error(__('Select at least one item on this page.', 'ai-content-rinse'));
        const proposals = [];
        preview.replaceChildren(el('h2', __('Review selected items', 'ai-content-rinse')));
        for (let index = 0; index < items.length; index++) {
            const item = items[index];
            status.textContent = __('Scanning selected items:', 'ai-content-rinse') + ' ' + (index + 1) + ' / ' + items.length;
            const details = el('details'); details.open = true;
            details.append(el('summary', item.title || __('Untitled', 'ai-content-rinse')));
            try {
                const result = await scanItem(item);
                if (mode === 'media') mediaDetails(result, details); else textDetails(result, details);
                if (hasFindings(result)) proposals.push({ item, result });
            } catch (error) { details.append(el('p', error.message, 'aicr-error')); }
            preview.append(details);
        }
        if (proposals.length) preview.append(button(__('Apply the reviewed changes to these items', 'ai-content-rinse') + ' (' + proposals.length + ')', async () => {
            confirmInPreview(__('Apply the reviewed changes to the selected items?', 'ai-content-rinse'), async () => {
            preview.replaceChildren(el('h2', __('Batch results', 'ai-content-rinse')));
            let succeeded = 0;
            for (const { item, result } of proposals) {
                const section = el('div');
                section.append(el('h3', item.title || __('Untitled', 'ai-content-rinse')));
                try {
                    if (mode === 'media') {
                        const saved = await api('media-clean', { id: item.id, save: true, token: result.token });
                        mediaOutcome(saved, section);
                        if (saved.ok) succeeded++;
                    } else {
                        await api('apply', { token: result.token });
                        section.append(el('p', __('Text changes saved.', 'ai-content-rinse')));
                        succeeded++;
                    }
                } catch (error) { section.append(el('p', error.message, 'aicr-error')); }
                preview.append(section);
            }
            preview.prepend(el('p', __('Successfully completed:', 'ai-content-rinse') + ' ' + succeeded + ' / ' + proposals.length));
            selected.clear();
            list.querySelectorAll('input[type=checkbox]').forEach(node => { node.checked = false; });
            await load(true);
            });
        }, true));
        reveal();
    }
    function pasteWorkspace() {
        controls.replaceChildren(); pager.replaceChildren();
        list.replaceChildren(el('h2', __('Paste text', 'ai-content-rinse')));
        const label = el('label', __('Text to clean', 'ai-content-rinse')); label.htmlFor = 'aicr-paste';
        const input = el('textarea'); input.id = 'aicr-paste'; input.rows = 10;
        const formatLabel = el('label', __('Input format', 'ai-content-rinse')); formatLabel.htmlFor = 'aicr-format';
        const format = el('select'); format.id = 'aicr-format';
        for (const [value, title] of [['plain', __('Plain text', 'ai-content-rinse')], ['html', __('WordPress / HTML — preserve markup and code', 'ai-content-rinse')]]) {
            const option = el('option', title); option.value = value; format.append(option);
        }
        list.append(label, input, formatLabel, format, button(__('Preview cleanup', 'ai-content-rinse'), async () => {
            const result = await api('paste', { text: input.value, format: format.value });
            preview.replaceChildren(el('h2', __('Pasted text preview', 'ai-content-rinse')), comparison(result.segments));
            preview.append(el('p', __('Characters changed:', 'ai-content-rinse') + ' ' + Object.values(result.counts).reduce((a, b) => a + b, 0)));
            const outputLabel = el('label', __('Cleaned text', 'ai-content-rinse')); outputLabel.htmlFor = 'aicr-output';
            const output = el('textarea'); output.id = 'aicr-output'; output.readOnly = true; output.rows = 8; output.value = result.text;
            preview.append(outputLabel, output, button(__('Copy cleaned text', 'ai-content-rinse'), async () => {
                try { await navigator.clipboard.writeText(output.value); }
                catch { output.focus(); output.select(); throw new Error(__('Copy is unavailable. The cleaned text is selected; press Ctrl+C.', 'ai-content-rinse')); }
            }, true));
            reveal();
        }, true));
    }
    function renderControls() {
        controls.replaceChildren();
        const searchLabel = el('label', __('Search titles and content', 'ai-content-rinse')); searchLabel.htmlFor = 'aicr-search';
        const input = el('input'); input.id = 'aicr-search'; input.type = 'search'; input.value = search;
        const doSearch = async () => { search = input.value; page = 1; await load(); };
        input.addEventListener('keydown', event => { if (event.key === 'Enter') { event.preventDefault(); run(doSearch); } });
        controls.append(searchLabel, input, button(__('Search', 'ai-content-rinse'), doSearch));
        if (mode !== 'history') {
            const label = el('label');
            const checkbox = el('input'); checkbox.type = 'checkbox'; checkbox.checked = findingsOnly;
            checkbox.addEventListener('change', () => run(async () => { findingsOnly = checkbox.checked; await load(); }));
            label.append(checkbox, document.createTextNode(__('Only findings on this page', 'ai-content-rinse')));
            controls.append(label, button(__('Review selected', 'ai-content-rinse'), reviewSelected));
        }
    }
    async function load(keepPreview = false) {
        const stamp = ++generation;
        selected.clear();
        if (!keepPreview) preview.hidden = true;
        pager.replaceChildren();
        if (mode === 'paste') { pasteWorkspace(); return; }
        renderControls();
        list.replaceChildren(el('p', __('Loading…', 'ai-content-rinse')));
        const data = await api('items?mode=' + mode + '&page=' + page + '&search=' + encodeURIComponent(search));
        if (stamp !== generation) return;
        list.replaceChildren(el('h2', mode === 'media' ? __('Image metadata', 'ai-content-rinse') : mode === 'history' ? __('Text recovery copies', 'ai-content-rinse') : __('Posts & pages', 'ai-content-rinse')));
        let visible = 0;
        for (const item of data.items) {
            let finding = '', problem = '';
            if (findingsOnly && mode !== 'history') {
                try { const result = await scanItem(item); if (!hasFindings(result)) continue; finding = __('Findings detected.', 'ai-content-rinse'); }
                catch (error) { problem = error.message; }
            }
            visible++;
            const row = el('div', undefined, 'aicr-row');
            if (mode !== 'history') {
                const checkbox = el('input'); checkbox.type = 'checkbox'; checkbox.setAttribute('aria-label', __('Select', 'ai-content-rinse') + ' ' + item.title);
                checkbox.addEventListener('change', () => { if (checkbox.checked) selected.set(item.id, item); else selected.delete(item.id); });
                row.append(checkbox);
            }
            if (item.image) { const img = el('img'); img.src = item.image; img.alt = ''; row.append(img); }
            const info = el('div'); info.append(el('strong', item.title || __('Untitled', 'ai-content-rinse')), el('small', item.type + ' / ' + item.status + ' / #' + item.id));
            if (finding || problem) info.append(el('small', finding || problem, problem ? 'aicr-error' : ''));
            row.append(info);
            if (mode === 'history') row.append(button(__('Restore original text', 'ai-content-rinse'), async () => {
                if (!window.confirm(__('Restore the text saved before cleaning? Later edits will block restoration.', 'ai-content-rinse'))) return;
                await api('restore', { id: item.id }); await load();
            }));
            else row.append(button(__('Scan & preview', 'ai-content-rinse'), () => inspect(item)));
            list.append(row);
        }
        if (!visible) list.append(el('p', findingsOnly ? __('No findings on this page. You can check the next page.', 'ai-content-rinse') : __('No items found.', 'ai-content-rinse')));
        if (page > 1) pager.append(button(__('Previous', 'ai-content-rinse'), async () => { --page; await load(); }));
        pager.append(el('span', page + ' / ' + Math.max(1, data.pages)));
        if (page < data.pages) pager.append(button(__('Next', 'ai-content-rinse'), async () => { ++page; await load(); }));
    }
    document.querySelectorAll('[data-tab]').forEach(node => node.addEventListener('click', () => run(async () => {
        mode = node.dataset.tab; page = 1;
        document.querySelectorAll('[data-tab]').forEach(tab => tab.classList.toggle('button-primary', tab === node));
        await load();
    })));
    run(load);
})();
