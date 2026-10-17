function register() {
    window.Alpine.data('operationalChart', (settings) => {
        let chart;
        let disposed = false;
        return {
            unavailable: false,
            async init() {
                try {
                    const { Chart, LineController, BarController, LineElement, BarElement, PointElement, CategoryScale, LinearScale, Tooltip, Legend } = await import('chart.js');
                    if (disposed || !this.$el.isConnected) return;
                    Chart.register(LineController, BarController, LineElement, BarElement, PointElement, CategoryScale, LinearScale, Tooltip, Legend);
                    chart = new Chart(this.$refs.canvas, {
                        type: settings.type,
                        data: settings.data,
                        options: {
                            responsive: true,
                            maintainAspectRatio: false,
                            animation: false,
                            locale: 'en-US',
                            indexAxis: settings.horizontal ? 'y' : 'x',
                            plugins: {
                                legend: { display: settings.data.datasets.length > 1, rtl: document.documentElement.dir === 'rtl', labels: { font: { family: 'Tahoma, Arial, sans-serif' }, usePointStyle: true } },
                                tooltip: { rtl: document.documentElement.dir === 'rtl', textDirection: document.documentElement.dir },
                            },
                            scales: {
                                x: { ...(settings.horizontal ? { beginAtZero: true, ticks: { precision: 0 } } : {}), grid: { display: false } },
                                y: { ...(settings.horizontal ? { grid: { display: false } } : { beginAtZero: true, ticks: { precision: 0 } }) },
                            },
                        },
                    });
                } catch {
                    this.unavailable = true;
                }
            },
            destroy() {
                disposed = true;
                chart?.destroy();
            },
        };
    });
}

if (window.Alpine) register();
else document.addEventListener('alpine:init', register, { once: true });
