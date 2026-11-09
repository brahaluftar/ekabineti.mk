'use strict';

/* e-Kabineti UI behaviour (CSP-safe: no inline handlers). */

// 1. Responsive tables: copy column headers into data-label for the stacked-card layout on phones.
document.querySelectorAll('.table-wrap table').forEach((table) => {
    const labels = Array.from(table.querySelectorAll('thead th')).map((header) => header.textContent.trim());
    table.querySelectorAll('tbody tr').forEach((row) => {
        Array.from(row.children).forEach((cell, index) => {
            if (cell.tagName === 'TD' && !cell.hasAttribute('colspan') && labels[index]) cell.dataset.label = labels[index];
        });
    });
});

// 2. Navigation drawer (tablet/phone): menu button, "Më shumë", backdrop, Escape.
(() => {
    const body = document.body;
    const sidebar = document.getElementById('app-sidebar');
    const backdrop = document.querySelector('.drawer-backdrop');
    const toggles = document.querySelectorAll('[data-drawer-toggle]');
    if (!sidebar) return;
    let lastFocus = null;
    const setOpen = (open) => {
        body.classList.toggle('drawer-open', open);
        if (backdrop) backdrop.hidden = !open;
        toggles.forEach((toggle) => toggle.setAttribute('aria-expanded', String(open)));
        if (open) {
            lastFocus = document.activeElement;
            const first = sidebar.querySelector('a, button');
            if (first) first.focus();
        } else if (lastFocus instanceof HTMLElement) {
            lastFocus.focus();
        }
    };
    toggles.forEach((toggle) => toggle.addEventListener('click', () => setOpen(!body.classList.contains('drawer-open'))));
    document.querySelectorAll('[data-drawer-close]').forEach((element) => element.addEventListener('click', () => setOpen(false)));
    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape' && body.classList.contains('drawer-open')) setOpen(false);
    });
    window.matchMedia('(min-width: 1200px)').addEventListener('change', (query) => { if (query.matches) setOpen(false); });
})();

// 3. User menu: close on outside click and Escape.
document.querySelectorAll('details.user-menu').forEach((menu) => {
    document.addEventListener('click', (event) => { if (menu.open && !menu.contains(event.target)) menu.open = false; });
    menu.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') { menu.open = false; menu.querySelector('summary').focus(); }
    });
});

// 4. Generic collapse toggles (data-collapse-target).
document.querySelectorAll('[data-collapse-target]').forEach((toggle) => {
    const target = document.getElementById(toggle.dataset.collapseTarget);
    if (!target) return;
    toggle.addEventListener('click', () => {
        const open = !target.classList.contains('is-open');
        target.classList.toggle('is-open', open);
        toggle.setAttribute('aria-expanded', String(open));
    });
});

// 5. Confirmation for destructive or irreversible actions: <form data-confirm="…">.
document.querySelectorAll('form[data-confirm]').forEach((form) => {
    form.addEventListener('submit', (event) => {
        if (!window.confirm(form.dataset.confirm)) event.preventDefault();
    });
});

// 6. Search pickers (accent-insensitive, multi-term).
document.querySelectorAll('[data-search-picker]').forEach((picker) => {
    const search = picker.querySelector('[data-picker-search]');
    const value = picker.querySelector('[data-picker-value]');
    const selected = picker.querySelector('[data-picker-selected]');
    const options = [...picker.querySelectorAll('.picker-option')];
    if (!search || !value || !selected) return;
    const normalize = (text) => text.toLocaleLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    const filter = () => {
        const terms = normalize(search.value).trim().split(/\s+/).filter(Boolean);
        options.forEach((option) => {
            const haystack = normalize(option.dataset.pickerLabel || option.textContent);
            option.hidden = terms.length > 0 && !terms.every((term) => haystack.includes(term));
        });
    };
    search.addEventListener('input', () => { value.value = ''; picker.classList.remove('has-selection'); filter(); });
    options.forEach((option) => option.addEventListener('click', () => {
        value.value = option.dataset.pickerValue || '';
        search.value = option.dataset.pickerLabel || option.textContent.trim();
        selected.textContent = `Избрано: ${search.value}`;
        picker.classList.add('has-selection');
        filter();
        search.blur();
    }));
});

