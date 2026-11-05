// Runs before the page paints, so night mode never flashes the day theme first.
'use strict';
(function () {
    var theme = null;
    try { theme = localStorage.getItem('rj-theme'); } catch (e) { /* storage blocked */ }
    if (theme !== 'night' && theme !== 'day') {
        theme = window.matchMedia && window.matchMedia('(prefers-color-scheme: dark)').matches ? 'night' : 'day';
    }
    document.documentElement.setAttribute('data-theme', theme);
    document.documentElement.classList.add('js'); // lets CSS hide results until the dice has rolled
})();
