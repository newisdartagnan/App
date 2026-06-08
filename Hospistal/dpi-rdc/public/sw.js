const CACHE_VERSION = 'dpi-v1';
const APP_SHELL = [
    '/',
    '/dashboard',
    '/manifest.json',
    '/build/assets/app.css',
];

const CRITICAL_PAGES = ['/dashboard', '/patients'];

self.addEventListener('install', (event) => {
    event.waitUntil(
        caches.open(CACHE_VERSION).then((cache) => cache.addAll(APP_SHELL.filter(Boolean)))
    );
    self.skipWaiting();
});

self.addEventListener('activate', (event) => {
    event.waitUntil(
        caches.keys().then((keys) =>
            Promise.all(keys.filter((k) => k !== CACHE_VERSION).map((k) => caches.delete(k)))
        )
    );
    self.clients.claim();
});

self.addEventListener('fetch', (event) => {
    const { request } = event;
    const url = new URL(request.url);

    if (request.method !== 'GET') {
        if (!navigator.onLine) {
            event.respondWith(queueOfflineRequest(request));
        }
        return;
    }

    if (url.pathname.startsWith('/build/')) {
        event.respondWith(cacheFirst(request));
        return;
    }

    if (CRITICAL_PAGES.some((p) => url.pathname.startsWith(p))) {
        event.respondWith(staleWhileRevalidate(request));
        return;
    }

    if (url.pathname.startsWith('/api/')) {
        event.respondWith(networkFirst(request));
        return;
    }

    event.respondWith(cacheFirst(request));
});

async function cacheFirst(request) {
    const cached = await caches.match(request);
    return cached || fetch(request);
}

async function staleWhileRevalidate(request) {
    const cache = await caches.open(CACHE_VERSION);
    const cached = await cache.match(request);
    const fetchPromise = fetch(request).then((response) => {
        if (response.ok) cache.put(request, response.clone());
        return response;
    });
    return cached || fetchPromise;
}

async function networkFirst(request) {
    try {
        const response = await fetch(request);
        if (response.ok) {
            const cache = await caches.open(CACHE_VERSION);
            cache.put(request, response.clone());
        }
        return response;
    } catch {
        return (await caches.match(request)) || new Response(JSON.stringify({ offline: true }), {
            status: 503,
            headers: { 'Content-Type': 'application/json' },
        });
    }
}

async function queueOfflineRequest(request) {
    const body = await request.clone().text();
    const queue = await getOfflineQueue();
    queue.push({ url: request.url, method: request.method, body, headers: [...request.headers], ts: Date.now() });
    await setOfflineQueue(queue);

    if ('sync' in self.registration) {
        await self.registration.sync.register('dpi-offline-sync');
    }

    return new Response(JSON.stringify({ queued: true }), {
        status: 202,
        headers: { 'Content-Type': 'application/json' },
    });
}

self.addEventListener('sync', (event) => {
    if (event.tag === 'dpi-offline-sync') {
        event.waitUntil(replayOfflineQueue());
    }
});

async function getOfflineQueue() {
    const cache = await caches.open('dpi-offline-queue');
    const res = await cache.match('/queue');
    return res ? await res.json() : [];
}

async function setOfflineQueue(queue) {
    const cache = await caches.open('dpi-offline-queue');
    await cache.put('/queue', new Response(JSON.stringify(queue)));
}

async function replayOfflineQueue() {
    const queue = await getOfflineQueue();
    const remaining = [];

    for (const item of queue) {
        try {
            const token = await getOfflineToken();
            const res = await fetch(item.url, {
                method: item.method,
                body: item.body || undefined,
                headers: { ...Object.fromEntries(item.headers), Authorization: `Bearer ${token}` },
            });
            if (!res.ok) remaining.push(item);
        } catch {
            remaining.push(item);
        }
    }

    await setOfflineQueue(remaining);
}

async function getOfflineToken() {
    const clients = await self.clients.matchAll();
    // Le token est injecté côté client via postMessage
    return self.offlineToken || '';
}

self.addEventListener('message', (event) => {
    if (event.data?.type === 'OFFLINE_TOKEN') {
        self.offlineToken = event.data.token;
    }
});