// 7. Language selector submits immediately.
document.querySelectorAll('[data-language-form]').forEach((form) => {
    const select = form.querySelector('[data-language-select]');
    if (!select) return;
    select.addEventListener('change', () => {
        if (typeof form.requestSubmit === 'function') form.requestSubmit();
        else form.submit();
    });
});

// 8. Wide tables scroll horizontally with the keyboard: Left/Right arrows (Shift = one screen), Home/End inside a table.
//    The table under the mouse, the focused table, or else the first visible scrollable table receives the keys.
(() => {
    const wraps = Array.from(document.querySelectorAll('.table-wrap'));
    if (!wraps.length) return;
    let hovered = null;
    wraps.forEach((wrap) => {
        if (!wrap.hasAttribute('tabindex')) wrap.tabIndex = 0;
        if (!wrap.hasAttribute('role')) wrap.setAttribute('role', 'region');
        if (!wrap.hasAttribute('aria-label')) wrap.setAttribute('aria-label', 'Табела; стрелките лево/десно ја движат хоризонтално');
        wrap.addEventListener('mouseenter', () => { hovered = wrap; });
        wrap.addEventListener('mouseleave', () => { if (hovered === wrap) hovered = null; });
    });
    const scrollable = (wrap) => wrap && wrap.scrollWidth > wrap.clientWidth + 1;
    const visible = (wrap) => { const r = wrap.getBoundingClientRect(); return r.bottom > 0 && r.top < window.innerHeight; };
    document.addEventListener('keydown', (event) => {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key) || event.altKey || event.ctrlKey || event.metaKey) return;
        const target = event.target;
        if (target instanceof Element && target.closest('input, textarea, select, [contenteditable="true"], .picker')) return;
        const focused = target instanceof Element ? target.closest('.table-wrap') : null;
        if ((event.key === 'Home' || event.key === 'End') && !focused) return;
        const wrap = [focused, hovered].find(scrollable) || wraps.find((w) => scrollable(w) && visible(w));
        if (!wrap) return;
        event.preventDefault();
        if (event.key === 'Home') { wrap.scrollLeft = 0; return; }
        if (event.key === 'End') { wrap.scrollLeft = wrap.scrollWidth; return; }
        const step = event.shiftKey ? wrap.clientWidth * 0.8 : 120;
        wrap.scrollBy({ left: event.key === 'ArrowLeft' ? -step : step, behavior: 'smooth' });
    });
})();

// Table pagination: choosing the rows per page reloads the list (server-side pagination).
document.addEventListener('change', (event) => {
    const select = event.target;
    if (select instanceof HTMLSelectElement && select.matches('select[data-page-size]') && select.form) select.form.submit();
});

