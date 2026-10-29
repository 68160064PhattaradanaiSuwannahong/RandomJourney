// Random Journey: small progressive enhancements. Every page works without JS.
'use strict';

document.addEventListener('DOMContentLoaded', () => {
    // Show / hide password
    document.querySelectorAll('[data-toggle-password]').forEach((button) => {
        const input = document.getElementById(button.dataset.togglePassword);
        if (!input) return;
        button.addEventListener('click', () => {
            const show = input.type === 'password';
            input.type = show ? 'text' : 'password';
            button.textContent = show ? 'Hide' : 'Show';
            button.setAttribute('aria-pressed', String(show));
        });
    });

    // Ask before destructive form submissions (delete buttons)
    document.querySelectorAll('form[data-confirm]').forEach((form) => {
        form.addEventListener('submit', (event) => {
            if (!window.confirm(form.dataset.confirm)) event.preventDefault();
        });
    });

    // Filters that change the form itself (e.g. country -> city list) reload it without spinning
    document.querySelectorAll('[data-autosubmit]').forEach((input) => {
        input.addEventListener('change', () => {
            const form = input.form;
            if (!form) return;
            const flag = document.createElement('input');
            flag.type = 'hidden';
            flag.name = 'refresh';
            flag.value = '1';
            form.appendChild(flag);
            const city = form.querySelector('select[name="city_id"]');
            if (input.name === 'country' && city) city.value = '';
            form.submit();
        });
    });

    // Slot-machine reveal for random results
    const reveal = document.querySelector('[data-reveal]');
    const motionOk = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;
    if (reveal && motionOk) {
        const text = reveal.querySelector('[data-reveal-text]');
        let decoys = [];
        try { decoys = JSON.parse(reveal.dataset.decoys || '[]'); } catch { decoys = []; }
        if (text && decoys.length > 1) {
            const finalText = text.textContent;
            const started = Date.now();
            const duration = 1400;
            let done = false;
            const finish = () => {
                if (done) return;
                done = true;
                text.textContent = finalText;
                reveal.classList.remove('is-spinning');
                reveal.classList.add('is-revealed');
            };
            reveal.classList.add('is-spinning');
            const tick = () => {
                const t = Date.now() - started;
                if (done || t >= duration) { finish(); return; }
                text.textContent = decoys[Math.floor(Math.random() * decoys.length)];
                // Slow down towards the end, like a wheel
                setTimeout(tick, 40 + (t / duration) * 160);
            };
            tick();
            // Safety net: always show the real result, even if timers are throttled
            setTimeout(finish, duration + 300);
        }
    }

    // Changing the country you live in suggests that country's currency
    document.querySelectorAll('select[data-currency-target]').forEach((select) => {
        const target = document.getElementById(select.dataset.currencyTarget);
        if (!target) return;
        select.addEventListener('change', () => {
            const code = select.selectedOptions[0]?.dataset.currency;
            if (code && [...target.options].some((o) => o.value === code)) {
                target.value = code;
            }
        });
    });
});
