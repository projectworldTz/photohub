document.addEventListener('submit', async event => {
    const form = event.target.closest('[data-sharing-form]');
    if (!form) return;
    event.preventDefault();
    const panel = form.closest('[data-online-sharing]');
    if (panel.dataset.busy === 'true') return;
    if (form.dataset.confirm && !confirm(form.dataset.confirm)) return;
    const ui = window.PhotoHubFeedback;
    const action = form.action.split('/').pop();
    const label = {previews: 'Uploading previews...', selections: 'Checking client selections...', finals: 'Uploading finished photos...'}[action] || 'Removing online gallery...';
    const originalStates = [];
    const active = event.submitter || form.querySelector('button');
    const status = panel.querySelector('[data-sharing-status]');
    const link = panel.querySelector('[data-sharing-result]');
    const buttons = [...panel.querySelectorAll('[data-sharing-form] button')];
    panel.dataset.busy = 'true';
    buttons.forEach(button => { originalStates.push([button, button.disabled]); button.disabled = true; });
    const initialLabel = panel.dataset.studioReady === 'false' ? 'Registering studio and preparing gallery...' : label;
    const restore = ui.busy(active, initialLabel);
    const note = ui.toast(initialLabel, 'loading');
    const bar = ui.progress(panel);
    panel.setAttribute('aria-busy', 'true');
    link.hidden = true;
    status.textContent = initialLabel;
    let url = form.action;
    let body = new FormData(form);
    try {
        while (true) {
            const response = await fetch(url, {
                method: 'POST', body,
                headers: {Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content}
            });
            if (!response.headers.get('content-type')?.includes('application/json')) {
                throw new Error('The sharing request was interrupted. Refresh the page and choose the action again to resume.');
            }
            const result = await response.json();
            if (!response.ok) {
                const first = Object.values(result.errors || {})[0];
                throw new Error(Array.isArray(first) ? first[0] : result.message || 'Unable to complete sharing. Your local work is safe.');
            }
            let message = result.message;
            if (['queued', 'running'].includes(result.status)) {
                if (result.total > 0 && result.uploaded < result.total) {
                    const percent = Math.floor(result.uploaded / result.total * 100);
                    bar.set(percent); message = `${label} ${percent}% (${result.uploaded} of ${result.total} photos uploaded)`;
                } else {
                    bar.set(null); message = action === 'finals' ? 'Preparing download link...' : action === 'previews' ? 'Creating customer link...' : label;
                }
            }
            status.textContent = message;
            note.update(message, result.status === 'failed' || result.status === 'cancelled' ? 'error' : result.status === 'completed' ? 'success' : 'loading');
            if (result.url) {
                link.href = result.url;
                link.hidden = false;
            }
            if (!['queued', 'running'].includes(result.status)) {
                if (result.status === 'completed') { ui.remember(result.message); window.location.reload(); }
                break;
            }
            url = result.continue_url;
            body = new FormData();
            await new Promise(resolve => setTimeout(resolve, 500));
        }
    } catch (error) {
        status.textContent = error instanceof TypeError
            ? 'The connection was interrupted. Your local work is safe. Choose this sharing action again to resume.'
            : error.message;
        note.update(status.textContent, 'error');
    } finally {
        panel.dataset.busy = 'false';
        restore();
        originalStates.forEach(([button, disabled]) => button.disabled = disabled);
        bar.remove(); panel.removeAttribute('aria-busy');
    }
});