// Searchable selects: every <select> with more than 7 options (budget codes, projects, directorates, users…) gets a
// combobox with accent-insensitive multi-term search over code and name, and A–Z / Z–A / original ordering. The native
// select stays in the form (hidden) and remains the value that is submitted and validated; data-plain opts out.
(() => {
    const MIN_OPTIONS = 8;
    const normalize = (text) => text.toLocaleLowerCase().normalize('NFD').replace(/[̀-ͯ]/g, '');
    const SORTS = [['original', 'Изворен редослед', '⇅'], ['asc', 'Подреди А–Ш', 'A–Z'], ['desc', 'Подреди Ш–А', 'Z–A']];
    const combos = [];
    let counter = 0;

    const enhance = (select) => {
        if (select.multiple || select.dataset.plain !== undefined || select.matches('[data-page-size], [data-language-select]') || select.options.length < MIN_OPTIONS || select.closest('.combo')) return;
        const id = `combo-${++counter}`;
        const wrap = document.createElement('div');
        wrap.className = 'combo';
        const field = document.createElement('div');
        field.className = 'combo__field';
        const input = document.createElement('input');
        input.type = 'search';
        input.className = 'combo__input';
        input.autocomplete = 'off';
        input.setAttribute('role', 'combobox');
        input.setAttribute('aria-autocomplete', 'list');
        input.setAttribute('aria-expanded', 'false');
        input.setAttribute('aria-controls', `${id}-list`);
        const label = select.labels && select.labels[0] ? select.labels[0].textContent.trim() : '';
        if (label) input.setAttribute('aria-label', label);
        const sort = document.createElement('button');
        sort.type = 'button';
        sort.className = 'combo__sort';
        const list = document.createElement('ul');
        list.className = 'combo__list';
        list.id = `${id}-list`;
        list.setAttribute('role', 'listbox');
        list.hidden = true;
        const count = document.createElement('div');
        count.className = 'combo__count';
        count.setAttribute('aria-live', 'polite');
        select.parentNode.insertBefore(wrap, select);
        field.append(input, sort);
        wrap.append(field, list, count, select);
        select.classList.add('combo__native');
        select.tabIndex = -1;
        select.setAttribute('aria-hidden', 'true');

        let sortIndex = 0;
        let items = [];
        let active = -1;
        const placeholderOption = () => [...select.options].find((o) => o.value === '');
        const syncInput = () => {
            const option = select.selectedOptions[0];
            const empty = !option || option.value === '';
            input.value = empty ? '' : option.textContent.trim();
            const placeholder = placeholderOption();
            input.placeholder = placeholder ? placeholder.textContent.trim() : 'Пребарај по шифра или назив…';
            if (empty && !placeholder) input.placeholder = 'Пребарај по шифра или назив…';
        };
        const renderSort = () => {
            const [, title, text] = SORTS[sortIndex];
            sort.textContent = text;
            sort.title = title;
            sort.setAttribute('aria-label', title);
        };
        const setActive = (index) => {
            items.forEach((item, i) => item.el.classList.toggle('is-active', i === index));
            active = index;
            if (index >= 0 && items[index]) {
                input.setAttribute('aria-activedescendant', items[index].el.id);
                items[index].el.scrollIntoView({ block: 'nearest' });
            } else input.removeAttribute('aria-activedescendant');
        };
        const build = (query) => {
            const terms = normalize(query).trim().split(/\s+/).filter(Boolean);
            let options = [...select.options].filter((o) => !o.hidden && !o.disabled && (o.value !== '' || terms.length === 0));
            options = options.filter((o) => { const text = normalize(o.textContent); return terms.every((term) => text.includes(term)); });
            const mode = SORTS[sortIndex][0];
            if (mode !== 'original') {
                options = options.slice().sort((a, b) => (a.value === '') - (b.value === '') || a.textContent.trim().localeCompare(b.textContent.trim(), 'mk', { numeric: true, sensitivity: 'base' }) * (mode === 'asc' ? 1 : -1));
            }
            list.textContent = '';
            items = options.map((option, i) => {
                const li = document.createElement('li');
                li.id = `${id}-o${i}`;
                li.className = 'combo__option';
                li.setAttribute('role', 'option');
                li.setAttribute('aria-selected', String(option.selected && option.value !== ''));
                const group = option.parentElement && option.parentElement.tagName === 'OPTGROUP' ? option.parentElement.label : '';
                li.textContent = option.textContent.trim();
                if (group) { const small = document.createElement('small'); small.textContent = group; li.append(small); }
                li.addEventListener('mousedown', (event) => { event.preventDefault(); choose(option); });
                list.append(li);
                return { el: li, option };
            });
            if (!items.length) {
                const li = document.createElement('li');
                li.className = 'combo__empty';
                li.textContent = 'Нема резултати';
                list.append(li);
            }
            const total = [...select.options].filter((o) => o.value !== '' && !o.hidden && !o.disabled).length;
            const shown = items.filter((item) => item.option.value !== '').length;
            count.textContent = terms.length ? `${shown} nga ${total}` : '';
            setActive(items.length ? Math.max(0, items.findIndex((item) => item.option.selected && item.option.value !== '')) : -1);
        };
        const open = (query = '') => {
            build(query);
            list.hidden = false;
            wrap.classList.add('is-open');
            input.setAttribute('aria-expanded', 'true');
        };
        const close = () => {
            list.hidden = true;
            wrap.classList.remove('is-open');
            input.setAttribute('aria-expanded', 'false');
            count.textContent = '';
            syncInput();
        };
        const choose = (option) => {
            if (select.value !== option.value) {
                select.value = option.value;
                select.dispatchEvent(new Event('input', { bubbles: true }));
                select.dispatchEvent(new Event('change', { bubbles: true }));
            }
            wrap.classList.remove('is-invalid');
            close();
        };

        input.addEventListener('focus', () => { input.select(); open(''); });
        input.addEventListener('click', () => { if (list.hidden) open(''); });
        input.addEventListener('input', () => open(input.value));
        input.addEventListener('blur', () => window.setTimeout(() => { if (document.activeElement !== input) close(); }, 120));
        input.addEventListener('keydown', (event) => {
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                event.preventDefault();
                if (list.hidden) { open(''); return; }
                const step = event.key === 'ArrowDown' ? 1 : -1;
                if (items.length) setActive((active + step + items.length) % items.length);
            } else if (event.key === 'Enter') {
                if (!list.hidden) { event.preventDefault(); if (active >= 0 && items[active]) choose(items[active].option); }
            } else if (event.key === 'Escape') {
                if (!list.hidden) { event.preventDefault(); close(); }
            } else if (event.key === 'Tab' && !list.hidden && input.value.trim() !== '' && items.length === 1) {
                choose(items[0].option);
            }
        });
        sort.addEventListener('mousedown', (event) => event.preventDefault());
        sort.addEventListener('click', () => {
            sortIndex = (sortIndex + 1) % SORTS.length;
            renderSort();
            if (document.activeElement !== input) input.focus();
            open(list.hidden ? '' : (input.value === (select.selectedOptions[0] || {}).textContent?.trim() ? '' : input.value));
        });
        select.addEventListener('invalid', () => { wrap.classList.add('is-invalid'); input.focus(); });
        select.addEventListener('change', () => { if (list.hidden) syncInput(); });
        renderSort();
        syncInput();
        combos.push(syncInput);
    };

    document.querySelectorAll('select').forEach(enhance);
    // Other scripts may change a select's value or options without events (e.g. dependent project lists): resync labels.
    document.addEventListener('change', () => window.setTimeout(() => combos.forEach((sync) => sync()), 0));
    document.addEventListener('reset', () => window.setTimeout(() => combos.forEach((sync) => sync()), 0));
})();

