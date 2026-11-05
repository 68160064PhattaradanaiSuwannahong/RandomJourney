import fs from 'node:fs';
import * as topo from 'topojson-client';
import * as d3 from 'd3-geo';
const world = JSON.parse(fs.readFileSync('node_modules/world-atlas/countries-50m.json'));
const world10 = JSON.parse(fs.readFileSync('node_modules/world-atlas/countries-10m.json'));
const sg10 = topo.feature(world10, world10.objects.countries).features.find((f) => f.id === '702');
const num2iso = JSON.parse(fs.readFileSync('num2iso.json'));
const dest = process.argv[2].split(',');
const fc = topo.feature(world, world.objects.countries);
const shapes = {}, borders = {};
const round = (x) => Math.round(x * 100) / 100;
for (const f of fc.features) {
  const iso = num2iso[String(f.id).padStart(3, '0')];
  if (!dest.includes(iso) || f.properties.name === 'Ashmore and Cartier Is.') continue;
  if (iso === 'SG') f.geometry = sg10.geometry;
  const polys = f.geometry.type === 'Polygon' ? [f.geometry.coordinates] : f.geometry.coordinates;
  // Borders for point-in-polygon: outer and inner rings, rounded
  borders[iso] = polys.map((p) => p.map((ring) => {
    const out = [];
    for (const [x, y] of ring) { const pt = [round(x), round(y)]; const l = out[out.length - 1]; if (!l || l[0] !== pt[0] || l[1] !== pt[1]) out.push(pt); }
    return out;
  }).filter((r) => r.length >= 4));
  // Silhouette: mainland only for countries with far-flung territories
  let keep = polys;
  const c = (p) => d3.geoCentroid({ type: 'Polygon', coordinates: p });
  if (iso === 'US') keep = polys.filter((p) => { const [x, y] = c(p); return x > -130 && y > 24 && y < 50; });
  if (iso === 'AU') keep = polys.filter((p) => c(p)[1] > -45);
  if (iso === 'FR') keep = polys.filter((p) => { const [x, y] = c(p); return x > -6 && x < 10 && y > 41 && y < 52; });
  if (iso === 'GR' || iso === 'ID' || iso === 'GB' || iso === 'IS' || iso === 'AU' || iso === 'CN' || iso === 'IN' || iso === 'JP' || iso === 'IT') {
    // drop tiny specks so the outline stays clean
    keep = keep.filter((p) => d3.geoArea({ type: 'Polygon', coordinates: p }) > 2e-6);
  }
  const g = { type: 'MultiPolygon', coordinates: keep };
  const [cx, cy] = d3.geoCentroid(g);
  const proj = d3.geoAzimuthalEqualArea().rotate([-cx, -cy]).fitExtent([[6, 6], [194, 194]], g);
  const path = d3.geoPath(proj).digits(1);
  shapes[iso] = path(g);
  if (!shapes[iso]) console.log('NULL', iso, keep.length);
}
fs.writeFileSync(process.argv[3], JSON.stringify(shapes));
fs.writeFileSync(process.argv[4], JSON.stringify(borders));
console.log(Object.keys(shapes).length, Object.keys(borders).length);
