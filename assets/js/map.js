// Random Journey maps (Leaflet + OpenStreetMap).
// Each <div data-map="ID"> reads its markers from <script type="application/json" id="ID">,
// so no inline JavaScript is needed (the Content Security Policy blocks it).
// Markers are wax seals; a whole-day trip is joined by a dotted ink line in visiting order.
'use strict';

document.addEventListener('DOMContentLoaded', () => {
    if (typeof L === 'undefined') return;

    // Seal colour group and the small gilt mark stamped on it, per category
    const seals = {
        attraction: ['sight', '✦'], trail: ['trail', '▲'],
        restaurant: ['food', '❦'], cafe: ['food', '❦'], beverage: ['food', '❦'], bar: ['bar', '◆'],
        market: ['shop', '❖'], mall: ['shop', '❖'], souvenir: ['shop', '❖'],
        hotel: ['stay', '☾'], city: ['city', '✧'], here: ['here', '★'],
    };

    const sealIcon = (kind, number) => {
        const [group, mark] = seals[kind] || seals.attraction;
        const size = kind === 'here' ? 38 : 30;
        // Only fixed strings from the table above go into this HTML
        return L.divIcon({
            className: 'seal-pin',
            html: `<span class="seal seal--${group}"><span class="seal__mark">${number ? String(number) : mark}</span></span>`,
            iconSize: [size, size],
            iconAnchor: [size / 2, size / 2],
            popupAnchor: [0, -size / 2],
        });
    };

    document.querySelectorAll('[data-map]').forEach((el) => {
        const source = document.getElementById(el.dataset.map);
        if (!source) return;

        let points;
        try { points = JSON.parse(source.textContent); } catch { return; }
        if (!points.length) return;

        const route = el.hasAttribute('data-route') && points.length > 1;
        const map = L.map(el, { scrollWheelZoom: false });
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        }).addTo(map);

        if (route) {
            L.polyline(points.map((p) => [p.lat, p.lng]), {
                className: 'ink-route', color: '#3a1d16', weight: 3, opacity: 0.85, dashArray: '1 9', lineCap: 'round',
            }).addTo(map);
        }

        const bounds = [];
        points.forEach((p, i) => {
            const isHere = p.kind === 'here';
            const marker = L.marker([p.lat, p.lng], {
                icon: sealIcon(p.kind, route ? i + 1 : 0),
                title: p.name,
                riseOnHover: true,
            }).addTo(map);

            // Build the popup with DOM nodes, not HTML strings, so names can't inject markup
            const popup = document.createElement('div');
            const title = document.createElement(p.url ? 'a' : 'strong');
            title.textContent = p.name;
            if (p.url) title.href = p.url;
            popup.appendChild(title);
            if (p.note) {
                const note = document.createElement('div');
                note.textContent = p.note;
                popup.appendChild(note);
            }
            marker.bindPopup(popup);
            if (isHere) marker.openPopup();
            bounds.push([p.lat, p.lng]);
        });

        if (bounds.length === 1) {
            map.setView(bounds[0], Number(el.dataset.zoom || 14));
        } else {
            map.fitBounds(bounds, { padding: [36, 36], maxZoom: 15 });
        }

        // Re-enable scroll zoom once the user clicks into the map
        map.once('focus', () => map.scrollWheelZoom.enable());
        map.on('click', () => map.scrollWheelZoom.enable());
    });
});
