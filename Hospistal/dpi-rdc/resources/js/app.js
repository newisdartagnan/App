import './bootstrap';
import Alpine from 'alpinejs';

window.Alpine = Alpine;
Alpine.start();

if ('serviceWorker' in navigator) {
    window.addEventListener('load', async () => {
        try {
            const registration = await navigator.serviceWorker.register('/sw.js');
            const token = localStorage.getItem('dpi_offline_token');
            if (token && registration.active) {
                registration.active.postMessage({ type: 'OFFLINE_TOKEN', token });
            }
        } catch (e) {
            console.warn('Service Worker non enregistré:', e);
        }
    });
}

function updateOfflineBanner() {
    const banner = document.getElementById('offline-banner');
    if (!banner) return;
    banner.classList.toggle('hidden', navigator.onLine);
}

window.addEventListener('online', updateOfflineBanner);
window.addEventListener('offline', updateOfflineBanner);
updateOfflineBanner();
