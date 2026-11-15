/* OV Pointage — service worker
 * - met en cache la coquille de l'application (CSS, JS, icônes) ;
 * - pages : réseau d'abord, page hors connexion en secours ;
 * - API et espace responsable : jamais mis en cache.
 */
var CACHE = 'ovpt-v1';
var SHELL = [
    './assets/pointage.css',
    './assets/pointage.js',
    './assets/icons/icon-192.png',
    './offline.html'
];

self.addEventListener('install', function (e) {
    e.waitUntil(caches.open(CACHE).then(function (c) { return c.addAll(SHELL); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
    e.waitUntil(caches.keys().then(function (keys) {
        return Promise.all(keys.filter(function (k) { return k !== CACHE; }).map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); }));
});

self.addEventListener('fetch', function (e) {
    var req = e.request;
    if (req.method !== 'GET') return;
    var url = new URL(req.url);
    if (url.origin !== self.location.origin) return;
    if (url.pathname.indexOf('/api/') !== -1 || url.pathname.indexOf('/admin/') !== -1 || url.pathname.indexOf('/client/') !== -1) return;

    if (req.mode === 'navigate') {
        e.respondWith(fetch(req).catch(function () { return caches.match('./offline.html'); }));
        return;
    }
    if (/\.(css|js|png|svg|woff2?)$/.test(url.pathname)) {
        e.respondWith(caches.match(req).then(function (hit) {
            var net = fetch(req).then(function (res) {
                if (res.ok) { var copy = res.clone(); caches.open(CACHE).then(function (c) { c.put(req, copy); }); }
                return res;
            }).catch(function () { return hit; });
            return hit || net;
        }));
    }
});
