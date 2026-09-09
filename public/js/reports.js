(() => {
    'use strict';
    const form = document.getElementById('report-filters');
    if (!form) return;
    const results = document.getElementById('report-results');
    const status = document.getElementById('report-status');
    const errorBox = document.getElementById('report-error');
    const page = form.closest('.report-page');
    const colors = {revenue: '#7652db', expenses: '#eda86a', profit: '#219b8c', previous_revenue: '#aaa2bf', moving_average: '#2f92cb', cumulative: '#7652db', positive: '#219b8c', negative: '#d46666'};
    const labels = {revenue: 'Revenue', expenses: 'Expenses', profit: 'Net cash flow', previous_revenue: 'Previous revenue', moving_average: 'Moving average', cumulative: 'Running net', fluctuation: 'Change'};
    const compact = new Intl.NumberFormat(undefined, {notation: 'compact', maximumFractionDigits: 1});
    const money = new Intl.NumberFormat(undefined, {minimumFractionDigits: 2, maximumFractionDigits: 2});
    let data = JSON.parse(document.getElementById('report-chart-data').textContent);
    let activeRequest;
    let revision = 0;
    let debounce;

    function svgNode(tag, attributes = {}, text = null) {
        const node = document.createElementNS('http://www.w3.org/2000/svg', tag);
        Object.entries(attributes).forEach(([key, value]) => node.setAttribute(key, value));
        if (text !== null) node.textContent = text;
        return node;
    }

    function renderChart(host) {
        host.replaceChildren();
        const type = host.dataset.chart;
        const rows = data.series;
        const keys = type === 'revenue' ? ['revenue', ...(host.dataset.compare === '1' ? ['previous_revenue'] : []), 'moving_average'] : type === 'cashflow' ? ['revenue', 'expenses', 'profit'] : [type === 'cumulative' ? 'cumulative' : 'fluctuation'];
        const legend = document.createElement('div');
        legend.className = 'chart-legend';
        const legendKeys = type === 'fluctuation' ? ['positive', 'negative'] : keys;
        legendKeys.forEach(key => {
            const item = document.createElement('span');
            const marker = document.createElement('i');
            marker.style.background = colors[key];
            if (type === 'fluctuation' || (type === 'cashflow' && key !== 'profit')) marker.className = 'column-key';
            item.append(marker, document.createTextNode(key === 'positive' ? 'Increase' : key === 'negative' ? 'Decrease' : labels[key]));
            legend.append(item);
        });
        const unit = document.createElement('span');
        unit.textContent = host.dataset.currency;
        legend.append(unit);
        host.append(legend);
        const width = Math.max(280, host.clientWidth || 560), height = 285;
        const left = 52, right = width - 14, top = 18, bottom = height - 42;
        const step = (right - left) / Math.max(1, rows.length);
        const x = index => left + step * (index + 0.5);
        const all = rows.flatMap(row => keys.map(key => Number(row[key] || 0)));
        const rawMin = Math.min(0, ...all), rawMax = Math.max(0, ...all);
        const span = rawMax - rawMin || 1;
        const low = rawMin < 0 ? rawMin - span * 0.08 : 0;
        const high = rawMax > 0 ? rawMax + span * 0.1 : (rawMin < 0 ? 0 : 1);
        const y = value => bottom - (Number(value) - low) / (high - low) * (bottom - top);
        const svg = svgNode('svg', {viewBox: `0 0 ${width} ${height}`, role: 'group', 'aria-label': host.closest('section').querySelector('h5').textContent});
        for (let i = 0; i <= 4; i++) {
            const value = low + (high - low) * i / 4;
            svg.append(svgNode('line', {x1: left, x2: right, y1: y(value), y2: y(value), stroke: '#ece9f2', 'stroke-dasharray': '3 5'}));
            svg.append(svgNode('text', {x: left - 9, y: y(value) + 4, 'text-anchor': 'end', fill: '#92889e', 'font-size': 10}, compact.format(value)));
        }
        svg.append(svgNode('line', {x1: left, x2: right, y1: y(0), y2: y(0), stroke: '#bcb4ca', 'stroke-width': 1}));
        const labelEvery = Math.max(1, Math.ceil(rows.length / (width < 400 ? 3 : 5)));
        rows.forEach((row, i) => {
            if (i % labelEvery === 0) svg.append(svgNode('text', {x: x(i), y: height - 14, 'text-anchor': 'middle', fill: '#92889e', 'font-size': 10}, row.from.slice(5)));
        });
        if (type === 'cashflow' || type === 'fluctuation') {
            rows.forEach((row, i) => {
                const columns = type === 'cashflow' ? ['revenue', 'expenses'] : ['fluctuation'];
                const barWidth = Math.min(type === 'cashflow' ? 24 : 36, step * (type === 'cashflow' ? 0.32 : 0.62));
                columns.forEach((key, column) => {
                    if (row[key] === null) return;
                    const value = row[key];
                    const offset = columns.length === 2 ? (column === 0 ? -barWidth - 1 : 1) : -barWidth / 2;
                    svg.append(svgNode('rect', {x: x(i) + offset, y: Math.min(y(value), y(0)), width: barWidth, height: Math.max(0.5, Math.abs(y(value) - y(0))), rx: 2, fill: key === 'fluctuation' ? colors[value >= 0 ? 'positive' : 'negative'] : colors[key], opacity: 0.9}));
                });
            });
        }
        const lines = type === 'cashflow' ? ['profit'] : type === 'fluctuation' ? [] : keys;
        lines.forEach(key => {
            const coordinates = rows.map((row, i) => `${x(i)},${y(row[key])}`);
            if (type === 'cumulative' && coordinates.length) {
                svg.append(svgNode('polygon', {points: `${x(0)},${y(0)} ${coordinates.join(' ')} ${x(rows.length - 1)},${y(0)}`, fill: colors[key], opacity: 0.13}));
            }
            svg.append(svgNode('polyline', {points: coordinates.join(' '), fill: 'none', stroke: colors[key], 'stroke-width': key === 'revenue' || key === 'cumulative' ? 2.6 : 1.8, 'stroke-linejoin': 'round', 'stroke-linecap': 'round', 'stroke-dasharray': key === 'previous_revenue' ? '5 5' : key === 'moving_average' ? '2 4' : 'none'}));
            if (rows.length < 35) rows.forEach((row, i) => svg.append(svgNode('circle', {cx: x(i), cy: y(row[key]), r: rows.length === 1 ? 4 : 2.4, fill: colors[key]})));
        });
        const readout = document.createElement('div');
        readout.className = 'chart-readout';
        readout.textContent = 'Hover, tap or focus a period. Use arrow keys to move between periods.';
        readout.setAttribute('aria-live', 'polite');
        const points = [];
        rows.forEach((row, i) => {
            const values = keys.map(key => `${labels[key]}: ${row[key] === null ? 'No prior interval' : host.dataset.currency + ' ' + money.format(row[key])}`);
            const priorLabel = type === 'revenue' && host.dataset.compare === '1' ? ` · Compared with ${row.previous_from} to ${row.previous_to}` : '';
            const description = `${row.from} to ${row.to} · ${values.join(' · ')}${priorLabel}`;
            const point = svgNode('g', {class: 'chart-point', tabindex: i === 0 ? '0' : '-1', role: 'button', 'aria-label': description});
            point.append(svgNode('rect', {x: x(i) - step / 2, y: top, width: step, height: bottom - top, fill: 'transparent'}));
            point.append(svgNode('line', {class: 'chart-focus', x1: x(i), x2: x(i), y1: top, y2: bottom, stroke: '#9b8fbd', 'stroke-dasharray': '3 3'}));
            point.append(svgNode('title', {}, description));
            ['pointerenter', 'click', 'focus'].forEach(event => point.addEventListener(event, () => { readout.textContent = description; }));
            point.addEventListener('keydown', event => {
                const next = event.key === 'ArrowRight' ? Math.min(rows.length - 1, i + 1) : event.key === 'ArrowLeft' ? Math.max(0, i - 1) : null;
                if (next !== null) {
                    event.preventDefault();
                    point.setAttribute('tabindex', '-1');
                    points[next].setAttribute('tabindex', '0');
                    points[next].focus();
                }
            });
            points.push(point);
            svg.append(point);
        });
        host.append(svg, readout);
        if (!all.some(value => value !== 0)) {
            const empty = document.createElement('p');
            empty.className = 'chart-empty';
            empty.textContent = type === 'fluctuation' ? 'No change between intervals in this range.' : 'No recorded activity for this date range.';
            host.append(empty);
        }
    }

    function render() { results.querySelectorAll('[data-chart]').forEach(renderChart); }
    function parameters() {
        const params = new URLSearchParams(new FormData(form));
        params.set('compare', document.getElementById('report-compare').checked ? '1' : '0');
        return params;
    }
    async function refresh() {
        // Invalidate an earlier request even if the newly edited dates are invalid.
        const ownRevision = ++revision;
        activeRequest?.abort();
        page.removeAttribute('aria-busy');
        if (!form.reportValidity()) { status.textContent = 'Complete the date filters to update the report.'; return; }
        activeRequest = new AbortController();
        const params = parameters();
        const url = new URL(form.action);
        url.search = params.toString();
        errorBox.hidden = true;
        status.textContent = 'Updating charts, comparisons and breakdowns…';
        page.setAttribute('aria-busy', 'true');
        try {
            const response = await fetch(url, {headers: {'Accept': 'application/json'}, signal: activeRequest.signal});
            const payload = await response.json();
            if (!response.ok) {
                const errors = Object.values(payload.errors || {}).flat();
                throw new Error(errors[0] || 'Unable to load reports. Check your session and try again.');
            }
            if (ownRevision !== revision) return;
            if (typeof payload.html !== 'string' || !Array.isArray(payload.charts?.series)) throw new Error('The report response was incomplete. Please retry.');
            // HTML is the authenticated, escaped Blade partial from this same origin.
            results.innerHTML = payload.html;
            data = payload.charts;
            render();
            ['report-csv', 'report-pdf'].forEach(id => {
                const link = document.getElementById(id);
                const exportUrl = new URL(link.href);
                exportUrl.search = params.toString();
                link.href = exportUrl.href;
            });
            history.replaceState(null, '', url);
            status.textContent = `Updated: ${params.get('from')} to ${params.get('to')} · ${data.interval} intervals.`;
        } catch (error) {
            if (error.name === 'AbortError' || ownRevision !== revision) return;
            errorBox.textContent = error instanceof SyntaxError ? 'Unable to load reports. Refresh the page to check your session.' : error.message;
            errorBox.hidden = false;
            status.textContent = 'Charts still show the last successfully loaded date range.';
        } finally {
            if (ownRevision === revision) page.removeAttribute('aria-busy');
        }
    }
    form.addEventListener('submit', event => { event.preventDefault(); clearTimeout(debounce); refresh(); });
    form.addEventListener('change', () => { clearTimeout(debounce); debounce = setTimeout(refresh, 180); });
    const formatDate = date => `${date.getFullYear()}-${String(date.getMonth() + 1).padStart(2, '0')}-${String(date.getDate()).padStart(2, '0')}`;
    form.querySelectorAll('[data-range]').forEach(button => button.addEventListener('click', () => {
        const end = form.dataset.today ? new Date(form.dataset.today + 'T12:00:00') : new Date();
        const start = new Date(end);
        if (button.dataset.range === 'year') start.setMonth(0, 1);
        else if (button.dataset.range === 'month') start.setDate(1);
        else start.setDate(start.getDate() - Number(button.dataset.range) + 1);
        form.elements.from.value = formatDate(start);
        form.elements.to.value = formatDate(end);
        clearTimeout(debounce);
        refresh();
    }));
    render();
    let resizeTimer;
    window.addEventListener('resize', () => { clearTimeout(resizeTimer); resizeTimer = setTimeout(render, 120); });
})();
