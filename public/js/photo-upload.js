// Sequential local imports keep the existing file/byte batch limits.
document.querySelectorAll('form[data-photo-upload]').forEach(form => {
    const input = form.querySelector('input[type=file]');
    const button = form.querySelector('button[type=submit],button:not([type])');
    const status = document.createElement('p');
    status.className = 'small mt-2'; status.setAttribute('role', 'status'); form.appendChild(status);
    const zone = form.querySelector('#drop-zone');
    if (zone) {
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(name => zone.addEventListener(name, event => event.preventDefault()));
        zone.addEventListener('drop', event => { if (input.disabled) return; input.files = event.dataTransfer.files; input.dispatchEvent(new Event('change')); });
    }
    let offset = 0, processing = false;
    input.addEventListener('change', () => { offset = 0; status.textContent = ''; });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        if (processing) return;
        const files = [...input.files]; if (!files.length) return;
        processing = true;
        const ui = window.PhotoHubFeedback, base = new FormData(form); base.delete(input.name);
        const states = [...form.querySelectorAll('button,input,select,textarea')].map(el => [el, el.disabled]);
        const restore = ui.busy(button, 'Uploading photos...');
        states.forEach(([el]) => el.disabled = true); form.setAttribute('aria-busy', 'true');
        const note = ui.toast('Uploading photos...', 'loading'), bar = ui.progress(form);
        const totalBytes = files.reduce((sum, file) => sum + file.size, 0);
        try {
            while (offset < files.length) {
                const data = new FormData(); base.forEach((value, name) => data.append(name, value));
                let end = offset, bytes = 0;
                while (end < files.length && end - offset < 5) {
                    if (end > offset && bytes + files[end].size > 16 * 1024 * 1024) break;
                    bytes += files[end].size; data.append(input.name, files[end]); end++;
                }
                const completed = files.slice(0, offset).reduce((sum, file) => sum + file.size, 0);
                const message = `Uploading photos ${offset + 1}-${end} of ${files.length}...`;
                status.textContent = message; note.update(message, 'loading');
                await new Promise((resolve, reject) => {
                    const xhr = new XMLHttpRequest(); xhr.open('POST', form.action);
                    xhr.setRequestHeader('Accept', 'application/json'); xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
                    xhr.upload.onprogress = event => {
                        if (!event.lengthComputable || !totalBytes) {bar.set(null); return;}
                        const percentage = Math.floor((completed + bytes * event.loaded / event.total) / totalBytes * 100);
                        bar.set(percentage); status.textContent = `${message} ${percentage}% transferred`;
                    };
                    xhr.upload.onload = () => {bar.set(null); status.textContent = 'Processing your photos...'; note.update(status.textContent, 'loading');};
                    xhr.onload = () => {
                        let result; try {result = JSON.parse(xhr.responseText);} catch (_) {}
                        if (xhr.status < 200 || xhr.status >= 300 || result?.uploaded !== end - offset) {
                            reject(new Error(`Import paused after ${offset} photos. Check file size/type and available disk space, then retry the remaining batch.`));
                        } else resolve();
                    };
                    xhr.onerror = xhr.onabort = () => reject(new Error('Connection interrupted. Check the gallery before retrying to avoid duplicate imports.'));
                    xhr.send(data);
                });
                offset = end;
            }
            note.update('Upload completed.', 'success'); ui.remember('Upload completed.'); location.reload();
        } catch (error) {status.textContent = error.message; note.update(error.message, 'error');}
        finally {restore(); states.forEach(([el, disabled]) => el.disabled = disabled); bar.remove(); processing = false; form.removeAttribute('aria-busy');}
    });
});
