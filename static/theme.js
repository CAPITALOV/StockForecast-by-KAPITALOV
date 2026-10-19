(function () {
    const toggle = document.getElementById('theme-toggle');
    if (!toggle) return;

    const saved = localStorage.getItem('theme') || 'dark';
    document.documentElement.setAttribute('data-theme', saved);

    function updateCharts(isDark) {
        const bg = isDark ? '#131722' : '#ffffff';
        const text = isDark ? '#d1d4dc' : '#1a1a1a';
        const grid = isDark ? '#1e222d' : '#e0e0e0';
        const border = isDark ? '#2a2e39' : '#c0c0c0';

        const opts = {
            layout: { background: { color: bg }, textColor: text },
            grid: { vertLines: { color: grid }, horzLines: { color: grid } },
            timeScale: { borderColor: border },
            rightPriceScale: { borderColor: border },
            leftPriceScale: { borderColor: border },
        };

        if (window.chart && typeof window.chart.applyOptions === 'function') {
            window.chart.applyOptions(opts);
        }
        if (window.rsiChart && typeof window.rsiChart.applyOptions === 'function') {
            window.rsiChart.applyOptions(opts);
        }
        if (window.compareForecastsChart && typeof window.compareForecastsChart.applyOptions === 'function') {
            window.compareForecastsChart.applyOptions(opts);
        }
    }

    // Применяем цвета графиков при загрузке
    updateCharts(saved === 'dark');

    toggle.addEventListener('click', () => {
        const current = document.documentElement.getAttribute('data-theme');
        const next = current === 'dark' ? 'light' : 'dark';
        document.documentElement.setAttribute('data-theme', next);
        localStorage.setItem('theme', next);
        updateCharts(next === 'dark');
    });
})();