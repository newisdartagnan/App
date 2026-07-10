import './bootstrap';

// Service Worker désactivé — il provoquait des tokens CSRF périmés (erreur JSON Livewire)

function updateOfflineBanner() {
    const banner = document.getElementById('offline-banner');
    if (!banner) {
        return;
    }
    banner.classList.toggle('hidden', navigator.onLine);
}

window.addEventListener('online', updateOfflineBanner);
window.addEventListener('offline', updateOfflineBanner);
updateOfflineBanner();

// Désinscrire tout SW existant (cache ancien)
if ('serviceWorker' in navigator) {
    navigator.serviceWorker.getRegistrations().then((registrations) => {
        registrations.forEach((registration) => registration.unregister());
    });
}
