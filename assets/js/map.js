// Random Journey maps (Leaflet + OpenStreetMap).
// Each <div data-map="ID"> reads its markers from <script type="application/json" id="ID">,
// so no inline JavaScript is needed (the Content Security Policy blocks it).
'use strict';

document.addEventListener('DOMContentLoaded', () => {
    if (typeof L === 'undefined') return;

    // Colour per category group, matching the legend in the CSS
    const colours = {
        attraction: '#24479e', trail: '#2f6b4f',
        restaurant: '#c53a3a', cafe: '#c53a3a', beverage: '#c53a3a', bar: '#8a3b8f',
        market: '#b7791f', mall: '#b7791f', souvenir: '#b7791f',
        hotel: '#55627a', city: '#1d2a47', here: '#c53a3a',
    };

    document.querySelectorAll('[data-map]').forEach((el) => {
        const source = document.getElementById(el.dataset.map);
        if (!source) return;

        let points;
        try { points = JSON.parse(source.textContent); } catch { return; }
        if (!points.length) return;

        const map = L.map(el, { scrollWheelZoom: false });
        L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
            maxZoom: 19,
            attribution: '&copy; <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a> contributors',
        }).addTo(map);

        const bounds = [];
        points.forEach((p) => {
            const isHere = p.kind === 'here';
            const marker = L.circleMarker([p.lat, p.lng], {
                radius: isHere ? 11 : 8,
                color: '#fbfcfa',
                weight: 2,
                fillColor: colours[p.kind] || '#24479e',
                fillOpacity: 0.95,
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
            map.fitBounds(bounds, { padding: [30, 30], maxZoom: 15 });
        }

        // Re-enable scroll zoom once the user clicks into the map
        map.once('focus', () => map.scrollWheelZoom.enable());
        map.on('click', () => map.scrollWheelZoom.enable());
    });
});