// Search pickers also get A–Z / Z–A / original ordering.
document.querySelectorAll('[data-search-picker]').forEach((picker) => {
    const container = picker.querySelector('[data-picker-options]');
    const search = picker.querySelector('[data-picker-search]');
    if (!container || !search || container.children.length < 2) return;
    const original = [...container.children];
    const modes = [['original', 'Изворен редослед', '⇅'], ['asc', 'Подреди А–Ш', 'A–Z'], ['desc', 'Подреди Ш–А', 'Z–A']];
    let index = 0;
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'combo__sort picker-sort';
    const render = () => { const [, title, text] = modes[index]; button.textContent = text; button.title = title; button.setAttribute('aria-label', title); };
    button.addEventListener('mousedown', (event) => event.preventDefault());
    button.addEventListener('click', () => {
        index = (index + 1) % modes.length;
        render();
        const mode = modes[index][0];
        const sorted = mode === 'original' ? original : original.slice().sort((a, b) => (a.dataset.pickerLabel || a.textContent).localeCompare(b.dataset.pickerLabel || b.textContent, 'mk', { numeric: true, sensitivity: 'base' }) * (mode === 'asc' ? 1 : -1));
        container.append(...sorted);
        search.focus();
    });
    render();
    const field = document.createElement('div');
    field.className = 'combo__field';
    search.parentNode.insertBefore(field, search);
    field.append(search, button);
});

