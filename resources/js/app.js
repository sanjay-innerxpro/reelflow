import './bootstrap';

// Confirmation for destructive actions: <form data-confirm="...">
document.addEventListener('submit', (event) => {
    const message = event.target.dataset.confirm;
    if (message && !window.confirm(message)) {
        event.preventDefault();
    }
});

// Bulk selection in the video library.
document.addEventListener('change', (event) => {
    if (event.target.matches('[data-select-all]')) {
        document.querySelectorAll('[data-select-item]').forEach((box) => {
            box.checked = event.target.checked;
        });
    }
    const count = document.querySelectorAll('[data-select-item]:checked').length;
    document.querySelectorAll('[data-selected-count]').forEach((el) => {
        el.textContent = count;
    });
});

// Copy-to-clipboard buttons: <button data-copy="#target">
document.addEventListener('click', (event) => {
    const button = event.target.closest('[data-copy]');
    if (!button) return;
    const target = document.querySelector(button.dataset.copy);
    if (target && navigator.clipboard) {
        navigator.clipboard.writeText(target.textContent.trim());
        button.textContent = 'Copied';
    }
});

// Upload page (ZIPs and videos in chunks).
const uploader = document.querySelector('[data-uploader]');
if (uploader) {
    import('./uploader.js').then(({ initUploader }) => initUploader(uploader));
}
