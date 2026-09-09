// Small sequential requests keep large imports below PHP's max_file_uploads limit.
document.querySelectorAll('form[data-photo-upload]').forEach(form => {
    const input = form.querySelector('input[type=file]');
    const button = form.querySelector('button[type=submit],button:not([type])');
    const status = document.createElement('p');
    status.className = 'small mt-2';
    status.setAttribute('role', 'status');
    form.appendChild(status);
    const zone = form.querySelector('#drop-zone');
    if (zone) {
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(name => zone.addEventListener(name, event => event.preventDefault()));
        zone.addEventListener('drop', event => { input.files = event.dataTransfer.files; input.dispatchEvent(new Event('change')); });
    }
    let offset = 0;
    input.addEventListener('change', () => { offset = 0; status.textContent = ''; });
    form.addEventListener('submit', async event => {
        event.preventDefault();
        const files = [...input.files];
        if (!files.length) return;
        button.disabled = true;
        input.disabled = true;
        try {
            while (offset < files.length) {
                const data = new FormData(form);
                data.delete(input.name);
                // Also cap bytes per request for ordinary XAMPP post_max_size settings.
                let end = offset, bytes = 0;
                while (end < files.length && end - offset < 5) {
                    if (end > offset && bytes + files[end].size > 16 * 1024 * 1024) break;
                    bytes += files[end].size;
                    data.append(input.name, files[end]);
                    end++;
                }
                status.textContent = `Importing ${offset + 1}–${end} of ${files.length} photos…`;
                const response = await fetch(form.action, {method: 'POST', body: data, headers: {'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest'}});
                if (!response.ok) {
                    throw new Error(`Import paused after ${offset} photos. Check file size/type and available disk space, then retry the remaining batch.`);
                }
                const result = await response.json();
                if (result.uploaded !== end - offset) { throw new Error('The import response was incomplete. Check the gallery before retrying.'); }
                offset = end;
            }
            location.reload();
        } catch (error) {
            status.textContent = error.message || 'Connection interrupted. Check the gallery before retrying to avoid duplicate imports.';
        } finally {
            button.disabled = false;
            input.disabled = false;
        }
    });
});
