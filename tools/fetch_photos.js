// Random Journey: collect photo links and credits from Wikipedia and Wikimedia Commons.
//
// How it was run (November 2026): open https://en.wikipedia.org/wiki/Special:BlankPage,
// paste this file into the browser console, set PLACES to [[id, name, city, country, lat, lng], ...]
// (exported from rj_places), call `await collectPhotos(PLACES)` and save the result as tools/photos.json.
// Then: python3 tools/gen_seed_photos.py database/seed_photos.sql
//
// Rules: only JPEG photos hosted on Commons (free licences), landscape-ish, at least 900 px wide,
// taken within 15 km of the place when the file has coordinates; maps, logos, drawings and
// paintings are skipped (except artworks in museum articles). Every photo keeps its author and licence.
// Results were then reviewed by hand (see the drops and re-orders in the project history).

const BAD = /(map|plan|logo|flag|icon|coat.of.arms|seal|diagram|chart|locator|svg|png|gif|tif|signature|emblem|stamp|banknote|coin|portrait|drawing|engraving|painting|wellcome|lithograph|illustration|postcard)/i;

const wiki = (p) => fetch('/w/api.php?' + new URLSearchParams({ format: 'json', formatversion: '2', ...p })).then((r) => r.json());
const commons = (p) => fetch('https://commons.wikimedia.org/w/api.php?' + new URLSearchParams({ format: 'json', formatversion: '2', origin: '*', ...p })).then((r) => r.json());
const IMAGE_INFO = { prop: 'imageinfo|coordinates', iiprop: 'url|size|extmetadata|mime', iiurlwidth: '1280', iiextmetadatafilter: 'Artist|LicenseShortName' };

const km = (a, b, c, d) => {
    const r = Math.PI / 180;
    const x = Math.sin((c - a) * r / 2) ** 2 + Math.cos(a * r) * Math.cos(c * r) * Math.sin((d - b) * r / 2) ** 2;
    return 12742 * Math.asin(Math.sqrt(x));
};
const text = (html) => { const d = document.createElement('div'); d.innerHTML = html || ''; return d.textContent.replace(/\s+/g, ' ').trim().slice(0, 70); };

function toPhoto(page, lat, lng) {
    const i = page.imageinfo?.[0];
    if (!i || i.mime !== 'image/jpeg' || BAD.test(page.title)) return null;
    if (i.width < 900 || i.height < 500 || i.width < i.height * 0.95) return null;
    if (page.coordinates && km(lat, lng, page.coordinates[0].lat, page.coordinates[0].lon) > 15) return null;
    const m = i.extmetadata || {};
    return [page.title.replace(/^File:/, ''), i.thumbwidth || i.width, i.thumbheight || i.height, text(m.Artist?.value), m.LicenseShortName?.value || null];
}

/** Find the Wikipedia article for a place: the first search hit within 25 km. */
async function findArticle(name, city, lat, lng) {
    for (const q of [name, `${name} ${city}`]) {
        const j = await wiki({ action: 'query', generator: 'search', gsrsearch: q, gsrlimit: '5', prop: 'coordinates|info', inprop: 'url' });
        for (const p of (j.query?.pages || []).sort((a, b) => a.index - b.index)) {
            if (p.coordinates && km(lat, lng, p.coordinates[0].lat, p.coordinates[0].lon) < 25) return p;
        }
    }
    return null;
}

/** Up to 5 photos per place: from its article first, then a Commons search. */
async function collectPhotos(places) {
    const out = {};
    for (const [, name, city, , lat, lng] of places) {
        const photos = [];
        const seen = new Set();
        const add = (ph) => { if (ph && !seen.has(ph[0]) && photos.length < 5) { seen.add(ph[0]); photos.push(ph); } };
        const article = await findArticle(name, city, lat, lng);
        if (article) {
            const j = await wiki({ action: 'query', titles: article.title, prop: 'images|pageimages', imlimit: '100', piprop: 'name' });
            const pg = j.query?.pages?.[0];
            const files = (pg?.images || []).map((x) => x.title).filter((t) => /\.jpe?g$/i.test(t));
            if (pg?.pageimage) files.unshift('File:' + pg.pageimage);
            const uniq = [...new Set(files)].slice(0, 40);
            if (uniq.length) {
                const k = await commons({ action: 'query', titles: uniq.join('|'), ...IMAGE_INFO });
                (k.query?.pages || []).forEach((p) => add(toPhoto(p, lat, lng)));
            }
        }
        if (photos.length < 5) {
            const k = await commons({ action: 'query', generator: 'search', gsrnamespace: '6', gsrsearch: `${name} ${city} filetype:bitmap`, gsrlimit: '30', ...IMAGE_INFO });
            (k.query?.pages || []).sort((a, b) => a.index - b.index).forEach((p) => add(toPhoto(p, lat, lng)));
        }
        out[name] = { wiki: article ? article.fullurl : null, photos };
    }
    return out;
}
