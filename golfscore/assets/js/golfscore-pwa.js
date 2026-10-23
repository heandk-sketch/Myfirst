(function () {
    function normalize(url) {
        try {
            var parsed = new URL(url, window.location.origin);
            return parsed.origin + parsed.pathname.replace(/\/+$/, '/');
        } catch (e) {
            return '';
        }
    }

    function isStandaloneMode() {
        return window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    }

    var startUrl = (window.golfscorePwaData && window.golfscorePwaData.startUrl) || '/golfscore/';
    var normalizedStart = normalize(startUrl);
    var normalizedCurrent = normalize(window.location.href);

    // Safety redirect for installed app launches only.
    if (isStandaloneMode() && normalizedStart && normalizedCurrent && normalizedStart !== normalizedCurrent) {
        window.location.replace(startUrl);
        return;
    }

    if (!('serviceWorker' in navigator)) {
        return;
    }

    var swUrl = (window.golfscorePwaData && window.golfscorePwaData.serviceWorkerUrl) || '/?golfscore-pwa-sw=1';

    window.addEventListener('load', function () {
        navigator.serviceWorker.register(swUrl, { scope: '/' }).catch(function (error) {
            console.warn('GolfScore PWA registration failed:', error);
        });
    });
})();
