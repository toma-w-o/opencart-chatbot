// Generates the avatar SVGs (original flat characters). Run: node gen-avatars.js <outdir>
const fs = require('fs');
const out = process.argv[2];
const INK = '#2b2438';

function css(id) {
  const P = 'svg.ac-' + id;
  return `
${P} *{transform-box:fill-box}
${P} .bob{transform-origin:50% 100%;animation:ac-breath 4.2s ease-in-out infinite}
${P} .head{transform-origin:50% 100%}
${P} .eye{transform-origin:50% 50%;animation:ac-blink 4.6s infinite}
${P} .eyes{transform-origin:50% 50%}
${P} .m-smile,${P} .m-open{transform-origin:50% 50%}
${P} .m-open{opacity:0;transform:scaleY(.2)}
${P} .think,${P} .spark{opacity:0}
${P} .cheek{opacity:.55}
${P} .ring{transform-origin:50% 50%;animation:ac-spin 9s linear infinite}
${P}.is-listening .head{animation:ac-tilt 2.4s ease-in-out infinite}
${P}.is-listening .eyes{transform:scale(1.08)}
${P}.is-listening .ear{animation:ac-ear 1.2s ease-in-out infinite;transform-origin:50% 100%}
${P}.is-thinking .eyes{animation:ac-look 2.4s ease-in-out infinite}
${P}.is-thinking .m-smile{transform:scaleX(.55)}
${P}.is-thinking .think{opacity:1}
${P}.is-thinking .think circle{animation:ac-dot 1.2s ease-in-out infinite}
${P}.is-thinking .think circle:nth-child(2){animation-delay:.2s}
${P}.is-thinking .think circle:nth-child(3){animation-delay:.4s}
${P}.is-talking .m-smile{opacity:0}
${P}.is-talking .m-open{opacity:1;animation:ac-talk .36s ease-in-out infinite}
${P}.is-talking .bob{animation:ac-breath 1.5s ease-in-out infinite}
${P}.is-happy .bob{animation:ac-hop .55s ease-out 2}
${P}.is-happy .eye{animation:none;transform:scaleY(.5)}
${P}.is-happy .cheek{opacity:1}
${P}.is-happy .m-smile{transform:scale(1.25)}
${P}.is-happy .spark{opacity:1;animation:ac-spark .9s ease-in-out 2}
@keyframes ac-breath{0%,100%{transform:scale(1,1)}50%{transform:scale(1.015,1.03)}}
@keyframes ac-blink{0%,92%,100%{transform:scaleY(1)}95.5%{transform:scaleY(.08)}}
@keyframes ac-tilt{0%,100%{transform:rotate(-4deg)}50%{transform:rotate(3deg)}}
@keyframes ac-ear{0%,100%{transform:rotate(0)}50%{transform:rotate(-8deg)}}
@keyframes ac-look{0%,100%{transform:translate(-1.6px,-1.6px)}50%{transform:translate(1.6px,-1.6px)}}
@keyframes ac-dot{0%,100%{opacity:.25;transform:translateY(0)}50%{opacity:1;transform:translateY(-2px)}}
@keyframes ac-talk{0%,100%{transform:scaleY(.35)}50%{transform:scaleY(1.15)}}
@keyframes ac-hop{0%{transform:translateY(0)}40%{transform:translateY(-3px) scale(1.03)}100%{transform:translateY(0)}}
@keyframes ac-spark{0%,100%{transform:scale(.6) rotate(0)}50%{transform:scale(1.15) rotate(20deg)}}
@keyframes ac-spin{to{transform:rotate(360deg)}}
@media (prefers-reduced-motion:reduce){${P} *{animation:none!important;transition:none!important}}`;
}

