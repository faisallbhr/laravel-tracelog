(() => {
    const root = document.getElementById('tracelog-viewer');
    const date_select = document.getElementById('trace-date');
    const tag_input = document.getElementById('trace-tag');
    const selected_date = document.getElementById('selected-date');
    const list = document.getElementById('trace-list');
    const status = document.getElementById('trace-status');
    const pagination = document.getElementById('trace-pagination');
    const shared_query = new URLSearchParams(window.location.search);
    const shared_date = shared_query.get('date');
    const shared_trace_id = shared_query.get('trace_id');
    const theme_options = document.querySelectorAll('input[name="theme"]');
    const settings = document.querySelector('.settings');
    const valid_themes = ['system', 'light', 'dark'];
    const page_size = Math.max(1, Number.parseInt(root.dataset.pageSize || '25', 10));
    let page = 1;

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
    const format_date = (date) => new Intl.DateTimeFormat(undefined, {
        day: '2-digit',
        month: 'short',
        year: 'numeric',
    }).format(new Date(`${date}T00:00:00`));
    const format_datetime = (datetime) => {
        const parsed_datetime = new Date(datetime);

        if (Number.isNaN(parsed_datetime.getTime())) return datetime;

        return new Intl.DateTimeFormat(undefined, {
            dateStyle: 'medium',
            timeStyle: 'medium',
        }).format(parsed_datetime);
    };
    const set_status = (message, state = '', is_loading = false) => {
        status.replaceChildren();
        if (is_loading) {
            const loading = element('span', undefined, 'loading');
            const spinner = element('span', undefined, 'spinner');
            spinner.setAttribute('aria-hidden', 'true');
            loading.append(spinner, text(message));
            status.append(loading);
        } else {
            status.append(text(message));
        }
        status.dataset.state = state;
    };

    async function get_json(url) {
        const response = await fetch(url, { headers: { Accept: 'application/json' } });
        const payload = await response.json();
        if (!response.ok) throw new Error(payload.message || 'Unable to load TraceLog data.');
        return payload;
    }

    async function load_dates() {
        set_status('Loading dates…', '', true);
        const payload = await get_json(root.dataset.datesUrl);
        date_select.replaceChildren();
        payload.dates.forEach((date) => date_select.append(new Option(format_date(date), date)));
        if (payload.dates.length) {
            if (shared_date && payload.dates.includes(shared_date)) date_select.value = shared_date;
            selected_date.textContent = format_date(date_select.value);
            await load_traces();
            if (shared_trace_id) await open_shared_trace(shared_trace_id);
        } else {
            selected_date.textContent = 'No dates yet';
            list.replaceChildren(element('p', 'No TraceLog dates available yet.', 'empty'));
            set_status('Nothing to inspect yet. New traces will appear here.');
        }
    }

    async function load_traces() {
        const params = new URLSearchParams({ date: date_select.value, page: String(page), per_page: String(page_size) });
        if (tag_input.value) params.set('tag', tag_input.value);
        set_status('Loading traces…', '', true);
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
            set_status(payload.total ? `${payload.total} trace(s) on ${format_date(date_select.value)}` : 'No traces match these filters.');
            if (payload.traces.length) {
                payload.traces.forEach((trace) => list.append(render_trace(trace)));
            } else {
                list.append(element('p', 'No traces match these filters.', 'empty'));
            }
            render_pagination(payload);
        } catch (error) {
            set_status(error.message, 'error');
            list.replaceChildren();
            list.append(element('p', 'Trace data could not be loaded. Try refreshing.', 'empty'));
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
        summary_main.append(element('span', trace.trace_id, 'trace-id'));
        const model_names = trace.models.map((model) => model.split('\\').pop()).slice(0, 2).join(', ');
        const model_suffix = trace.models.length > 2 ? ` +${trace.models.length - 2}` : '';
        const model_summary = `${model_names}${model_suffix}`;
        const tag_summary = trace.tags.length ? ` · Tags: ${trace.tags.join(', ')}` : ' · Untagged';
        summary_main.append(element('span', `${format_datetime(trace.started_at)} · Models: ${model_summary}${tag_summary}`, 'trace-context'));
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
        summary.append(summary_main, element('span', `${trace.event_count} event(s)`, 'trace-count'), share_button);
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
        share_url.search = new URLSearchParams({ date: date_select.value, trace_id }).toString();
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

            set_status('Share link copied.');
        } catch (error) {
            set_status(`Copy this link: ${share_url}`);
        }
    }

    async function open_shared_trace(trace_id) {
        const existing_trace = list.querySelector(`[data-trace-id="${CSS.escape(trace_id)}"]`);
        let details = existing_trace;

        if (!details) {
            try {
                const url = root.dataset.traceUrlTemplate.replace('__TRACE_ID__', encodeURIComponent(trace_id));
                const trace = await get_json(`${url}?date=${encodeURIComponent(date_select.value)}`);
                details = render_trace(trace);
                list.prepend(details);
            } catch (error) {
                set_status(error.message, 'error');

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
            const trace = await get_json(`${url}?date=${encodeURIComponent(date_select.value)}`);
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

    date_select.onchange = () => { page = 1; selected_date.textContent = format_date(date_select.value); load_traces(); };
    document.getElementById('trace-refresh').onclick = () => { page = 1; load_traces(); };
    tag_input.onkeydown = (event) => {
        if (event.key === 'Enter') { page = 1; load_traces(); }
    };
    load_dates().catch((error) => {
        set_status(error.message, 'error');
        list.append(element('p', 'TraceLog is unavailable right now.', 'empty'));
    });
})();
