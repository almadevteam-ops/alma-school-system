// Service worker מינימלי: מאפשר התקנה כאפליקציה. לא שומר שום מידע במטמון (המערכת מכילה מידע אישי).
self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', (e) => e.waitUntil(self.clients.claim()));
self.addEventListener('fetch', (e) => {
  if (e.request.mode !== 'navigate') return;
  e.respondWith(fetch(e.request).catch(() => new Response('<!doctype html><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><body dir="rtl" style="font-family:sans-serif;text-align:center;padding:40px"><h2>אין חיבור לאינטרנט</h2><p>המערכת דורשת חיבור. בדקו את הרשת ונסו שוב.</p></body>', { status: 503, headers: { 'Content-Type': 'text/html; charset=utf-8' } })));
});