function face(o) {
  o = Object.assign({ cx: 32, ey: 31, dx: 6, my: 38, eye: INK, hi: true, rx: 2.3, ry: 2.9, mouth: INK, open: '#6b2c3e', cheek: '#ff8fa3', cheeks: true }, o);
  const e = (x) => `<g class="eye"><ellipse cx="${x}" cy="${o.ey}" rx="${o.rx}" ry="${o.ry}" fill="${o.eye}"/>` +
    (o.hi ? `<circle cx="${x + 0.8}" cy="${o.ey - 1}" r=".85" fill="#fff"/>` : '') + '</g>';
  let s = '';
  if (o.cheeks) s += `<ellipse class="cheek" cx="${o.cx - o.dx - 3.6}" cy="${o.ey + 5.6}" rx="2.6" ry="1.7" fill="${o.cheek}"/><ellipse class="cheek" cx="${o.cx + o.dx + 3.6}" cy="${o.ey + 5.6}" rx="2.6" ry="1.7" fill="${o.cheek}"/>`;
  s += `<g class="eyes">${e(o.cx - o.dx)}${e(o.cx + o.dx)}</g>`;
  s += `<path class="m-smile" d="M${o.cx - 3.4} ${o.my}Q${o.cx} ${o.my + 3.8} ${o.cx + 3.4} ${o.my}" fill="none" stroke="${o.mouth}" stroke-width="1.6" stroke-linecap="round"/>`;
  s += `<ellipse class="m-open" cx="${o.cx}" cy="${o.my + 1.2}" rx="2.9" ry="2.7" fill="${o.open}"/>`;
  return s;
}
const SH = (c) => `<path d="M9 54C10 45 20 42 32 42S54 45 55 54A32 32 0 0 1 9 54Z" fill="${c}"/>`;
const neck = (c) => `<rect x="28" y="36" width="8" height="9" rx="3" fill="${c}"/>`;

const think = (c) => `<g class="think" fill="${c}"><circle cx="44" cy="9" r="2"/><circle cx="50" cy="12.5" r="2"/><circle cx="55" cy="18" r="2"/></g>`;
const star = (x, y, s) => `<path d="M${x} ${y - s}L${x + s * .3} ${y - s * .3}L${x + s} ${y}L${x + s * .3} ${y + s * .3}L${x} ${y + s}L${x - s * .3} ${y + s * .3}L${x - s} ${y}L${x - s * .3} ${y - s * .3}Z" fill="#ffc933"/>`;
const spark = () => `<g class="spark">${star(11, 15, 4)}${star(53, 11, 3.2)}</g>`;

const C = {};
C.maria = { name: 'Maria', bg: '#ffe3ec', tc: '#e0587f', body:
  `<g class="bob">${SH('#2fa4a0')}${neck('#eebb94')}<g class="head"><path d="M12 31C12 12 52 12 52 31L53 47 11 47Z" fill="#6b3f2a"/><ellipse cx="32" cy="30" rx="14" ry="15.5" fill="#f6c9a4"/><path d="M17.5 29C16 12 46 10 46.5 29 42 22 30 19 23 24 20 26 18.5 28 17.5 29Z" fill="#6b3f2a"/>${face({ ey: 32, dx: 6, my: 39.2 })}</g></g>` };
C.ivan = { name: 'Ivan', bg: '#dbeafe', tc: '#3b6fd8', body:
  `<g class="bob">${SH('#3b6fd8')}<path d="M26 42L32 49 38 42Z" fill="#f3f6ff"/>${neck('#dba87d')}<g class="head"><circle cx="17.6" cy="32" r="3" fill="#e8b48a"/><circle cx="46.4" cy="32" r="3" fill="#e8b48a"/><ellipse cx="32" cy="30.5" rx="14.2" ry="15.5" fill="#e8b48a"/><path d="M17.5 28C15 11 49 11 46.5 28 44 21.5 38 19.5 32 19.5S20 21.5 17.5 28Z" fill="#2b2230"/><path d="M24 25.5q3-2 6-.4M34 25.1q3-1.6 6 .4" stroke="#2b2230" stroke-width="1.6" fill="none" stroke-linecap="round"/>${face({ ey: 31, dx: 6, my: 39, cheeks: false })}</g></g>` };