// Drag and drop uploads: files dropped on (or chosen in) a [data-dropzone] are added to its file field, listed with
// their size and removable; the form then submits them as usual.
document.querySelectorAll('[data-dropzone]').forEach((zone) => {
    const input = zone.querySelector('input[type="file"]');
    const list = zone.parentElement ? zone.parentElement.querySelector('.dropzone__list') : null;
    if (!input || !list || typeof DataTransfer === 'undefined') return;
    let files = [];
    const size = (bytes) => (bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`);
    // Rebuilds the input's file list (needed after a drop or when several picks are added up). A single file chosen in
    // the picker is left exactly as the browser set it: older iOS Safari cannot build a DataTransfer.
    const sync = (rebuild = true) => {
        if (rebuild) {
            try {
                const transfer = new DataTransfer();
                files.forEach((file) => transfer.items.add(file));
                input.files = transfer.files;
            } catch (error) { /* keep the browser's own selection */ }
        }
        list.textContent = '';
        files.forEach((file, index) => {
            const item = document.createElement('li');
            const name = document.createElement('span');
            name.textContent = `${file.name} · ${size(file.size)}`;
            const remove = document.createElement('button');
            remove.type = 'button';
            remove.className = 'dropzone__remove';
            remove.textContent = 'Отстрани';
            remove.setAttribute('aria-label', `Отстрани ${file.name}`);
            remove.addEventListener('click', () => { files.splice(index, 1); if (!files.length) input.value = ''; sync(files.length > 0); });
            item.append(name, remove);
            list.append(item);
        });
        zone.classList.toggle('has-files', files.length > 0);
    };
    const add = (incoming) => {
        const picked = [...incoming];
        if (!picked.length) return;
        if (input.multiple) {
            const key = (file) => `${file.name}|${file.size}|${file.lastModified}`;
            const known = new Set(files.map(key));
            files = files.concat(picked.filter((file) => !known.has(key(file))));
        } else files = [picked[0]];
        sync();
    };
    input.addEventListener('change', () => {
        const chosen = [...input.files];
        if (!input.multiple) { files = chosen.slice(0, 1); sync(false); return; }
        if (chosen.length) add(chosen); else sync(false);
    });
    ['dragenter', 'dragover'].forEach((type) => zone.addEventListener(type, (event) => { event.preventDefault(); zone.classList.add('is-dragging'); }));
    ['dragleave', 'dragend'].forEach((type) => zone.addEventListener(type, (event) => { if (!zone.contains(event.relatedTarget)) zone.classList.remove('is-dragging'); }));
    zone.addEventListener('drop', (event) => {
        event.preventDefault();
        zone.classList.remove('is-dragging');
        if (event.dataTransfer && event.dataTransfer.files) add(event.dataTransfer.files);
    });
    if (input.form) input.form.addEventListener('reset', () => { files = []; window.setTimeout(sync, 0); });
});

// Revenue import read in the background: poll its status and reload the page when it is done.
document.querySelectorAll('[data-import-status]').forEach((box) => {
    const url = box.dataset.importStatus;
    const poll = () => fetch(url, { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
        .then((response) => response.ok ? response.json() : null)
        .then((data) => {
            if (data && data.done) { window.location.reload(); return; }
            const label = box.querySelector('[data-import-progress]');
            if (label && data && data.status === 'processing' && data.progress > 0) label.textContent = 'Извештајот се чита (досега ' + data.progress.toLocaleString('en-US') + ' редови).';
            window.setTimeout(poll, 2000);
        })
        .catch(() => window.setTimeout(poll, 5000));
    window.setTimeout(poll, 1500);
});

// Simple dialogs (e.g. "Importo raportin ditor"): [data-dialog-open="<id>"] opens <dialog id="<id>" data-dialog>; the
// link still leads to a page with the same form without JavaScript. While the file is read, the submit button waits.
document.querySelectorAll('dialog[data-dialog]').forEach((dialog) => {
    if (typeof dialog.showModal !== 'function') return;
    document.querySelectorAll('[data-dialog-open="' + dialog.id + '"]').forEach((link) => link.addEventListener('click', (event) => { event.preventDefault(); dialog.showModal(); }));
    dialog.querySelectorAll('[data-modal-close]').forEach((button) => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
    dialog.querySelectorAll('form').forEach((form) => form.addEventListener('submit', () => {
        form.querySelectorAll('button:not([type="button"])').forEach((button) => { button.disabled = true; button.textContent = 'Се чита извештајот…'; });
    }));
    if (dialog.hasAttribute('data-open-on-load')) dialog.showModal();
});

// Project task dialog: "Task i ri" and the edit icons open one <dialog> (the links still work without JavaScript).
// It checks the project's total weight and can ask the server to scale the other tasks to keep 100%.
document.querySelectorAll('[data-task-modal]').forEach((dialog) => {
    const form = dialog.querySelector('[data-task-form]');
    if (!form || typeof dialog.showModal !== 'function') return;
    const field = (name) => form.elements.namedItem(name);
    const title = dialog.querySelector('[data-task-title]');
    const submit = dialog.querySelector('[data-task-submit]');
    const again = dialog.querySelector('[data-task-again]');
    const againFlag = dialog.querySelector('[data-task-again-flag]');
    const version = dialog.querySelector('[data-task-version]');
    const rebalance = dialog.querySelector('[data-task-rebalance]');
    const documents = dialog.querySelector('[data-task-documents]');
    const weightInput = dialog.querySelector('[data-weight-input]');
    const weightBox = dialog.querySelector('[data-weight-box]');
    const weightStatus = dialog.querySelector('[data-weight-status]');
    const weightMeter = dialog.querySelector('[data-weight-meter]');
    const weightHelp = dialog.querySelector('[data-weight-help]');
    const rebalanceButton = dialog.querySelector('[data-weight-rebalance]');
    const progress = dialog.querySelector('[data-progress-input]');
    const progressOutput = dialog.querySelector('[data-progress-output]');
    let weights = [];
    try { weights = JSON.parse(dialog.dataset.weights || '[]'); } catch (error) { weights = []; }
    let editingId = null;
    const number = (value) => (Number.isFinite(Number(value)) ? Number(value) : 0);
    const format = (value) => `${Math.round(value * 100) / 100}%`;

    const renderWeight = () => {
        const own = number(weightInput.value);
        const others = weights.filter((task) => task.id !== editingId);
        const used = others.reduce((sum, task) => sum + number(task.weight), 0);
        const total = used + own;
        const scaled = rebalance.value === '1' && total > 100 && used > 0 ? (100 - own) / used : 1;
        weightMeter.textContent = '';
        others.forEach((task) => {
            const segment = document.createElement('span');
            segment.className = `weight-meter__part${task.done ? ' is-done' : ''}${task.late ? ' is-late' : ''}`;
            segment.style.width = `${(number(task.weight) * scaled) / Math.max(100, rebalance.value === '1' ? 100 : total) * 100}%`;
            segment.title = `${task.title}: ${format(number(task.weight) * scaled)}`;
            weightMeter.append(segment);
        });
        const mine = document.createElement('span');
        mine.className = 'weight-meter__part is-current';
        mine.style.width = `${own / Math.max(100, rebalance.value === '1' ? 100 : total) * 100}%`;
        weightMeter.append(mine);
        const over = total > 100.00001;
        weightBox.classList.toggle('is-over', over && rebalance.value !== '1');
        rebalanceButton.hidden = !over || rebalance.value === '1';
        if (over && rebalance.value === '1') {
            weightStatus.textContent = `Другите задачи пропорционално се намалуваат: ${format(used)} → ${format(100 - own)}`;
            weightHelp.textContent = 'По зачувувањето вкупната тежина ќе биде 100%.';
        } else if (over) {
            weightStatus.textContent = `Вкупната тежина ќе биде ${format(total)} – треба да биде до 100%`;
            weightHelp.textContent = 'Намалете ја тежината или прераспределете ги другите задачи според нивната тежина.';
        } else {
            weightStatus.textContent = `Вкупна тежина: ${format(total)} · слободни ${format(100 - total)}`;
            weightHelp.textContent = 'Тежината на сите задачи мора да изнесува вкупно до 100%.';
        }
        weightInput.setCustomValidity(over && rebalance.value !== '1' ? 'Вкупната тежина надминува 100%. Намалете ја тежината или прераспределете.' : '');
    };
    const renderProgress = () => { progressOutput.textContent = `${progress.value}%`; };
    const setStatus = (value) => { const radio = form.querySelector(`input[name="status"][value="${value}"]`); if (radio) radio.checked = true; };

    const open = (task) => {
        form.reset();
        editingId = task ? Number(task.id) : null;
        rebalance.value = '0';
        againFlag.value = '0';
        form.action = task ? `${dialog.dataset.createAction}/${task.id}` : dialog.dataset.createAction;
        version.disabled = !task;
        version.value = task ? task.row_version : '';
        title.textContent = task ? 'Уреди ја задачата' : 'Нова задача';
        submit.textContent = task ? 'Зачувај ги промените' : 'Создај ја задачата';
        again.hidden = Boolean(task);
        if (documents) documents.hidden = Boolean(task);
        if (task) {
            ['title', 'owner_id', 'starts_at', 'due_at', 'weight_percent', 'next_step', 'dependency_notes'].forEach((name) => { const input = field(name); if (input) input.value = task[name] ?? ''; });
            progress.value = task.progress;
            setStatus(task.status);
        }
        window.setTimeout(() => { renderWeight(); renderProgress(); document.dispatchEvent(new Event('change')); }, 0);
        dialog.showModal();
        const first = field('title');
        if (first) first.focus();
    };

    document.querySelectorAll('[data-modal-open="task-modal"]').forEach((link) => link.addEventListener('click', (event) => { event.preventDefault(); open(null); }));
    document.querySelectorAll('[data-task-edit]').forEach((link) => link.addEventListener('click', (event) => {
        event.preventDefault();
        try { open(JSON.parse(link.dataset.taskEdit)); } catch (error) { window.location.href = link.href; }
    }));
    dialog.querySelectorAll('[data-modal-close]').forEach((button) => button.addEventListener('click', () => dialog.close()));
    dialog.addEventListener('click', (event) => { if (event.target === dialog) dialog.close(); });
    weightInput.addEventListener('input', () => { rebalance.value = '0'; renderWeight(); });
    rebalanceButton.addEventListener('click', () => { rebalance.value = '1'; renderWeight(); weightInput.focus(); });
    progress.addEventListener('input', () => {
        renderProgress();
        const status = form.querySelector('input[name="status"]:checked');
        if (Number(progress.value) === 100) setStatus('done');
        else if (status && status.value === 'done') setStatus('in_progress');
        else if (Number(progress.value) > 0 && status && status.value === 'not_started') setStatus('in_progress');
    });
    form.querySelectorAll('input[name="status"]').forEach((radio) => radio.addEventListener('change', () => {
        if (radio.value === 'done') progress.value = 100;
        if (radio.value === 'not_started') progress.value = 0;
        renderProgress();
    }));
    const end = dialog.dataset.projectEnd;
    const due = field('due_at');
    const starts = field('starts_at');
    const checkDates = () => {
        due.setCustomValidity(starts.value && due.value && due.value < starts.value ? 'Рокот мора да биде на или по датумот на почеток.' : '');
    };
    due.addEventListener('input', checkDates);
    starts.addEventListener('input', () => { due.min = starts.value || ''; checkDates(); });
    const dueHint = dialog.querySelector('[data-due-hint]');
    due.addEventListener('input', () => { if (dueHint && end) dueHint.classList.toggle('text-danger', Boolean(due.value) && due.value > end); });
    again.addEventListener('click', () => { againFlag.value = '1'; if (form.reportValidity()) form.requestSubmit(submit); else againFlag.value = '0'; });
    form.addEventListener('keydown', (event) => {
        if (event.key === 'Enter' && (event.ctrlKey || event.metaKey)) { event.preventDefault(); if (form.reportValidity()) form.requestSubmit(submit); }
    });
    form.addEventListener('submit', () => { submit.disabled = true; submit.textContent = 'Се зачувува…'; });
    if (dialog.hasAttribute('data-open-on-load')) open(null);
});

// After saving, the saved task's row is highlighted briefly.
if (window.location.hash.startsWith('#task-')) {
    const row = document.getElementById(window.location.hash.slice(1));
    if (row) { row.classList.add('is-highlighted'); window.setTimeout(() => row.classList.remove('is-highlighted'), 4000); }
}
