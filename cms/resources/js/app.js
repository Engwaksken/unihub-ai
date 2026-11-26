// Alpine.js and Livewire are provided by @livewireScripts.
//
// Charts: Chart.js is bundled by Vite (self-hosted, no CDN). Any
// <canvas data-chart='{"type":..,"labels":[..],"datasets":[..]}'> is rendered.
// Every chart on the portal also has an adjacent data table, so the canvas is
// a visual summary and never the only way to read the numbers.
import {
    Chart,
    BarController,
    BarElement,
    CategoryScale,
    Legend,
    LinearScale,
    LineController,
    LineElement,
    PointElement,
    Tooltip,
} from 'chart.js';

Chart.register(BarController, BarElement, CategoryScale, Legend, LinearScale, LineController, LineElement, PointElement, Tooltip);

const palette = ['#660033', '#b8860b', '#3f6e8c', '#9f1239', '#4d7c0f'];

function renderCharts(root = document) {
    root.querySelectorAll('canvas[data-chart]').forEach((canvas) => {
        if (canvas.dataset.rendered === '1') {
            return;
        }
        let spec;
        try {
            spec = JSON.parse(canvas.dataset.chart);
        } catch {
            return;
        }
        canvas.dataset.rendered = '1';

        const reduceMotion = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

        new Chart(canvas, {
            type: spec.type || 'line',
            data: {
                labels: spec.labels || [],
                datasets: (spec.datasets || []).map((ds, i) => ({
                    borderColor: palette[i % palette.length],
                    backgroundColor: palette[i % palette.length] + (spec.type === 'bar' ? 'cc' : '33'),
                    borderWidth: 2,
                    pointRadius: 2,
                    spanGaps: false, // unknown (null) values stay gaps, never 0
                    ...ds,
                })),
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                animation: reduceMotion ? false : undefined,
                interaction: { mode: 'index', intersect: false },
                plugins: { legend: { labels: { font: { family: 'Poppins, system-ui, sans-serif' } } } },
                scales: {
                    y: { beginAtZero: true, title: { display: !!spec.yLabel, text: spec.yLabel || '' } },
                },
            },
        });
    });
}

document.addEventListener('DOMContentLoaded', () => renderCharts());
document.addEventListener('livewire:navigated', () => renderCharts());
