// Chunked browser uploads for the Upload page (ZIP or video files).
// Each file is its own upload: create → PUT chunks (with retries) → complete
// → poll until the server has unpacked it.

const csrf = () => document.querySelector('meta[name="csrf-token"]')?.content ?? '';
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

async function request(url, options = {}) {
    const response = await fetch(url, {
        credentials: 'same-origin',
        ...options,
        headers: { Accept: 'application/json', 'X-CSRF-TOKEN': csrf(), ...(options.headers ?? {}) },
    });
    const data = await response.json().catch(() => ({}));
    if (!response.ok) {
        const error = new Error(data.message ?? `Server error (${response.status})`);
        error.status = response.status;
        throw error;
    }
    return data;
}

function formatSize(bytes) {
    return bytes >= 1048576 ? `${(bytes / 1048576).toFixed(1)} MB` : `${Math.max(1, Math.round(bytes / 1024))} KB`;
}

function escapeHtml(text) {
    return String(text ?? '').replace(/[&<>"']/g, (c) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c]);
}

const STATUS_STYLE = {
    added: 'bg-emerald-50 text-emerald-700',
    duplicate: 'bg-slate-100 text-slate-600',
    skipped: 'bg-amber-50 text-amber-700',
    quota: 'bg-red-50 text-red-700',
};

function renderItem(file) {
    const li = document.createElement('li');
    li.className = 'card card-body';
    li.innerHTML = `
        <div class="flex items-center justify-between gap-3">
            <div class="min-w-0"><div class="truncate font-medium">${escapeHtml(file.name)}</div><div class="text-xs text-slate-500">${formatSize(file.size)}</div></div>
            <span data-state class="shrink-0 text-sm text-slate-500">Waiting…</span>
        </div>
        <div class="mt-3 h-2 overflow-hidden rounded-full bg-slate-100"><div data-bar class="h-2 rounded-full bg-indigo-500 transition-all" style="width:0%"></div></div>
        <div data-results class="mt-3 hidden space-y-1 text-sm"></div>`;
    return li;
}

function showResults(li, batch) {
    const box = li.querySelector('[data-results]');
    const rows = batch.results ?? [];
    box.innerHTML = rows.map((row) => `
        <div class="flex items-start justify-between gap-3">
            <span class="truncate">${escapeHtml(row.name)}</span>
            <span class="shrink-0 rounded px-1.5 py-0.5 text-xs font-medium ${STATUS_STYLE[row.status] ?? 'bg-slate-100'}">${escapeHtml(row.status)}</span>
        </div>${row.message && row.status !== 'added' ? `<div class="text-xs text-slate-500">${escapeHtml(row.message)}</div>` : ''}`).join('');
    box.classList.toggle('hidden', rows.length === 0);
}

async function uploadFile(root, file, li) {
    const state = li.querySelector('[data-state]');
    const bar = li.querySelector('[data-bar]');
    const setState = (text, tone = 'text-slate-500') => {
        state.className = `shrink-0 text-sm ${tone}`;
        state.textContent = text;
    };

    try {
        setState('Starting…');
        const batch = await request(root.dataset.storeUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ name: file.name, size: file.size }),
        });
        const base = `${root.dataset.storeUrl}/${batch.id}`;
        const done = new Set(batch.received);

        for (let index = 0; index < batch.total_chunks; index++) {
            if (done.has(index)) continue;
            const chunk = file.slice(index * batch.chunk_size, Math.min(file.size, (index + 1) * batch.chunk_size));
            for (let attempt = 1; ; attempt++) {
                try {
                    await request(`${base}/chunks/${index}`, { method: 'PUT', headers: { 'Content-Type': 'application/octet-stream' }, body: chunk });
                    break;
                } catch (error) {
                    if (attempt >= 5 || (error.status && error.status < 500 && error.status !== 429)) throw error;
                    setState(`Connection problem, retrying (${attempt})…`, 'text-amber-600');
                    await sleep(1500 * attempt);
                }
            }
            done.add(index);
            const percent = Math.round((done.size / batch.total_chunks) * 100);
            bar.style.width = `${percent}%`;
            setState(`Uploading ${percent}%`);
        }

        let status = await request(`${base}/complete`, { method: 'POST' });
        bar.classList.replace('bg-indigo-500', 'bg-violet-500');
        for (let polls = 0; status.status === 'processing' && polls < 600; polls++) {
            setState(file.name.toLowerCase().endsWith('.zip') ? 'Unpacking…' : 'Processing…');
            await sleep(2000);
            status = await request(base);
        }

        if (status.status === 'completed') {
            bar.classList.replace('bg-violet-500', 'bg-emerald-500');
            setState(`${status.added} added`, 'text-emerald-700');
        } else if (status.status === 'processing') {
            setState('Still processing; check Recent uploads later', 'text-amber-600');
        } else {
            throw new Error(status.error ?? 'Upload failed.');
        }
        showResults(li, status);
    } catch (error) {
        bar.classList.add('bg-red-500');
        setState(error.message, 'text-red-600');
    }
}

function validate(root, files) {
    const maxBytes = Number(root.dataset.maxBytes);
    const maxVideos = Number(root.dataset.maxVideos);
    const extensions = root.dataset.extensions.split(',');
    const extension = (file) => file.name.split('.').pop().toLowerCase();

    const invalid = files.filter((file) => extension(file) !== 'zip' && !extensions.includes(extension(file)));
    if (invalid.length) return `Not a ZIP or video: ${invalid.map((f) => f.name).join(', ')}`;
    const total = files.reduce((sum, file) => sum + file.size, 0);
    if (total > maxBytes) return `That is ${formatSize(total)}; upload at most ${formatSize(maxBytes)} at a time.`;
    const videos = files.filter((file) => extension(file) !== 'zip').length;
    if (videos > maxVideos) return `Choose at most ${maxVideos} videos at a time (or put them in a ZIP).`;
    return null;
}

export function initUploader(root) {
    const input = root.querySelector('input[type=file]');
    const zone = root.querySelector('[data-dropzone]');
    const list = root.querySelector('[data-upload-list]');
    const errorBox = root.querySelector('[data-upload-error]');
    let queue = Promise.resolve();

    const start = (fileList) => {
        const files = Array.from(fileList ?? []);
        if (!files.length) return;
        const problem = validate(root, files);
        errorBox.classList.toggle('hidden', !problem);
        errorBox.textContent = problem ?? '';
        if (problem) return;

        for (const file of files) {
            const li = renderItem(file);
            list.prepend(li);
            queue = queue.then(() => uploadFile(root, file, li)); // one upload at a time
        }
        input.value = '';
    };

    input.addEventListener('change', () => start(input.files));
    ['dragenter', 'dragover'].forEach((type) => zone.addEventListener(type, (event) => {
        event.preventDefault();
        zone.classList.add('border-indigo-500', 'bg-indigo-50');
    }));
    ['dragleave', 'drop'].forEach((type) => zone.addEventListener(type, (event) => {
        event.preventDefault();
        zone.classList.remove('border-indigo-500', 'bg-indigo-50');
    }));
    zone.addEventListener('drop', (event) => start(event.dataTransfer?.files));

    window.addEventListener('beforeunload', (event) => {
        if (list.querySelector('[data-state]:not(.text-emerald-700):not(.text-red-600)')) {
            event.preventDefault();
        }
    });
}
