// PWA Service Worker for icut - Hybrid strategy:
//   - Navigation (page loads) are network-first with cache fallback, so pages
//     such as admin_login.php always load fresh from the server.
//   - Static assets use stale-while-revalidate (serve cache, update in background).
const CACHE_NAME = 'icut-v3';       // runtime cache (static assets)
const STATIC_CACHE = 'icut-static-v3'; // pre-cached app shell + runtime shared cache

// Assets to cache for offline fallback
const STATIC_ASSETS = [
    '/icut/',
    '/icut/index.php',
    '/icut/manifest.json',
    '/icut/sw.js'
];

// Install event - cache static assets
self.addEventListener('install', function(event) {
    console.log('[SW] Installing service worker...');
    event.waitUntil(
        caches.open(STATIC_CACHE)
            .then(function(cache) {
                console.log('[SW] Caching static assets');
                return cache.addAll(STATIC_ASSETS);
            })
            .then(function() {
                console.log('[SW] Static assets cached successfully');
                return self.skipWaiting();
            })
            .catch(function(err) {
                console.error('[SW] Failed to cache static assets:', err);
            })
    );
});

// Activate event - clean up old caches
self.addEventListener('activate', function(event) {
    console.log('[SW] Activating service worker...');
    event.waitUntil(
        caches.keys()
            .then(function(cacheNames) {
                return Promise.all(
                    cacheNames
                        .filter(function(cacheName) {
                            return cacheName !== STATIC_CACHE && cacheName !== CACHE_NAME;
                        })
                        .map(function(cacheName) {
                            console.log('[SW] Deleting old cache:', cacheName);
                            return caches.delete(cacheName);
                        })
                );
            })
            .then(function() {
                console.log('[SW] Service worker activated');
                return self.clients.claim();
            })
    );
});

// Fetch event - Hybrid strategy
// Navigation requests: network-first (fresh HTML from server, cache fallback offline).
// Static assets: stale-while-revalidate (serve cache now, update in background).
self.addEventListener('fetch', function(event) {
    const requestUrl = new URL(event.request.url);

    // Skip non-GET requests
    if (event.request.method !== 'GET') {
        return;
    }

    // Only handle same-origin requests; let external CDNs load directly
    if (!requestUrl.origin.includes(self.location.hostname)) {
        return;
    }

    // Navigation (page loads) - network-first so pages always stay fresh.
    // This prevents a stale cached index.php from ever answering admin_login.php.
    if (event.request.mode === 'navigate') {
        event.respondWith(
            fetch(event.request).then(function(response) {
                if (response && response.status === 200) {
                    const responseClone = response.clone();
                    caches.open(STATIC_CACHE).then(function(cache) {
                        cache.put(event.request, responseClone);
                    });
                }
                return response;
            }).catch(function() {
                // Offline - fall back to the cached copy of the page if available
                return caches.match(event.request).then(function(cached) {
                    if (cached) {
                        return cached;
                    }
                    return caches.match('/icut/index.php');
                });
            })
        );
        return;
    }

    // Static asset - stale-while-revalidate
    event.respondWith(
        caches.open(STATIC_CACHE).then(function(cache) {
            return cache.match(event.request).then(function(cachedResponse) {
                // Start network fetch in background
                const networkFetch = fetch(event.request).then(function(response) {
                    // Update cache with fresh response
                    if (response && response.status === 200) {
                        const responseClone = response.clone();
                        cache.put(event.request, responseClone);
                    }
                    return response;
                }).catch(function(error) {
                    // Network failed, but we already have cached response
                    console.log('[SW] Network failed, using cache for:', event.request.url);
                });

                // Return cached response immediately if available
                if (cachedResponse) {
                    // Still wait for network fetch to complete in background
                    networkFetch.catch(function() {});
                    return cachedResponse;
                }

                // No cache, wait for network
                return networkFetch;
            });
        })
    );
});

// Push Notifications
self.addEventListener('push', function(event) {
    let data = {
        title: 'icut Reminder',
        body: 'You have an upcoming appointment!',
        icon: '/icut/uploads/logo/6a6fe15cf145e_1785717084.png',
        badge: '/icut/uploads/logo/6a6fe15cf145e_1785717084.png'
    };
    
    if (event.data) {
        try {
            const payload = event.data.json();
            data.title = payload.title || data.title;
            data.body = payload.body || data.body;
            data.icon = payload.icon || data.icon;
            data.badge = payload.badge || data.badge;
            data.data = payload.data || {};
        } catch (e) {
            data.body = event.data.text();
        }
    }
    
    const options = {
        body: data.body,
        icon: data.icon,
        badge: data.badge,
        vibrate: [100, 50, 100],
        data: data.data || {},
        actions: [
            { action: 'view', title: 'View Details' },
            { action: 'close', title: 'Dismiss' }
        ]
    };
    
    event.waitUntil(
        self.registration.showNotification(data.title, options)
    );
});

self.addEventListener('notificationclick', function(event) {
    event.notification.close();
    
    if (event.action === 'view' || !event.action) {
        event.waitUntil(
            self.clients.matchAll({ type: 'window' }).then(function(clientList) {
                for (const client of clientList) {
                    if (client.url.includes('/icut/') && 'focus' in client) {
                        return client.focus();
                    }
                }
                if (self.clients.openWindow) {
                    return self.clients.openWindow('/icut/');
                }
            })
        );
    }
});