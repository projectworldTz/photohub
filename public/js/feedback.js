(() => {
    'use strict';
    const region = document.createElement('div');
    region.className = 'pf-toasts';
    region.setAttribute('aria-label', 'Notifications');
    document.body.appendChild(region);
    function toast(message, type = 'success') {
        const el = document.createElement('div');
        el.className = 'pf-toast';
        const icon = document.createElement('span');
        icon.className = 'pf-toast-icon';
        icon.setAttribute('aria-hidden', 'true');
        const text = document.createElement('p');
        text.className = 'pf-toast-message';
        const close = document.createElement('button');
        close.className = 'pf-toast-close'; close.type = 'button'; close.textContent = '×';
        close.setAttribute('aria-label', 'Dismiss notification');
        el.append(icon, text, close);
        region.appendChild(el);
        let timer;
        const dismiss = () => { clearTimeout(timer); el.classList.add('is-leaving'); setTimeout(() => el.remove(), 220); };
        close.addEventListener('click', dismiss);
        function update(next, nextType = type) {
            type = nextType; clearTimeout(timer);
            el.dataset.type = type;
            el.setAttribute('role', type === 'error' ? 'alert' : 'status');
            el.setAttribute('aria-live', type === 'error' ? 'assertive' : 'polite');
            el.setAttribute('aria-atomic', 'true');
            icon.replaceChildren();
            if (type === 'loading') { const spinner = document.createElement('span'); spinner.className = 'pf-spinner'; icon.appendChild(spinner); }
            else icon.textContent = type === 'error' ? '!' : '✓';
            text.textContent = next;
            if (type !== 'loading') timer = setTimeout(dismiss, type === 'error' ? 9000 : 5000);
        }
        update(message, type);
        return {update, dismiss};
    }
    function busy(button, label) {
        if (!button) return () => {};
        const html = button.innerHTML, disabled = button.disabled, aria = button.getAttribute('aria-disabled');
        button.disabled = true; button.setAttribute('aria-disabled', 'true'); button.setAttribute('aria-busy', 'true');
        button.replaceChildren();
        const spinner = document.createElement('span'); spinner.className = 'pf-spinner'; spinner.setAttribute('aria-hidden', 'true');
        button.append(spinner, document.createTextNode(label));
        return () => {
            button.innerHTML = html; button.disabled = disabled; button.removeAttribute('aria-busy');
            if (aria === null) button.removeAttribute('aria-disabled'); else button.setAttribute('aria-disabled', aria);
        };
    }
    function progress(container) {
        const track = document.createElement('div'), fill = document.createElement('div');
        track.className = 'pf-progress is-indeterminate'; fill.className = 'pf-progress-fill';
        track.setAttribute('role', 'progressbar'); track.setAttribute('aria-label', 'Action progress');
        track.appendChild(fill); container.appendChild(track);
        return {set(value) {
            const known = Number.isFinite(value);
            track.classList.toggle('is-indeterminate', !known);
            if (known) { const n = Math.max(0, Math.min(100, value)); fill.style.width = `${n}%`; track.setAttribute('aria-valuenow', String(Math.round(n))); track.setAttribute('aria-valuemin', '0'); track.setAttribute('aria-valuemax', '100'); }
            else track.removeAttribute('aria-valuenow');
        }, remove() {track.remove();}};
    }
    function remember(message, type = 'success') {
        try {sessionStorage.setItem('photohub-feedback', JSON.stringify({message, type, time: Date.now()}));} catch (_) {}
    }
    async function errorMessage(response, fallback) {
        try { const json = await response.json(); const first = Object.values(json.errors || {})[0]; return Array.isArray(first) ? first[0] : json.message || fallback; } catch (_) { return fallback; }
    }
    const headers = () => ({Accept: 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]')?.content || ''});
    async function copy(button, value, fallback) {
        if (button.dataset.copyBusy) return;
        button.dataset.copyBusy = 'true';
        const restore = busy(button, 'Copying...');
        try {
            await navigator.clipboard.writeText(value);
            button.textContent = 'Copied ✓'; toast('Link copied.');
            setTimeout(() => {restore(); delete button.dataset.copyBusy;}, 2000);
        } catch (_) {
            restore(); delete button.dataset.copyBusy;
            if (fallback) {
                fallback.closest('details')?.setAttribute('open', '');
                fallback.hidden = false; fallback.value = value; fallback.focus(); fallback.select();
            }
            toast('Automatic copying is unavailable. Select and copy the highlighted text.', 'error');
        }
    }
    window.PhotoHubFeedback = {toast, busy, progress, remember, errorMessage, headers, copy};

    let remembered = false;
    try {
        const item = JSON.parse(sessionStorage.getItem('photohub-feedback') || 'null'); sessionStorage.removeItem('photohub-feedback');
        if (item && Date.now() - item.time < 30000) {toast(item.message, item.type); remembered = true;}
    } catch (_) {}
    if (!remembered) document.querySelectorAll('[data-feedback-flash]').forEach(el => toast(el.dataset.feedbackMessage, el.dataset.feedbackFlash));

    // Ordinary POST actions retain their existing redirects and validation behavior.
    document.addEventListener('submit', async event => {
        const form = event.target.closest('[data-feedback-form]');
        if (!form || event.defaultPrevented) return;
        event.preventDefault();
        if (form.dataset.feedbackBusy) return;
        form.dataset.feedbackBusy = 'true'; form.setAttribute('aria-busy', 'true');
        const body = new FormData(form);
        if (event.submitter?.name) body.append(event.submitter.name, event.submitter.value);
        const controls = [...form.querySelectorAll('button')];
        if (form.hasAttribute('data-selection-submit')) controls.push(...document.querySelectorAll('.proof button,.proof-action,.action'));
        const states = controls.map(el => [el, el.disabled]);
        const restore = busy(event.submitter || form.querySelector('button[type="submit"],button:not([type])'), form.dataset.loading || 'Processing...');
        controls.forEach(el => el.disabled = true);
        const note = toast(form.dataset.loading || 'Processing...', 'loading');
        const bar = progress(form);
        const fallback = form.dataset.error || 'Could not complete this action. Please retry.';
        try {
            const response = await fetch(form.action, {method: 'POST', body, headers: headers()});
            if (!response.ok) throw new Error(await errorMessage(response, fallback));
            if (!response.headers.get('content-type')?.includes('text/html')) throw new Error(fallback);
            const doc = new DOMParser().parseFromString(await response.text(), 'text/html');
            const failure = doc.querySelector('[data-feedback-flash="error"]');
            if (failure) throw new Error(failure.dataset.feedbackMessage || fallback);
            const success = doc.querySelector('[data-feedback-flash="success"]');
            if (!success) throw new Error(fallback);
            const message = form.dataset.success || success.dataset.feedbackMessage;
            note.update(message, 'success'); remember(message);
            window.location.assign(response.url);
        } catch (error) { note.update(error instanceof TypeError ? fallback : error.message, 'error'); }
        finally {restore(); states.forEach(([el, disabled]) => el.disabled = disabled); bar.remove(); delete form.dataset.feedbackBusy; form.removeAttribute('aria-busy');}
    });

    document.addEventListener('click', event => {
        const button = event.target.closest('[data-copy-cloud-link], [data-copy-invitation], [data-copy-value]');
        if (!button) return;
        const field = button.dataset.copyInvitation ? document.getElementById(button.dataset.copyInvitation) : button.closest('[data-online-sharing]')?.querySelector('[data-cloud-copy-fallback]');
        copy(button, button.dataset.copyCloudLink ?? button.dataset.copyValue ?? field?.value ?? '', field);
    });

    // One active download preparation per page, including ZIP and single images.
    let downloadBusy = false;
    async function download(url, method, body, button) {
        if (downloadBusy) return;
        downloadBusy = true;
        const controls = [...document.querySelectorAll('[data-download-form] button,[data-download-form] input[type="checkbox"],a[data-download-link]')];
        const states = controls.map(el => [el, el.disabled, el.getAttribute('aria-disabled')]);
        const restore = busy(button, 'Preparing download...');
        controls.forEach(el => {el.disabled = true; el.setAttribute('aria-disabled', 'true');});
        const note = toast('Preparing download...', 'loading');
        const bar = progress(noteElement());
        try {
            const response = await fetch(url, {method, body, headers: headers()});
            if (!response.ok) throw new Error(await errorMessage(response, 'Could not prepare your download. Please try again.'));
            const disposition = response.headers.get('content-disposition') || '';
            if (!/attachment/i.test(disposition)) throw new Error('Could not prepare your download. Please refresh the gallery and try again.');
            const blob = await response.blob();
            const target = URL.createObjectURL(blob), anchor = document.createElement('a');
            const utf = disposition.match(/filename\*=UTF-8''([^;]+)/i), plain = disposition.match(/filename="?([^";]+)"?/i);
            let filename = plain?.[1] || 'photos.zip';
            if (utf) {try {filename = decodeURIComponent(utf[1]);} catch (_) {}}
            anchor.href = target; anchor.download = filename; document.body.appendChild(anchor); anchor.click(); anchor.remove();
            setTimeout(() => URL.revokeObjectURL(target), 60000);
            note.update('Your download is ready. Check your browser downloads.', 'success');
        } catch (error) {note.update(error instanceof TypeError ? 'Could not prepare your download. Check your connection and try again.' : error.message, 'error');}
        finally {
            restore(); states.forEach(([el, disabled, aria]) => {el.disabled = disabled; if (aria === null) el.removeAttribute('aria-disabled'); else el.setAttribute('aria-disabled', aria);});
            bar.remove(); downloadBusy = false;
        }
    }
    function noteElement() {return region.lastElementChild || region;}
    document.addEventListener('submit', event => {
        const form = event.target.closest('[data-download-form]');
        if (!form || event.defaultPrevented) return;
        event.preventDefault();
        if (form.dataset.requireSelection !== undefined && !form.querySelector('input[type="checkbox"]:checked') && !form.querySelector('input[name="photos[]"]')) {toast('Select at least one photo to download.', 'error'); return;}
        download(form.action, 'POST', new FormData(form), event.submitter);
    });
    document.addEventListener('click', event => {
        const anchor = event.target.closest('a[data-download-link]');
        if (!anchor || event.ctrlKey || event.metaKey || event.shiftKey || event.altKey) return;
        event.preventDefault(); download(anchor.href, 'GET', undefined, anchor);
    });

    // Individual skeletons remain for lazy images; the page becomes ready after
    // the initial visible collection settles, without waiting for offscreen images.
    const images = [...document.querySelectorAll('[data-gallery-image]')];
    const galleryStatus = document.querySelector('[data-gallery-loading]');
    const initial = images.slice(0, 4); let remaining = initial.length, broken = 0;
    function ready() {
        if (remaining || !galleryStatus) return;
        galleryStatus.textContent = broken ? 'Some previews could not load. Refresh the page to try again.'
            : images.length ? (galleryStatus.dataset.ready || 'Your gallery is ready.') : 'No photos are available yet.';
        galleryStatus.removeAttribute('aria-busy');
    }
    images.forEach(img => {
        const isInitial = initial.includes(img);
        if (isInitial) img.loading = 'eager';
        let settled = false;
        const finish = failed => {
            if (settled) return; settled = true;
            img.classList.remove('pf-image-loading'); img.classList.toggle('pf-image-failed', failed); img.removeAttribute('aria-busy');
            if (failed) img.alt = `${img.alt || 'Photo'} — preview unavailable`;
            if (isInitial) {remaining--; if (failed) broken++; ready();}
        };
        img.classList.add('pf-image-loading'); img.setAttribute('aria-busy', 'true');
        img.addEventListener('load', () => finish(false), {once:true}); img.addEventListener('error', () => finish(true), {once:true});
        if (img.complete) finish(!img.naturalWidth);
    });
    ready();
})();
