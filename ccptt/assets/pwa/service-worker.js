const HAM_PWA_CACHE = 'ham-dashboard-v1';
const STATIC_ASSETS = [
  '../css/expert.css',
  '../css/frontend.css',
  '../js/expert.js',
  '../js/frontend.js'
];
self.addEventListener('install', event => {
  event.waitUntil(caches.open(HAM_PWA_CACHE).then(cache => cache.addAll(STATIC_ASSETS)).catch(()=>{}));
  self.skipWaiting();
});
self.addEventListener('activate', event => {
  event.waitUntil(caches.keys().then(keys => Promise.all(keys.filter(k => k !== HAM_PWA_CACHE).map(k => caches.delete(k)))));
  self.clients.claim();
});
self.addEventListener('fetch', event => {
  const req = event.request;
  if (req.method !== 'GET') return;
  const url = new URL(req.url);
  if (url.pathname.includes('/wp-admin/admin-ajax.php')) return;
  event.respondWith(
    caches.match(req).then(cached => cached || fetch(req).then(res => {
      if (res && res.ok && (url.pathname.includes('/assets/css/') || url.pathname.includes('/assets/js/') || url.search.includes('cptt_expert_dashboard'))) {
        const clone = res.clone(); caches.open(HAM_PWA_CACHE).then(cache => cache.put(req, clone)).catch(()=>{});
      }
      return res;
    }).catch(() => cached || Response.error()))
  );
});
