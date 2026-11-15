/* OV Pointage — service worker
 * - coquille de l'application (CSS, JS, icônes, Bootstrap/Font Awesome) en cache ;
 * - écran agent (app.php) : réseau d'abord, dernière copie si pas de réseau,
 *   pour pouvoir pointer hors connexion (les pointages partent ensuite) ;
 * - API, espace responsable et portail client : jamais mis en cache.
 */
var SHELL_CACHE = 'ovpt-shell-v2';
var PAGES_CACHE = 'ovpt-pages';
var SHELL = [
    './assets/pointage.css',
    './assets/pointage.js',
    './assets/app.js',
    './assets/icons/icon-192.png',
    './offline.html'
];
var CDN = /^https:\/\/(cdn\.jsdelivr\.net|cdnjs\.cloudflare\.com)\//;

self.addEventListener('install', function (e) {
    e.waitUntil(caches.open(SHELL_CACHE).then(function (c) { return c.addAll(SHELL); }).then(function () { return self.skipWaiting(); }));
});

self.addEventListener('activate', function (e) {
    e.waitUntil(caches.keys().then(function (keys) {
        return Promise.all(keys.filter(function (k) { return k !== SHELL_CACHE && k !== PAGES_CACHE; })
            .map(function (k) { return caches.delete(k); }));
    }).then(function () { return self.clients.claim(); }));
});

function cacheFirst(req, cacheName) {
    return caches.match(req).then(function (hit) {
        var net = fetch(req).then(function (res) {
            if (res && (res.ok || res.type === 'opaque')) {
                var copy = res.clone();
                caches.open(cacheName).then(function (c) { c.put(req, copy); });
            }
            return res;
        }).catch(function () { return hit; });
        return hit || net;
    });
}

self.addEventListener('fetch', function (e) {
    var req = e.request;
    if (req.method !== 'GET') return;
    var url = new URL(req.url);

    if (CDN.test(req.url)) { e.respondWith(cacheFirst(req, SHELL_CACHE)); return; }
    if (url.origin !== self.location.origin) return;
    if (/\/(api|admin|client)\//.test(url.pathname)) return;

    if (req.mode === 'navigate') {
        var isApp = /\/app\.php$/.test(url.pathname);
        e.respondWith(fetch(req).then(function (res) {
            if (isApp && res.ok && !res.redirected) {
                var copy = res.clone();
                caches.open(PAGES_CACHE).then(function (c) { c.put('./app.php', copy); });
            }
            return res;
        }).catch(function () {
            return (isApp ? caches.match('./app.php') : Promise.resolve(null)).then(function (hit) {
                return hit || caches.match('./offline.html');
            });
        }));
        return;
    }
    if (/\.(css|js|png|svg|woff2?)$/.test(url.pathname)) e.respondWith(cacheFirst(req, SHELL_CACHE));
});
