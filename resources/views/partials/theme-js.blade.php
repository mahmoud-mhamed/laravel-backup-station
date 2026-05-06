(function () {
    const stored = localStorage.getItem('backup-station-theme');
    if (stored) document.documentElement.setAttribute('data-theme', stored);
    window.toggleTheme = function () {
        const cur = document.documentElement.getAttribute('data-theme') === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', cur);
        localStorage.setItem('backup-station-theme', cur);
        const i = document.getElementById('theme-icon');
        if (i) i.textContent = cur === 'dark' ? '☀' : '☾';
    };
    document.addEventListener('DOMContentLoaded', () => {
        const i = document.getElementById('theme-icon');
        if (i) i.textContent = document.documentElement.getAttribute('data-theme') === 'dark' ? '☀' : '☾';
    });
})();
