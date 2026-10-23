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

// Mobile sidebar drawer.
const sidebar = document.querySelector('[data-sidebar]');
const backdrop = document.querySelector('[data-sidebar-backdrop]');
const setSidebar = (open) => {
    sidebar?.classList.toggle('-translate-x-full', !open);
    backdrop?.classList.toggle('hidden', !open);
};
document.querySelector('[data-sidebar-open]')?.addEventListener('click', () => setSidebar(true));
document.querySelector('[data-sidebar-close]')?.addEventListener('click', () => setSidebar(false));
backdrop?.addEventListener('click', () => setSidebar(false));

// Light / dark theme, remembered per browser (see components/theme-script).
document.addEventListener('click', (event) => {
    if (!event.target.closest('[data-theme-toggle]')) return;
    const dark = !document.documentElement.classList.contains('dark');
    document.documentElement.classList.toggle('dark', dark);
    try {
        localStorage.setItem('reelflow-theme', dark ? 'dark' : 'light');
    } catch (e) {
        // Private mode: the choice simply is not remembered.
    }
});
