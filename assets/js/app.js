// Random Journey: small progressive enhancements. Every page works without JS.
'use strict';

document.addEventListener('DOMContentLoaded', () => {
    // Date fields: open the calendar on a click anywhere in the field, not only on the small icon
    document.querySelectorAll('input[type="date"]').forEach((input) => {
        input.addEventListener('click', () => {
            try { input.showPicker?.(); } catch { /* the browser only allows it on a real click */ }
        });
    });

    // Forms on a page left open for a long time get a fresh token just before they're sent,
    // so a break doesn't end in "page expired" (the session may have ended meanwhile)
    const loadedAt = Date.now();
    const csrfUrl = document.querySelector('meta[name="rj-csrf-url"]')?.content;
    document.addEventListener('submit', async (event) => {
        const form = event.target;
        const field = form.querySelector && form.querySelector('input[name="csrf_token"]');
        if (!field || !csrfUrl || form.dataset.tokenFresh || form.hasAttribute('data-confirm') || Date.now() - loadedAt < 15 * 60 * 1000) return;
        event.preventDefault();
        try {
            const r = await fetch(csrfUrl, { credentials: 'same-origin', cache: 'no-store' });
            const data = await r.json();
            if (data.token) document.querySelectorAll('input[name="csrf_token"]').forEach((i) => { i.value = data.token; });
        } catch { /* send it anyway; the server explains if it has expired */ }
        form.dataset.tokenFresh = '1';
        // Keep the button that was pressed (e.g. which card to keep)
        const submitter = event.submitter;
        if (submitter && submitter.name) {
            const hidden = document.createElement('input');
            hidden.type = 'hidden';
            hidden.name = submitter.name;
            hidden.value = submitter.value;
            form.appendChild(hidden);
        }
        form.submit();
    }, true);

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

    const motionOk = !window.matchMedia('(prefers-reduced-motion: reduce)').matches;

    // --- Near me: the visitor's location, switched on and off in one tap -------------
    // Kept only in this browser (rounded to ~1 km). The server uses it for one search and never saves it.
    const LOC_KEY = 'rj-location';
    const readLoc = () => { try { return JSON.parse(localStorage.getItem(LOC_KEY) || 'null'); } catch { return null; } };
    const writeLoc = (v) => { try { v ? localStorage.setItem(LOC_KEY, JSON.stringify(v)) : localStorage.removeItem(LOC_KEY); } catch { /* private mode */ } };
    const toggles = document.querySelectorAll('[data-location-toggle]');
    const statuses = document.querySelectorAll('[data-location-status]');

    const applyLoc = (loc, message) => {
        const on = !!(loc && loc.on && typeof loc.lat === 'number');
        document.querySelectorAll('[data-loc-lat]').forEach((i) => { i.value = on ? loc.lat.toFixed(2) : ''; });
        document.querySelectorAll('[data-loc-lng]').forEach((i) => { i.value = on ? loc.lng.toFixed(2) : ''; });
        toggles.forEach((b) => {
            b.setAttribute('aria-pressed', String(on));
            b.classList.toggle('is-on', on);
            if (b.dataset.titleOn) b.title = on ? b.dataset.titleOn : b.dataset.titleOff;
        });
        statuses.forEach((s) => {
            s.textContent = 'short' in s.dataset ? (on ? 'On' : 'Off') : (message || (on ? 'On, about 1 km accuracy' : 'Off'));
        });
        document.documentElement.classList.toggle('loc-on', on);
    };

    const locate = () => {
        if (!('geolocation' in navigator)) { applyLoc(null, 'Your browser can\'t share a location'); return; }
        statuses.forEach((s) => { s.textContent = 'short' in s.dataset ? '…' : 'Finding you…'; });
        navigator.geolocation.getCurrentPosition(
            (pos) => {
                const loc = { on: true, lat: Math.round(pos.coords.latitude * 100) / 100, lng: Math.round(pos.coords.longitude * 100) / 100, t: Date.now() };
                writeLoc(loc);
                applyLoc(loc);
            },
            (err) => {
                writeLoc(null);
                applyLoc(null, err.code === 1 ? 'Off: location permission was blocked' : 'Off: couldn\'t find your location');
            },
            { enableHighAccuracy: false, timeout: 10000, maximumAge: 15 * 60 * 1000 }
        );
    };

    document.querySelectorAll('[data-location-panel]').forEach((p) => { p.hidden = false; });
    const saved = readLoc();
    applyLoc(saved);
    // Refresh an old position quietly (it may be from yesterday's trip)
    if (saved && saved.on && Date.now() - (saved.t || 0) > 30 * 60 * 1000) locate();
    toggles.forEach((b) => b.addEventListener('click', () => {
        const loc = readLoc();
        if (loc && loc.on) { writeLoc(null); applyLoc(null); } else { locate(); }
    }));

    // Forms that use GET switch to POST when they carry a location, so it stays out of URLs
    document.querySelectorAll('form[data-post-with-location]').forEach((form) => {
        form.addEventListener('submit', () => {
            const lat = form.querySelector('[data-loc-lat]');
            form.method = lat && lat.value ? 'post' : 'get';
        });
    });

    // --- Country and city pickers -----------------------------------------------------
    document.querySelectorAll('.tool-form').forEach((form) => {
        const countryBoxes = [...form.querySelectorAll('input[name="countries[]"]')];
        const groups = [...form.querySelectorAll('[data-city-country]')];
        const update = () => {
            const chosen = countryBoxes.filter((b) => b.checked).map((b) => b.value);
            groups.forEach((g) => {
                const hasChecked = !!g.querySelector('input:checked');
                g.hidden = chosen.length > 0 && !chosen.includes(g.dataset.cityCountry) && !hasChecked;
            });
            form.querySelectorAll('[data-picker-count]').forEach((el) => {
                const n = form.querySelectorAll(`input[name="${el.dataset.pickerCount}"]:checked`).length;
                el.textContent = n ? `${n} chosen` : el.dataset.empty;
            });
        };
        form.addEventListener('change', (e) => {
            if (e.target.matches('input[name="countries[]"], input[name="cities[]"]')) update();
        });
        update();
    });

    // --- The die and the tarot card (Spin page) -----------------------------------------
    const oracle = document.querySelector('[data-oracle]');
    if (oracle) {
        const webgl = (() => {
            try { const c = document.createElement('canvas'); return !!(c.getContext('webgl2') || c.getContext('webgl')); } catch { return false; }
        })();
        const showCard = () => {
            oracle.classList.remove('is-rolling');
            oracle.classList.add('is-revealed');
        };
        if (motionOk && webgl && oracle.dataset.diceSrc) {
            oracle.classList.add('is-rolling');
            import(oracle.dataset.diceSrc).then((m) => m.roll(oracle)).catch(showCard);
            setTimeout(showCard, 7000); // never leave the result hidden
        } else {
            oracle.classList.add('is-static');
        }
    }

    // --- Photo album on place pages: an open book whose pages turn ----------------------
    document.querySelectorAll('[data-album]').forEach((album) => {
        const photos = [...album.querySelectorAll('[data-album-photo]')];
        const book = album.querySelector('[data-album-book]');
        const left = album.querySelector('[data-album-left]');
        const right = album.querySelector('[data-album-right]');
        const nav = album.querySelector('[data-album-nav]');
        const status = album.querySelector('[data-album-status]');
        const prevBtn = album.querySelector('[data-album-prev]');
        const nextBtn = album.querySelector('[data-album-next]');
        const box = album.querySelector('[data-lightbox]');
        const boxImg = album.querySelector('[data-lightbox-image]');
        if (!photos.length || !book) return;

        const wide = window.matchMedia('(min-width: 46rem)');
        let perSpread = wide.matches ? 2 : 1;
        let first = 0;      // index of the photo on the first page of the spread
        let busy = false;

        // A page: a copy of photo i, or a blank endpaper
        const pageContent = (i) => {
            if (i >= 0 && i < photos.length) {
                const fig = photos[i].cloneNode(true);
                fig.querySelectorAll('img').forEach((img) => { img.loading = 'eager'; });
                return fig;
            }
            const end = document.createElement('div');
            end.className = 'album__endpaper';
            end.innerHTML = '<span aria-hidden="true">✦</span>';
            return end;
        };
        const fill = (el, i) => { el.replaceChildren(pageContent(i)); };
        const render = () => {
            album.classList.toggle('album--single', perSpread === 1);
            if (perSpread === 2) {
                fill(left, first);
                fill(right, first + 1);
            } else {
                fill(right, first);
            }
            const last = Math.min(photos.length, first + perSpread);
            status.textContent = perSpread === 2 && last - first === 2
                ? `Pages ${first + 1}–${last} of ${photos.length}`
                : `Page ${first + 1} of ${photos.length}`;
            prevBtn.disabled = first === 0;
            nextBtn.disabled = first + perSpread >= photos.length;
        };

        // A leaf that rotates around the spine, front showing what you see now, back what comes next
        const turn = (dir) => {
            if (busy) return;
            const target = first + dir * perSpread;
            if (target < 0 || target >= photos.length) return;
            if (!motionOk) { first = target; render(); return; }
            busy = true;
            window.dispatchEvent(new CustomEvent('rj:page-turn'));
            const leaf = document.createElement('div');
            leaf.className = 'album__leaf';
            const front = document.createElement('div');
            front.className = 'album__leaf-face album__page';
            const back = document.createElement('div');
            back.className = 'album__leaf-face album__leaf-face--back album__page';
            leaf.append(front, back);

            let keyframes;
            if (perSpread === 2 && dir > 0) {
                // Right page turns over to the left
                leaf.classList.add('album__leaf--right');
                front.append(pageContent(first + 1));
                back.append(pageContent(target));
                fill(right, target + 1);
                keyframes = [{ transform: 'rotateY(0deg)' }, { transform: 'rotateY(-180deg)' }];
            } else if (perSpread === 2) {
                // Left page turns back to the right
                leaf.classList.add('album__leaf--left');
                front.append(pageContent(first));
                back.append(pageContent(target + 1));
                fill(left, target);
                keyframes = [{ transform: 'rotateY(0deg)' }, { transform: 'rotateY(180deg)' }];
            } else if (dir > 0) {
                // One page: it lifts away to the left, revealing the next
                leaf.classList.add('album__leaf--right', 'album__leaf--solo');
                front.append(pageContent(first));
                fill(right, target);
                keyframes = [{ transform: 'rotateY(0deg)' }, { transform: 'rotateY(-180deg)' }];
            } else {
                // One page: the previous page comes back over from the left
                leaf.classList.add('album__leaf--right', 'album__leaf--solo');
                front.append(pageContent(target));
                keyframes = [{ transform: 'rotateY(-180deg)' }, { transform: 'rotateY(0deg)' }];
            }
            book.appendChild(leaf);
            const anim = leaf.animate(keyframes, { duration: 850, easing: 'cubic-bezier(0.45, 0.05, 0.25, 1)', fill: 'forwards' });
            const done = () => {
                first = target;
                render();
                leaf.remove();
                busy = false;
            };
            anim.onfinish = done;
            setTimeout(() => { if (busy) done(); }, 1500);
        };

        prevBtn.addEventListener('click', () => turn(-1));
        nextBtn.addEventListener('click', () => turn(1));
        album.addEventListener('keydown', (e) => {
            if (e.key === 'ArrowRight') { e.preventDefault(); turn(1); }
            if (e.key === 'ArrowLeft') { e.preventDefault(); turn(-1); }
        });
        // Swipe on touch screens
        let touchX = null;
        book.addEventListener('touchstart', (e) => { touchX = e.touches[0].clientX; }, { passive: true });
        book.addEventListener('touchend', (e) => {
            if (touchX === null) return;
            const dx = e.changedTouches[0].clientX - touchX;
            if (Math.abs(dx) > 40) turn(dx < 0 ? 1 : -1);
            touchX = null;
        });
        wide.addEventListener('change', () => {
            perSpread = wide.matches ? 2 : 1;
            first -= first % perSpread;
            render();
        });
        // Click a photo to see it large
        book.addEventListener('click', (e) => {
            const link = e.target.closest('[data-album-open]');
            if (!link || !box || typeof box.showModal !== 'function') return;
            e.preventDefault();
            boxImg.src = link.href;
            boxImg.alt = link.querySelector('img')?.alt || '';
            box.showModal();
        });
        box?.addEventListener('click', (e) => { if (e.target === box) box.close(); });

        album.classList.add('is-book');
        album.tabIndex = -1;
        book.hidden = false;
        nav.hidden = false;
        render();
    });

    // Selects that submit their own small form (e.g. moving a stop to another day)
    document.querySelectorAll('select[data-autosubmit-select]').forEach((select) => {
        select.addEventListener('change', () => select.form && select.form.submit());
        const btn = select.form && select.form.querySelector('button');
        if (btn) btn.hidden = true;
    });

    // --- Night mode ("blow out the candles") ----------------------------------------------
    document.querySelectorAll('[data-theme-toggle]').forEach((button) => {
        const sync = () => {
            const night = document.documentElement.getAttribute('data-theme') === 'night';
            button.setAttribute('aria-pressed', String(night));
            button.title = night ? 'Light the candles (day theme)' : 'Blow out the candles (night theme)';
        };
        sync();
        button.addEventListener('click', () => {
            const next = document.documentElement.getAttribute('data-theme') === 'night' ? 'day' : 'night';
            document.documentElement.setAttribute('data-theme', next);
            try { localStorage.setItem('rj-theme', next); } catch { /* private mode */ }
            sync();
        });
    });

    // --- Sound effects (off unless the visitor turns them on) ------------------------------
    const SOUND_KEY = 'rj-sound';
    let soundOn = false;
    try { soundOn = localStorage.getItem(SOUND_KEY) === 'on'; } catch { soundOn = false; }
    let audio = null;
    const ctx = () => {
        if (!audio) {
            const AC = window.AudioContext || window.webkitAudioContext;
            if (!AC) return null;
            audio = new AC();
        }
        if (audio.state === 'suspended') audio.resume();
        return audio;
    };
    // Wooden clatter: short filtered noise bursts that slow down like a die settling
    const playRoll = (count) => {
        const ac = ctx();
        if (!ac) return;
        const now = ac.currentTime;
        let t = 0;
        for (let i = 0; i < 14 * count; i++) {
            t += 0.04 + i * 0.012 / count;
            const len = 0.05;
            const buf = ac.createBuffer(1, ac.sampleRate * len, ac.sampleRate);
            const data = buf.getChannelData(0);
            for (let j = 0; j < data.length; j++) data[j] = (Math.random() * 2 - 1) * Math.pow(1 - j / data.length, 3);
            const src = ac.createBufferSource();
            src.buffer = buf;
            const filter = ac.createBiquadFilter();
            filter.type = 'bandpass';
            filter.frequency.value = 900 + Math.random() * 1600;
            filter.Q.value = 4;
            const gain = ac.createGain();
            gain.gain.value = 0.55 * Math.pow(0.88, i / count);
            src.connect(filter).connect(gain).connect(ac.destination);
            src.start(now + t);
        }
    };
    // Card turning over: a soft rising chime
    const playChime = () => {
        const ac = ctx();
        if (!ac) return;
        const now = ac.currentTime;
        [523.25, 659.25, 783.99, 1046.5].forEach((f, i) => {
            const osc = ac.createOscillator();
            const gain = ac.createGain();
            osc.type = 'sine';
            osc.frequency.value = f;
            gain.gain.setValueAtTime(0.0001, now + i * 0.09);
            gain.gain.exponentialRampToValueAtTime(0.18, now + i * 0.09 + 0.02);
            gain.gain.exponentialRampToValueAtTime(0.0001, now + i * 0.09 + 1.2);
            osc.connect(gain).connect(ac.destination);
            osc.start(now + i * 0.09);
            osc.stop(now + i * 0.09 + 1.3);
        });
    };
    window.addEventListener('rj:dice-roll', (e) => { if (soundOn) playRoll(e.detail?.count || 1); });
    window.addEventListener('rj:card-reveal', () => { if (soundOn) playChime(); });
    // Paper rustle when an album page turns
    window.addEventListener('rj:page-turn', () => {
        if (!soundOn) return;
        const ac = ctx();
        if (!ac) return;
        const len = 0.45;
        const buf = ac.createBuffer(1, ac.sampleRate * len, ac.sampleRate);
        const data = buf.getChannelData(0);
        for (let j = 0; j < data.length; j++) {
            const t = j / data.length;
            data[j] = (Math.random() * 2 - 1) * Math.sin(Math.PI * t) * (0.6 + 0.4 * Math.sin(t * 40));
        }
        const src = ac.createBufferSource();
        src.buffer = buf;
        const filter = ac.createBiquadFilter();
        filter.type = 'highpass';
        filter.frequency.value = 1800;
        const gain = ac.createGain();
        gain.gain.value = 0.18;
        src.connect(filter).connect(gain).connect(ac.destination);
        src.start();
    });
    document.querySelectorAll('[data-sound-toggle]').forEach((button) => {
        const sync = () => {
            button.setAttribute('aria-pressed', String(soundOn));
            button.title = soundOn ? 'Sound effects on' : 'Sound effects off';
        };
        sync();
        button.addEventListener('click', () => {
            soundOn = !soundOn;
            try { localStorage.setItem(SOUND_KEY, soundOn ? 'on' : 'off'); } catch { /* private mode */ }
            sync();
            if (soundOn) playChime();
        });
    });

    // --- Cards tilt towards the pointer -----------------------------------------------------
    if (motionOk) {
        document.querySelectorAll('.tarot--tilt').forEach((card) => {
            card.addEventListener('pointermove', (e) => {
                const r = card.getBoundingClientRect();
                const x = (e.clientX - r.left) / r.width - 0.5;
                const y = (e.clientY - r.top) / r.height - 0.5;
                card.style.setProperty('--ry', (x * 18).toFixed(2) + 'deg');
                card.style.setProperty('--rx', (-y * 14).toFixed(2) + 'deg');
                card.style.setProperty('--gx', ((x + 0.5) * 100).toFixed(1) + '%');
            });
            card.addEventListener('pointerleave', () => {
                card.style.setProperty('--ry', '0deg');
                card.style.setProperty('--rx', '0deg');
            });
        });
    }
    // Tap a face-down card to peek at its back turning; tap again to turn it back
    document.querySelectorAll('[data-card-flip]').forEach((card) => {
        card.addEventListener('click', () => card.classList.toggle('is-flipped'));
        card.addEventListener('keydown', (e) => { if (e.key === 'Enter' || e.key === ' ') { e.preventDefault(); card.classList.toggle('is-flipped'); } });
    });

    // --- Save a card as an image ------------------------------------------------------------
    const canvasOk = !!document.createElement('canvas').getContext;
    document.querySelectorAll('[data-save-card]').forEach((button) => {
        if (!canvasOk) return;
        button.hidden = false;
        button.addEventListener('click', async () => {
            let card;
            try { card = JSON.parse(button.dataset.saveCard); } catch { return; }
            button.disabled = true;
            try {
                const blob = await drawCard(card, true).catch(() => drawCard(card, false));
                const a = document.createElement('a');
                a.href = URL.createObjectURL(blob);
                a.download = `random-journey-${card.code.toLowerCase()}.png`;
                document.body.appendChild(a);
                a.click();
                setTimeout(() => { URL.revokeObjectURL(a.href); a.remove(); }, 1000);
            } finally {
                button.disabled = false;
            }
        });
    });

    // --- Candle-dust motes drifting behind the page ------------------------------------
    const dust = document.querySelector('[data-dust]');
    if (dust && motionOk && window.innerWidth > 640) {
        const ctx = dust.getContext('2d');
        let W = 0;
        let H = 0;
        const resize = () => {
            const r = Math.min(2, window.devicePixelRatio || 1);
            W = window.innerWidth; H = window.innerHeight;
            dust.width = W * r; dust.height = H * r;
            ctx.setTransform(r, 0, 0, r, 0, 0);
        };
        resize();
        window.addEventListener('resize', resize);
        const motes = Array.from({ length: 38 }, () => ({
            x: Math.random() * W, y: Math.random() * H, r: 0.6 + Math.random() * 1.8,
            vy: 0.06 + Math.random() * 0.22, vx: (Math.random() - 0.5) * 0.12, p: Math.random() * Math.PI * 2,
        }));
        let last = 0;
        const draw = (now) => {
            if (document.hidden) { requestAnimationFrame(draw); return; }
            const dt = Math.min(50, now - (last || now)) / 16;
            last = now;
            ctx.clearRect(0, 0, W, H);
            for (const m of motes) {
                m.y -= m.vy * dt; m.x += (m.vx + Math.sin(now / 2400 + m.p) * 0.08) * dt; m.p += 0.01 * dt;
                if (m.y < -10) { m.y = H + 10; m.x = Math.random() * W; }
                const a = 0.25 + 0.35 * (0.5 + 0.5 * Math.sin(now / 900 + m.p * 3));
                const g = ctx.createRadialGradient(m.x, m.y, 0, m.x, m.y, m.r * 4);
                g.addColorStop(0, `rgba(255, 214, 140, ${a})`);
                g.addColorStop(1, 'rgba(255, 214, 140, 0)');
                ctx.fillStyle = g;
                ctx.beginPath(); ctx.arc(m.x, m.y, m.r * 4, 0, Math.PI * 2); ctx.fill();
            }
            requestAnimationFrame(draw);
        };
        requestAnimationFrame(draw);
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

/** Draw a tarot card on a canvas and return it as a PNG blob. */
async function drawCard(card, withFlag) {
    const W = 700;
    const H = 1150;
    const c = document.createElement('canvas');
    c.width = W;
    c.height = H;
    const g = c.getContext('2d');
    try { await document.fonts.ready; } catch { /* ignore */ }

    // Night-blue face with a warm glow and a double gilt frame
    const bg = g.createLinearGradient(0, 0, W * 0.3, H);
    bg.addColorStop(0, '#34396a');
    bg.addColorStop(0.55, '#1d2040');
    bg.addColorStop(1, '#151730');
    g.fillStyle = bg;
    g.fillRect(0, 0, W, H);
    const glow = g.createRadialGradient(W / 2, H * 0.38, 20, W / 2, H * 0.38, W * 0.6);
    glow.addColorStop(0, 'rgba(240, 212, 138, 0.25)');
    glow.addColorStop(1, 'rgba(240, 212, 138, 0)');
    g.fillStyle = glow;
    g.fillRect(0, 0, W, H);
    g.strokeStyle = '#c9a24a';
    g.lineWidth = 4;
    g.strokeRect(26, 26, W - 52, H - 52);
    g.lineWidth = 1.5;
    g.strokeStyle = 'rgba(201, 162, 74, 0.6)';
    g.strokeRect(40, 40, W - 80, H - 80);

    const gold = (y0, y1) => {
        const lg = g.createLinearGradient(0, y0, 0, y1);
        lg.addColorStop(0, '#fff2c4');
        lg.addColorStop(0.5, '#e2bb5c');
        lg.addColorStop(1, '#a57a26');
        return lg;
    };
    g.textAlign = 'center';
    g.textBaseline = 'middle';
    g.fillStyle = gold(70, 130);
    g.font = '700 58px Cinzel, Georgia, serif';
    g.fillText(card.numeral.split('').join(' '), W / 2, 104);

    // Compass rays and the country's outline
    const cx = W / 2;
    const cy = 420;
    g.strokeStyle = 'rgba(201, 162, 74, 0.55)';
    g.lineWidth = 2;
    g.setLineDash([3, 9]);
    g.beginPath(); g.arc(cx, cy, 250, 0, Math.PI * 2); g.stroke();
    g.setLineDash([]);
    g.beginPath(); g.arc(cx, cy, 190, 0, Math.PI * 2); g.stroke();
    for (let a = 0; a < 8; a++) {
        const ang = a * Math.PI / 4;
        const r1 = a % 2 ? 210 : 200;
        g.beginPath();
        g.moveTo(cx + Math.sin(ang) * r1, cy - Math.cos(ang) * r1);
        g.lineTo(cx + Math.sin(ang) * 268, cy - Math.cos(ang) * 268);
        g.stroke();
    }
    if (card.shape) {
        g.save();
        g.translate(cx - 200, cy - 200);
        g.scale(2, 2);
        g.shadowColor = 'rgba(240, 212, 138, 0.8)';
        g.shadowBlur = 18;
        g.fillStyle = '#f0d48a';
        g.fill(new Path2D(card.shape));
        g.restore();
    }

    let y = 735;
    if (withFlag && card.flag) {
        const img = await new Promise((resolve, reject) => {
            const im = new Image();
            im.crossOrigin = 'anonymous';
            im.onload = () => resolve(im);
            im.onerror = reject;
            im.src = card.flag;
        });
        const fw = 110;
        const fh = Math.round(fw * img.naturalHeight / img.naturalWidth);
        g.drawImage(img, cx - fw / 2, y - fh / 2, fw, fh);
        g.strokeStyle = '#c9a24a';
        g.lineWidth = 2;
        g.strokeRect(cx - fw / 2, y - fh / 2, fw, fh);
    }
    y += 85;
    g.fillStyle = gold(y - 40, y + 30);
    g.font = '700 62px Cinzel, Georgia, serif';
    g.fillText(card.name, cx, y, W - 120);
    if (card.local) {
        y += 62;
        g.fillStyle = '#e2d2ac';
        g.font = '500 34px Taviraj, Georgia, serif';
        g.fillText(card.local, cx, y, W - 120);
    }
    y += 58;
    g.strokeStyle = 'rgba(201, 162, 74, 0.5)';
    g.lineWidth = 1.5;
    g.beginPath(); g.moveTo(cx - 170, y - 26); g.lineTo(cx + 170, y - 26); g.stroke();
    g.fillStyle = '#c9a24a';
    g.font = '500 30px Cinzel, Georgia, serif';
    g.fillText(card.code.split('').join('   '), cx, y + 4);
    if (card.place) {
        g.fillStyle = '#d8c9a8';
        g.font = 'italic 30px "IM Fell English", Georgia, serif';
        g.fillText(card.place, cx, H - 120, W - 120);
    }
    g.fillStyle = 'rgba(201, 162, 74, 0.75)';
    g.font = '700 22px Cinzel, Georgia, serif';
    g.fillText('✦  RANDOM JOURNEY  ✦', cx, H - 70);

    return new Promise((resolve, reject) => {
        try {
            c.toBlob((b) => (b ? resolve(b) : reject(new Error('empty'))), 'image/png');
        } catch (e) {
            reject(e); // a cross-origin flag can taint the canvas: the caller retries without it
        }
    });
}
