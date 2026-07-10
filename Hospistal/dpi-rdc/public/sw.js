// SW désactivé — se désinstalle automatiquement pour libérer le cache
self.addEventListener('install', () => self.skipWaiting());

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys()
            .then((keys) => Promise.all(keys.map((key) => caches.delete(key))))
            .then(() => self.registration.unregister())
            .then(() => self.clients.matchAll())
            .then((clients) => clients.forEach((client) => client.navigate(client.url)))
    );
});

self.addEventListener('fetch', () => {});
