// Presenters map (/map): one dot per presenter at their institution, coloured by slam. Several presenters from one
// institution sit in a small ring around it, so every person stays visible at any zoom.
import './public.js';
import L from 'leaflet';
import 'leaflet/dist/leaflet.css';
import { esc } from '../shared/ui.js';

const box = document.querySelector('[data-pmap]');
if (box) {
  const cfg = JSON.parse(box.dataset.pmap);
  const colour = Object.fromEntries(cfg.slams.map((s) => [s.slug, s.colour]));
  const slamName = Object.fromEntries(cfg.slams.map((s) => [s.slug, s.name]));
  const KERALA = L.latLngBounds([8.15, 74.85], [12.85, 77.45]);
  const map = L.map(box, { scrollWheelZoom: false, zoomSnap: 0.5 }).fitBounds(KERALA);
  L.tileLayer('https://tile.openstreetmap.org/{z}/{x}/{y}.png', {
    maxZoom: 18, attribution: '© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',
  }).addTo(map);
  // Boundaries (DataMeet, CC BY 2.5 India, via Wikimedia Commons / Wikidata): Tamil Nadu, Kerala's districts and the
  // Kerala outline, drawn under the dots. The dots never wait for them.
  const SHADES = ['#fde7c8', '#e3f0d4', '#dbe9f6', '#f6dde6', '#ece3f5', '#fff1b8', '#d9f0ec'];
  const overlays = {};
  fetch(box.dataset.pmapBoundaries, { credentials: 'omit' }).then((r) => (r.ok ? r.json() : null)).then((geo) => {
    if (!geo) return;
    const of = (kind) => ({ type: 'FeatureCollection', features: geo.features.filter((f) => f.properties.kind === kind) });
    const tn = { type: 'FeatureCollection', features: of('state').features.filter((f) => f.properties.name === 'Tamil Nadu') };
    const kl = { type: 'FeatureCollection', features: of('state').features.filter((f) => f.properties.name === 'Kerala') };
    const tnLayer = L.layerGroup([L.geoJSON(tn, { interactive: false, style: { color: '#3f6d1f', weight: 2.2, fillColor: '#9cc46a', fillOpacity: 0.32, dashArray: '6 4' } })]);
    tn.features.forEach((f) => L.tooltip({ permanent: true, direction: 'center', className: 'pmap-district pmap-state', interactive: false })
      .setLatLng(f.properties.label).setContent('Tamil Nadu').addTo(tnLayer));
    overlays['Tamil Nadu'] = tnLayer.addTo(map);
    const districts = L.layerGroup().addTo(map);
    of('district').features.forEach((f, i) => {
      L.geoJSON(f, { interactive: false, style: { color: '#8a6d3b', weight: 1, fillColor: SHADES[i % SHADES.length], fillOpacity: 0.45 } }).addTo(districts);
      L.tooltip({ permanent: true, direction: 'center', className: 'pmap-district', interactive: false })
        .setLatLng(f.properties.label).setContent(esc(f.properties.name)).addTo(districts);
    });
    overlays['Kerala districts'] = districts;
    overlays['Kerala'] = L.geoJSON(kl, { interactive: false, style: { color: '#7b1a1a', weight: 2.5, fill: false } }).addTo(map);
    L.control.layers(null, overlays, { collapsed: true, position: 'topright' }).addTo(map);
    map.attributionControl.addAttribution('Boundaries © <a href="https://github.com/datameet/maps">DataMeet</a> (CC BY 2.5 IN) via Wikimedia Commons');
    const labels = () => box.classList.toggle('pmap-small', map.getZoom() < 7);
    map.on('zoomend', labels); labels();
  }).catch(() => {});
  map.on('focus', () => map.scrollWheelZoom.enable());
  map.on('blur', () => map.scrollWheelZoom.disable());

  // Dot k of n around the place: the first in the centre, then rings of 6, 12, 18 ... (pixels, so they never merge).
  const D = 14;
  const offset = (k, n) => {
    if (n === 1 || k === 0) return [0, 0];
    let ring = 1; let start = 1;
    while (k >= start + 6 * ring) { start += 6 * ring; ring += 1; }
    const inRing = Math.min(6 * ring, n - start); const a = (2 * Math.PI * (k - start)) / inRing - Math.PI / 2;
    return [Math.round(Math.cos(a) * ring * (D + 1)), Math.round(Math.sin(a) * ring * (D + 1))];
  };
  const layers = Object.fromEntries(cfg.slams.map((s) => [s.slug, L.layerGroup().addTo(map)]));
  const everything = [];
  cfg.places.forEach((p) => {
    everything.push([p.lat, p.lng]);
    p.people.forEach((person, k) => {
      const [dx, dy] = offset(k, p.people.length);
      const icon = L.divIcon({
        className: 'pmap-pin', iconSize: [D, D], iconAnchor: [D / 2 - dx, D / 2 - dy],
        html: `<span style="background:${colour[person.slam] || '#555'}"></span>`,
      });
      const popup = `<div class="pmap-pop">${person.thumb ? `<img src="${esc(person.thumb)}" alt="" width="56" height="56" loading="lazy">` : ''}
        <div><div class="fw-bold ml-text">${esc(person.name)}</div><div class="ml-text">${esc(person.topic || '')}</div>
        <div class="small text-secondary">${esc(p.name)}</div><div class="small" style="color:${colour[person.slam]}">${esc(slamName[person.slam] || '')}</div></div></div>`;
      L.marker([p.lat, p.lng], { icon, title: `${person.name} · ${p.name}`, keyboard: true })
        .bindPopup(popup, { maxWidth: 280 }).bindTooltip(`${p.name} (${p.people.length})`, { direction: 'top', offset: [0, -10] })
        .addTo(layers[person.slam] || map);
    });
  });
  document.querySelectorAll('[data-pmap-slam]').forEach((cb) => cb.addEventListener('change', () => {
    const g = layers[cb.dataset.pmapSlam];
    if (g) (cb.checked ? g.addTo(map) : g.remove());
  }));
  const allBtn = document.querySelector('[data-pmap-all]'); const keralaBtn = document.querySelector('[data-pmap-kerala]');
  allBtn?.addEventListener('click', () => { map.fitBounds(L.latLngBounds(everything).pad(0.1)); allBtn.hidden = true; keralaBtn.hidden = false; });
  keralaBtn?.addEventListener('click', () => { map.fitBounds(KERALA); allBtn.hidden = false; keralaBtn.hidden = true; });
}
