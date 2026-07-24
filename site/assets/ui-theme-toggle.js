document.querySelectorAll('[data-vf-theme]').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var container = btn.closest('[data-vf-theme-url]');
        if (!container) return;
        var url = container.dataset.vfThemeUrl;
        var form = new FormData();
        form.append('theme', btn.dataset.vfTheme);
        fetch(url, { method: 'POST', body: form })
            .then(function (response) {
                if (response.ok) location.reload();
            });
    });
});
