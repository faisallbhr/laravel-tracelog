(() => {
    const root = document.getElementById('tracelog-viewer');
    const date_list = document.getElementById('trace-date');
    const date_count = document.getElementById('date-count');
    const tag_input = document.getElementById('trace-tag');
    const list = document.getElementById('trace-list');
    const pagination = document.getElementById('trace-pagination');
    const shared_query = new URLSearchParams(window.location.search);
    const shared_date = shared_query.get('date');
    const shared_trace_id = shared_query.get('trace_id');
    const theme_options = document.querySelectorAll('input[name="theme"]');
    const settings = document.querySelector('.settings');
    const valid_themes = ['system', 'light', 'dark'];
    const configured_page_size = Number.parseInt(root.dataset.pageSize || '25', 10);
    const page_size = Number.isFinite(configured_page_size) && configured_page_size > 0
        ? configured_page_size
        : 25;
    let page = 1;
    let selected_date = '';

    document.addEventListener('click', (event) => {
        if (settings.open && !settings.contains(event.target)) settings.open = false;
    });

    document.addEventListener('keydown', (event) => {
        if (event.key === 'Escape') settings.open = false;
    });

    const apply_theme = (theme) => {
        if (theme === 'system') {
            document.documentElement.removeAttribute('data-theme');
        } else {
            document.documentElement.dataset.theme = theme;
        }

        theme_options.forEach((option) => {
            option.checked = option.value === theme;
        });
    };

    let saved_theme = 'system';
    try {
        const stored_theme = window.localStorage.getItem('tracelog-theme');
        if (valid_themes.includes(stored_theme)) saved_theme = stored_theme;
    } catch (error) {
        // Local storage can be unavailable in private or restricted browser contexts.
    }

    apply_theme(saved_theme);
    theme_options.forEach((option) => option.addEventListener('change', () => {
        apply_theme(option.value);
        try {
            window.localStorage.setItem('tracelog-theme', option.value);
        } catch (error) {
            // Theme still applies for the current page when persistence is unavailable.
        }
    }));

    const text = (item) => document.createTextNode(item === null || item === undefined ? 'null' : String(item));
    const element = (name, item, class_name) => {
        const node = document.createElement(name);
        if (class_name) node.className = class_name;
        if (item !== undefined) node.append(text(item));
        return node;
    };
    const value = (item) => JSON.stringify(item, null, 2);
    const format_date = (date) => {
        const parsed_date = new Date(`${date}T00:00:00`);

        if (Number.isNaN(parsed_date.getTime())) return date;

        return new Intl.DateTimeFormat(undefined, {
            day: '2-digit',
            month: 'short',
            year: 'numeric',
        }).format(parsed_date);
    };
    const format_datetime = (datetime) => {
        const parsed_datetime = new Date(datetime);

        if (Number.isNaN(parsed_datetime.getTime())) return datetime;

        return new Intl.DateTimeFormat(undefined, {
            dateStyle: 'medium',
            timeStyle: 'medium',
        }).format(parsed_datetime);
    };
    const format_time = (datetime) => {
        const parsed_datetime = new Date(datetime);

        if (Number.isNaN(parsed_datetime.getTime())) return datetime;

        return new Intl.DateTimeFormat(undefined, { timeStyle: 'medium' }).format(parsed_datetime);
    };
    async function get_json(url) {
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        const payload = await response.json();
        if (!response.ok) throw new Error(payload.message || 'Unable to load TraceLog data.');
        return payload;
    }

    async function load_dates() {
        list.replaceChildren(element('p', 'Loading dates…', 'empty'));
        const payload = await get_json(root.dataset.datesUrl);
        date_list.replaceChildren();
        date_count.textContent = payload.dates.length ? String(payload.dates.length) : '';
        payload.dates.forEach((date) => {
            const date_button = element('button', format_date(date), 'date-item');
            date_button.type = 'button';
            date_button.dataset.date = date;
            date_button.setAttribute('role', 'option');
            date_button.onclick = () => {
                select_date(date);
                load_traces();
            };
            date_list.append(date_button);
        });
        if (payload.dates.length) {
            select_date(shared_date && payload.dates.includes(shared_date) ? shared_date : payload.dates[0]);
            await load_traces();
            if (shared_trace_id) await open_shared_trace(shared_trace_id);
        } else {
            list.replaceChildren(element('p', 'No TraceLog dates available yet.', 'empty'));
        }
    }

    async function load_traces() {
        if (!selected_date) {
            list.replaceChildren(element('p', 'Select a log date to view traces.', 'empty'));
            pagination.replaceChildren();

            return;
        }

        const params = new URLSearchParams({ date: selected_date, page: String(page), per_page: String(page_size) });
        if (tag_input.value) params.set('tag', tag_input.value);
        list.classList.add('is-loading');
        const loading_overlay = element('div', undefined, 'loading-overlay');
        const loading_spinner = element('span', undefined, 'spinner');
        loading_spinner.setAttribute('aria-hidden', 'true');
        loading_overlay.append(loading_spinner, text('Loading traces…'));
        list.append(loading_overlay);
        list.setAttribute('aria-busy', 'true');
        try {
            const payload = await get_json(`${root.dataset.tracesUrl}?${params}`);
            list.replaceChildren();
            if (payload.traces.length) {
                payload.traces.forEach((trace) => list.append(render_trace(trace)));
            } else {
                list.append(element('p', 'No traces match these filters.', 'empty'));
            }
            render_pagination(payload);
        } catch (error) {
            list.replaceChildren();
            list.append(element('p', error.message || 'Trace data could not be loaded. Try refreshing.', 'empty'));
            pagination.replaceChildren();
        } finally {
            list.classList.remove('is-loading');
            list.removeAttribute('aria-busy');
        }
    }

    function render_trace(trace) {
        const details = document.createElement('details');
        details.className = 'trace';
        details.dataset.traceId = trace.trace_id;
        const summary = document.createElement('summary');
        const summary_main = element('span', undefined, 'trace-summary-main');
        const summary_top = element('span', undefined, 'trace-summary-top');
        summary_top.append(element('span', trace.trace_id, 'trace-id'));
        summary_top.append(element('span', String(trace.event_count) + ' events', 'trace-count'));
        summary_main.append(summary_top);
        const model_names = trace.models.map((model) => model.split('\\').pop()).slice(0, 2).join(', ');
        const model_suffix = trace.models.length > 2 ? ' +' + (trace.models.length - 2) : '';
        const model_row = element('span', undefined, 'trace-models');
        model_row.append(element('span', model_names || 'No models', 'trace-context'));
        if (model_suffix) model_row.append(element('span', model_suffix + ' models', 'trace-model-more'));
        summary_main.append(model_row);
        const tag_row = element('span', undefined, 'trace-tags');
        if (trace.tags.length) {
            trace.tags.forEach((tag) => tag_row.append(element('span', tag, 'trace-tag')));
        } else {
            tag_row.append(element('span', 'untagged', 'trace-tag is-empty'));
        }
        summary_main.append(tag_row);
        const timestamp = element('time', format_time(trace.started_at), 'trace-time');
        timestamp.dateTime = trace.started_at;
        timestamp.title = trace.started_at;
        const share_button = document.createElement('button');
        share_button.className = 'share-button';
        share_button.type = 'button';
        share_button.title = 'Copy share link';
        share_button.setAttribute('aria-label', `Copy share link for trace ${trace.trace_id}`);
        const share_icon = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
        share_icon.setAttribute('viewBox', '0 0 24 24');
        share_icon.setAttribute('aria-hidden', 'true');
        const share_path = document.createElementNS('http://www.w3.org/2000/svg', 'path');
        share_path.setAttribute('d', 'M10 13a5 5 0 0 0 7.07.07l2-2a5 5 0 0 0-7.07-7.07l-1.15 1.15M14 11a5 5 0 0 0-7.07-.07l-2 2A5 5 0 0 0 12 20l1.15-1.15');
        share_icon.append(share_path);
        share_button.append(share_icon);
        share_button.addEventListener('click', (event) => {
            event.preventDefault();
            event.stopPropagation();
            share_trace(trace.trace_id);
        });
        const chevron = element('span', '›', 'trace-chevron');
        chevron.setAttribute('aria-hidden', 'true');
        summary.append(summary_main, timestamp, share_button, chevron);
        details.append(summary);
        const meta = element('p', undefined, 'trace-meta');
        meta.append(element('span', `${format_datetime(trace.started_at)} → ${format_datetime(trace.ended_at)}`));
        meta.append(element('span', `Tags: ${trace.tags.join(', ') || 'none'}`));
        meta.append(element('span', `Models: ${trace.models.join(', ') || 'none'}`));
        details.append(meta);
        details.addEventListener('toggle', () => details.open && load_detail(details, trace.trace_id));
        return details;
    }

    async function share_trace(trace_id) {
        const share_url = new URL(window.location.href);
        share_url.search = new URLSearchParams({ date: selected_date, trace_id }).toString();
        share_url.hash = '';

        try {
            if (navigator.clipboard?.writeText) {
                await navigator.clipboard.writeText(share_url.toString());
            } else {
                const copy_input = document.createElement('textarea');
                copy_input.value = share_url.toString();
                copy_input.style.position = 'fixed';
                copy_input.style.opacity = '0';
                document.body.append(copy_input);
                copy_input.select();
                const copied = document.execCommand('copy');
                copy_input.remove();

                if (!copied) throw new Error('Copy command failed.');
            }

        } catch (error) {
            list.prepend(element('p', `Copy this link: ${share_url}`, 'empty'));
        }
    }

    async function open_shared_trace(trace_id) {
        const existing_trace = list.querySelector(`[data-trace-id="${CSS.escape(trace_id)}"]`);
        let details = existing_trace;

        if (!details) {
            try {
                const url = root.dataset.traceUrlTemplate.replace('__TRACE_ID__', encodeURIComponent(trace_id));
                const trace = await get_json(`${url}?date=${encodeURIComponent(selected_date)}`);
                details = render_trace(trace);
                list.prepend(details);
            } catch (error) {
                list.prepend(element('p', error.message, 'empty'));

                return;
            }
        }

        details.open = true;
        details.scrollIntoView({ block: 'center' });
    }

    async function load_detail(container, trace_id) {
        if (container.dataset.loaded) return;
        const url = root.dataset.traceUrlTemplate.replace('__TRACE_ID__', encodeURIComponent(trace_id));
        const detail = element('div', undefined, 'detail');
        const loading = element('p', undefined, 'meta loading');
        const spinner = element('span', undefined, 'spinner');
        spinner.setAttribute('aria-hidden', 'true');
        loading.append(spinner, text('Loading timeline…'));
        detail.append(loading);
        container.append(detail);
        try {
            const trace = await get_json(`${url}?date=${encodeURIComponent(selected_date)}`);
            detail.replaceChildren(element('p', `Parts: ${trace.files.join(', ')}`, 'detail-heading'));
            const timeline = element('div', undefined, 'timeline');
            trace.events.forEach((event) => {
                const item = element('article', undefined, 'event');
                const title = element('h3', undefined, 'event-title');
                const timestamp = element('time', format_datetime(event.timestamp));
                timestamp.dateTime = event.timestamp;
                timestamp.title = event.timestamp;
                title.append(text(event.event), text(' '), timestamp);
                item.append(title);
                item.append(element('p', `${event.model} #${event.id ?? 'null'}`, 'meta'));
                if (event.source) item.append(element('p', event.source, 'meta'));
                if (event.changes) {
                    const changes = document.createElement('details');
                    changes.className = 'changes';
                    const changes_summary = element('summary', 'View changes');
                    changes.addEventListener('toggle', () => {
                        changes_summary.textContent = changes.open ? 'Hide changes' : 'View changes';
                    });
                    changes.append(changes_summary, element('pre', value(event.changes)));
                    item.append(changes);
                }
                timeline.append(item);
            });
            detail.append(timeline);
            container.dataset.loaded = '1';
        } catch (error) {
            detail.replaceChildren(element('p', error.message, 'status'));
        }
    }

    function render_pagination(payload) {
        pagination.replaceChildren();
        const total_pages = Math.ceil(payload.total / payload.per_page);

        if (payload.total < 1) return;

        const previous = element('button', '‹', 'pagination-arrow');
        previous.type = 'button';
        previous.setAttribute('aria-label', 'Previous page');
        previous.title = 'Previous page';
        previous.disabled = payload.page <= 1;
        if (payload.page > 1) {
            previous.onclick = () => { page--; load_traces(); };
        }
        pagination.append(previous);

        const visible_pages = new Set([
            1,
            total_pages,
            Math.max(1, payload.page - 1),
            payload.page,
            Math.min(total_pages, payload.page + 1),
        ]);
        let previous_page = 0;

        [...visible_pages].sort((first_page, second_page) => first_page - second_page).forEach((page_number) => {
            if (page_number - previous_page > 1) {
                pagination.append(element('span', '…', 'page-ellipsis'));
            }

            const page_button = element('button', String(page_number), 'page-number');
            page_button.type = 'button';
            page_button.setAttribute('aria-label', 'Go to page ' + page_number);

            if (page_number === payload.page) {
                page_button.classList.add('is-current');
                page_button.setAttribute('aria-current', 'page');
            } else {
                page_button.onclick = () => { page = page_number; load_traces(); };
            }

            pagination.append(page_button);
            previous_page = page_number;
        });

        const next = element('button', '›', 'pagination-arrow');
        next.type = 'button';
        next.setAttribute('aria-label', 'Next page');
        next.title = 'Next page';
        next.disabled = !payload.has_more;
        if (payload.has_more) {
            next.onclick = () => { page++; load_traces(); };
        }
        pagination.append(next);
    }

    function select_date(date) {
        selected_date = date;
        date_list.querySelectorAll('.date-item').forEach((date_button) => {
            const is_selected = date_button.dataset.date === date;
            date_button.classList.toggle('is-selected', is_selected);
            date_button.setAttribute('aria-selected', String(is_selected));
        });
        page = 1;
    }

    document.getElementById('trace-refresh').onclick = () => { page = 1; load_traces(); };
    tag_input.onkeydown = (event) => {
        if (event.key === 'Enter') { page = 1; load_traces(); }
    };
    load_dates().catch((error) => {
        list.replaceChildren(element('p', error.message || 'TraceLog is unavailable right now.', 'empty'));
    });
})();
