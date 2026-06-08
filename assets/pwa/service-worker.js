/**
 * CPTT — Service Worker
 * v6.1.8 — minimal pass-through SW.
 *   The previous version installed a cache that could return
 *   `Response.error()` on miss, causing the browser to spin its
 *   loading indicator forever ("FetchEvent ... promise was rejected").
 *
 *   This version simply lets the network handle every request. We
 *   keep the SW registered (so the app is still PWA-installable)
 *   but it does NOT intercept anything.
 */
self.addEventListener('install',  function(e){ self.skipWaiting(); });
self.addEventListener('activate', function(e){ self.clients.claim(); });
/* NO `fetch` handler on purpose — browser handles all requests directly. */
