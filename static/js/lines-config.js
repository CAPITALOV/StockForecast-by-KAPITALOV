// static/js/lines-config.js
export const LINES_CONFIG = {
    1: {
        type: 'delta',
        label: 'Delta Close — направление тренда Close',
        color: '#e11d48', // red
        public: true
    },
    3: {
        type: 'rpo',
        label: 'RPO — Weighted average price',
        color: '#2563eb', // blue
        public: true
    },
    2: {
        type: 'synthetic',
        label: 'Synthetic Average (admin)',
        color: '#f59e0b', // orange
        public: false
    }
};

// При получении данных из lib/api.php, используйте этот конфиг для настройки серий
export function getLineSettings(lineNumber, isAdmin = false) {
    const config = LINES_CONFIG[lineNumber];
    if (!config || (!isAdmin && !config.public)) {
        return null;
    }
    return config;
}