C.nia = { name: 'Nia', bg: '#fff0cf', tc: '#f08a3c', body:
  `<g class="bob">${SH('#f08a3c')}${neck('#9a643f')}<g class="head"><circle cx="32" cy="24" r="19.5" fill="#2a1c1a"/><ellipse cx="32" cy="31" rx="14" ry="15.5" fill="#a9714b"/><path d="M17.6 28C18 15 46 15 46.4 28 42 22 22 22 17.6 28Z" fill="#2a1c1a"/><circle cx="17.8" cy="35" r="1.6" fill="#ffc933"/><circle cx="46.2" cy="35" r="1.6" fill="#ffc933"/>${face({ ey: 32, dx: 6, my: 40, cheek: '#e8607a', cheeks: true })}<g fill="none" stroke="#2a1c1a" stroke-width="1.3"><circle cx="26" cy="32" r="5.2"/><circle cx="38" cy="32" r="5.2"/><path d="M31.2 31.5h1.6"/></g></g></g>` };
C.robot = { name: 'Robo', bg: '#e4e1ff', tc: '#6a5cf0', body:
  `<g class="bob"><path d="M9 54C10 46 20 43 32 43S54 46 55 54A32 32 0 0 1 9 54Z" fill="#8a8fa8"/><rect x="26" y="38" width="12" height="7" rx="2" fill="#6c7190"/><g class="head"><rect class="ear" x="9.5" y="27" width="5" height="11" rx="2.5" fill="#6a5cf0"/><rect class="ear" x="49.5" y="27" width="5" height="11" rx="2.5" fill="#6a5cf0"/><path d="M32 17V10" stroke="#6c7190" stroke-width="2" stroke-linecap="round"/><circle cx="32" cy="8.6" r="3" fill="#ffc933"/><rect x="13.5" y="16" width="37" height="29" rx="11" fill="#dfe4f2"/><rect x="18" y="20.5" width="28" height="20" rx="7.5" fill="#1f2640"/>${face({ ey: 28.2, dx: 7, my: 34.6, eye: '#6cf0ff', hi: false, rx: 2.5, ry: 3.1, mouth: '#6cf0ff', open: '#6cf0ff', cheeks: false })}</g></g>`, tcol: '#6a5cf0' };
C.mia = { name: 'Mia (cat)', bg: '#ffe9d6', tc: '#e08a3a', body:
  `<g class="bob">${SH('#f2a65a')}<g class="head"><path class="ear" d="M14 28L13 8 28 18Z" fill="#f2a65a"/><path class="ear" d="M50 28L51 8 36 18Z" fill="#f2a65a"/><path d="M17 23L16.6 12.5 24 17.5Z" fill="#ffb3c1"/><path d="M47 23L47.4 12.5 40 17.5Z" fill="#ffb3c1"/><ellipse cx="32" cy="31" rx="18" ry="15" fill="#f2a65a"/><path d="M32 17v5M27 18.5l1 4M37 18.5l-1 4" stroke="#d9823a" stroke-width="1.6" stroke-linecap="round"/><ellipse cx="32" cy="38" rx="8" ry="5.4" fill="#fff4e6"/>${face({ ey: 30.5, dx: 8, my: 39.4, cheeks: false })}<path d="M30.2 35.6h3.6L32 37.6Z" fill="#e8607a"/><path d="M19 36.5l-7-1.2M19 39l-7 2M45 36.5l7-1.2M45 39l7 2" stroke="#b86a28" stroke-width="1" stroke-linecap="round"/></g></g>` };
C.bun = { name: 'Bun (bunny)', bg: '#e9f7e1', tc: '#7c5ccc', body:
  `<g class="bob">${SH('#b49cf0')}<g class="head"><g class="ear"><ellipse cx="24" cy="14" rx="5" ry="11" fill="#f7f3ff"/><ellipse cx="24" cy="14.5" rx="2.4" ry="8" fill="#ffc2d1"/></g><g class="ear"><ellipse cx="40" cy="14" rx="5" ry="11" fill="#f7f3ff"/><ellipse cx="40" cy="14.5" rx="2.4" ry="8" fill="#ffc2d1"/></g><ellipse cx="32" cy="34" rx="16.5" ry="14" fill="#f7f3ff"/>${face({ ey: 33, dx: 6.5, my: 40.4 })}<path d="M30.4 37.2h3.2L32 38.8Z" fill="#ff8fa3"/></g></g>` };
