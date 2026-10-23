{{-- Applies the saved theme (or the system one) before first paint. --}}
<script>
    (() => {
        let theme = null;
        try { theme = localStorage.getItem('reelflow-theme'); } catch (e) {}
        const dark = theme ? theme === 'dark' : window.matchMedia('(prefers-color-scheme: dark)').matches;
        document.documentElement.classList.toggle('dark', dark);
    })();
</script>
<link rel="preconnect" href="https://fonts.bunny.net">
<link href="https://fonts.bunny.net/css?family=inter:400,500,600,700&display=swap" rel="stylesheet">
