// KWASU LCS — Service Worker for Push Notifications
// Place this file in LMS-portal/ (project root)

self.addEventListener('install', () => self.skipWaiting());
self.addEventListener('activate', e => e.waitUntil(clients.claim()));

// Handle incoming push message
self.addEventListener('push', function (event) {
    let data = { title: 'KWASU LCS', body: 'You have a new notification.', url: '/LMS-portal/notifications.php' };
    try { if (event.data) data = { ...data, ...event.data.json() }; } catch (e) { }

    event.waitUntil(
        self.registration.showNotification(data.title, {
            body: data.body,
            icon: '/LMS-portal/assets/icon-192.png',
            badge: '/LMS-portal/assets/icon-192.png',
            tag: 'lcs-notification',
            renotify: true,
            data: { url: data.url }
        })
    );
});

// Click notification → open the page
self.addEventListener('notificationclick', function (event) {
    event.notification.close();
    const url = event.notification.data?.url || '/LMS-portal/notifications.php';
    event.waitUntil(
        clients.matchAll({ type: 'window', includeUncontrolled: true }).then(windowClients => {
            for (const client of windowClients) {
                if (client.url.includes('LMS-portal') && 'focus' in client) {
                    client.navigate(url);
                    return client.focus();
                }
            }
            return clients.openWindow(url);
        })
    );
});