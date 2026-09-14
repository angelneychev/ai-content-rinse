/* global aicrConfig, wp */
(() => {
    'use strict';
    const { __ } = wp.i18n;
    const el = (tag, text, cls) => {
        const node = document.createElement(tag);
        if (text !== undefined) node.textContent = text;
        if (cls) node.className = cls;
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
    function comparison(segments) {
        const grid = el('div', undefined, 'aicr-comparison');
        for (const side of ['before', 'after']) {
            const column = el('div');
            const pre = el('pre');
            column.append(el('h4', side === 'before' ? __('Before - changes in red', 'ai-content-rinse') : __('After - replacements in green', 'ai-content-rinse')));
            for (const segment of segments) {
                if (!segment.label) { pre.append(document.createTextNode(segment[side])); continue; }
                if (side === 'after' && !segment.after) continue;
                const visible = side === 'before'
                    ? (/[—–]/u.test(segment.before) ? segment.before : '⟦' + segment.label + '⟧')
                    : segment.after;
                const mark = el('mark', visible, side === 'before' ? 'aicr-removed' : 'aicr-added');
                mark.title = segment.label + ' - ' + (side === 'before' ? __('Removed during cleanup', 'ai-content-rinse') : __('Inserted during cleanup', 'ai-content-rinse'));
                pre.append(mark);
            }
            column.append(pre);
            grid.append(column);
        }
        return grid;
    }

    const ruleLabels = {
        invisible: __('Remove invisible characters', 'ai-content-rinse'),
        dashes: __('Replace long dashes with standard hyphens', 'ai-content-rinse')
    };
    window.aicrUI = { api, comparison, el, ruleLabels };
})();