C.bru = { name: 'Bru (bear)', bg: '#fff0c9', tc: '#a9703f', body:
  `<g class="bob">${SH('#a9703f')}<path d="M24 43Q32 49 40 43L38 47Q32 51 26 47Z" fill="#e04f5f"/><g class="head"><g class="ear"><circle cx="17" cy="17.5" r="6.5" fill="#a9703f"/><circle cx="17" cy="17.5" r="3.4" fill="#e7c29a"/></g><g class="ear"><circle cx="47" cy="17.5" r="6.5" fill="#a9703f"/><circle cx="47" cy="17.5" r="3.4" fill="#e7c29a"/></g><circle cx="32" cy="31" r="17.5" fill="#a9703f"/><ellipse cx="32" cy="37.5" rx="8.5" ry="6.4" fill="#e7c29a"/>${face({ ey: 29.5, dx: 7.8, my: 40, cheeks: false, eye: '#241a16' })}<ellipse cx="32" cy="35" rx="2.8" ry="1.9" fill="#3a2418"/></g></g>` };
C.rex = { name: 'Rex (fox)', bg: '#d8f0ff', tc: '#ee7b30', body:
  `<g class="bob"><path d="M9 54C10 45 20 42 32 42S54 45 55 54A32 32 0 0 1 9 54Z" fill="#ee7b30"/><path d="M25 43Q32 56 39 43Q32 47 25 43Z" fill="#fff6ea"/><g class="head"><path class="ear" d="M15 27L14 6 29 17.5Z" fill="#ee7b30"/><path class="ear" d="M49 27L50 6 35 17.5Z" fill="#ee7b30"/><path d="M18 21L17.6 11.5 25 17Z" fill="#3a2a30"/><path d="M46 21L46.4 11.5 39 17Z" fill="#3a2a30"/><path d="M14 24C14 16 20 15 24 18 28 16.5 36 16.5 40 18 44 15 50 16 50 24 51 36 42 47 32 47S13 36 14 24Z" fill="#ee7b30"/><path d="M32 47C24 47 17 40 15 34 22 36.5 27 37 32 33 37 37 42 36.5 49 34 47 40 40 47 32 47Z" fill="#fff6ea"/>${face({ ey: 30.5, dx: 7.5, my: 42.6, cheeks: false })}<circle cx="32" cy="38.8" r="2.3" fill="#2b2438"/></g></g>` };
C.glow = { name: 'Glow (orb)', bg: '#241f5a', tc: '#7c5cff', think: '#cfc6ff', body:
  `<g class="bob"><circle cx="32" cy="33" r="25" fill="#7c5cff" opacity=".22"/><circle cx="32" cy="33" r="21" fill="#7c5cff" opacity=".35"/><circle cx="32" cy="33" r="17" fill="#9b8cff"/><circle cx="32" cy="33" r="13" fill="#cfc6ff"/><ellipse cx="26" cy="26" rx="4" ry="2.6" fill="#fff" opacity=".7" transform="rotate(-30 26 26)"/><g class="head"><g class="ring"><ellipse cx="32" cy="33" rx="27" ry="8" fill="none" stroke="#ffd45c" stroke-width="1.2" opacity=".8" transform="rotate(-20 32 33)"/><circle cx="58" cy="29" r="2.2" fill="#ffd45c"/></g>${face({ ey: 33, dx: 5, my: 38, eye: '#2b2060', hi: false, mouth: '#2b2060', open: '#2b2060', cheek: '#ff8fb5', cheeks: true })}</g></g>` };

const manifest = [];
for (const id of Object.keys(C)) {
  const c = C[id];
  const svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="ac-${id} is-idle" focusable="false" aria-hidden="true"><style>${css(id)}</style><circle cx="32" cy="32" r="32" fill="${c.bg}"/>${c.body}${think(c.think || c.tc)}${spark()}</svg>\n`;
  fs.writeFileSync(out + '/' + id + '.svg', svg);
  manifest.push({ id, name: c.name });
}
fs.writeFileSync(out + '/manifest.json', JSON.stringify(manifest, null, 2) + '\n');
