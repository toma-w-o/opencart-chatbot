// Generates the avatar library: 8 original "AI agent" faces (screen-face robots), animated with inline CSS only.
// Run: node tools/gen-avatars.js upload/catalog/view/javascript/aichat/avatars
'use strict';
const fs = require('fs');
const path = require('path');
const out = process.argv[2];
if (!out) { console.error('usage: node gen-avatars.js <outdir>'); process.exit(1); }

const HEART = '#ff4f86', STAR = '#ffe27a';
const f = (n) => String(Math.round(n * 100) / 100);

/* ---------- shared eye variants (drawn around 0,0) ---------- */
const happyArc = (c, w) => `<path d="M-4.2 1.8Q0 -4.4 4.2 1.8" fill="none" stroke="${c}" stroke-width="${w || 2.3}" stroke-linecap="round"/>`;
const heart = '<path d="M0 3.8C-5.2 .2-4.8-4.6-2-4.6C-.8-4.6 0-3.7 0-2.9C0-3.7 .8-4.6 2-4.6C4.8-4.6 5.2 .2 0 3.8Z" fill="' + HEART + '"/>';
const star = (c) => `<path d="M0-5.4L1.4-1.4L5.4 0L1.4 1.4L0 5.4L-1.4 1.4L-5.4 0L-1.4-1.4Z" fill="${c || STAR}"/>`;

/* ---------- mouths (drawn around 0,0 = mouth anchor) ---------- */
const mBig = (c, tongue) => `<path d="M-5.2 -1.4Q0 -.4 5.2 -1.4Q4.8 5.8 0 5.8Q-4.8 5.8 -5.2 -1.4Z" fill="${c}"/><ellipse cx="0" cy="4.2" rx="2.5" ry="1.3" fill="${tongue || '#ff7aa0'}"/>`;
const mO = (c) => `<ellipse cx="0" cy="1.2" rx="2.1" ry="2.6" fill="none" stroke="${c}" stroke-width="1.7"/>`;

const AVATARS = [
  {
    id: 'nova', name: 'Nova', bg: '#e4e1ff', accent: '#6a5cf0', tilt: 8, spd: 1, blink: 4.6, look: 9,
    ears: '<rect class="ear" x="6.6" y="27" width="5" height="11.5" rx="2.5" fill="#6a5cf0"/><rect class="ear" x="52.4" y="27" width="5" height="11.5" rx="2.5" fill="#6a5cf0"/>',
    ant: '<path d="M32 17.5V10.5" stroke="#aab0c9" stroke-width="2.2" stroke-linecap="round"/><circle cx="32" cy="8.2" r="5.2" fill="#ffc933" opacity=".28"/><circle class="tip" cx="32" cy="8.2" r="3.1" fill="#ffc933"/><circle cx="31" cy="7.2" r="1" fill="#fff" opacity=".8"/>',
    head: '<rect x="9.5" y="16" width="45" height="37" rx="15" fill="#f3f4ff" stroke="#c4c9ec" stroke-width="1.2"/><path d="M13 46Q32 56 51 46L51 48Q47 53 32 53Q17 53 13 48Z" fill="#aeb4dd" opacity=".4"/><path d="M16 21.5Q22 17.6 29 17.8" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round"/>',
    screen: '<rect x="14.5" y="21" width="35" height="25" rx="10" fill="#1b1d3c"/><path d="M18 25Q20 23 24 23" fill="none" stroke="#fff" stroke-width="1.2" stroke-linecap="round" opacity=".18"/>',
    eyeX: 7.5, eyeY: 31,
    eye: () => '<ellipse rx="5.6" ry="6.6" fill="#6cf0ff" opacity=".18"/><ellipse rx="3.5" ry="4.5" fill="#6cf0ff"/><circle cx="-1.1" cy="-1.7" r="1.1" fill="#fff" opacity=".85"/>',
    eyeColor: '#6cf0ff', browColor: '#6cf0ff', brow: { dy: -8.8, w: 7 },
    mouthY: 39.6, mouthColor: '#6cf0ff',
    smile: (c) => `<path d="M-3.6 -1.2Q0 3 3.6 -1.2" fill="none" stroke="${c}" stroke-width="1.8" stroke-linecap="round"/>`,
    open: (c) => `<ellipse cx="0" cy="1" rx="3" ry="2.8" fill="${c}"/>`,
    cheeks: '<circle class="cheek" cx="19.4" cy="38.6" r="2.6" fill="#ff9db8"/><circle class="cheek" cx="44.6" cy="38.6" r="2.6" fill="#ff9db8"/>',
    dots: '#6a5cf0', spark: '#ffc933'
  },
  {
    id: 'mint', name: 'Mint', bg: '#d6f5ea', accent: '#1f8f7a', tilt: 6, spd: 1.08, blink: 5.2, look: 11,
    ears: '<rect class="ear" x="4.8" y="27.5" width="7" height="13" rx="3.5" fill="#1f8f7a"/><rect class="ear" x="52.2" y="27.5" width="7" height="13" rx="3.5" fill="#1f8f7a"/><circle cx="8.3" cy="34" r="1.5" fill="#8ff0d3"/><circle cx="55.7" cy="34" r="1.5" fill="#8ff0d3"/>',
    ant: '<path d="M32 17.5Q31.6 12.5 34 9" fill="none" stroke="#1f8f7a" stroke-width="2.1" stroke-linecap="round"/><path class="tip" d="M34 9Q33.6 3.4 40 4Q40.4 9.8 34 9Z" fill="#a4f46f"/><path d="M33.2 11.8Q27.6 11.4 27.2 6Q33 5.6 33.2 11.8Z" fill="#7fdc5a"/>',
    head: '<rect x="11" y="16.5" width="42" height="36" rx="13" fill="#5fd8bd" stroke="#29a88c" stroke-width="1.2"/><path d="M14 45Q32 55 50 45L50 47.5Q46 52.5 32 52.5Q18 52.5 14 47.5Z" fill="#1f8f7a" opacity=".22"/><path d="M16.5 21.5Q22 18 29 18.2" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" opacity=".7"/>',
    screen: '<rect x="15.5" y="21" width="33" height="25" rx="9" fill="#0f3a37"/><path d="M19 25Q21 23.2 25 23.2" fill="none" stroke="#fff" stroke-width="1.2" stroke-linecap="round" opacity=".18"/>',
    eyeX: 7.2, eyeY: 31.2,
    eye: () => '<rect x="-4.2" y="-7.4" width="8.4" height="14.8" rx="4.2" fill="#c3ff7e" opacity=".18"/><rect x="-2.7" y="-5.6" width="5.4" height="11.2" rx="2.7" fill="#c3ff7e"/><circle cx="-.9" cy="-2.8" r=".9" fill="#fff" opacity=".8"/>',
    eyeColor: '#c3ff7e', browColor: '#c3ff7e', brow: { dy: -9.2, w: 6 },
    mouthY: 40.4, mouthColor: '#c3ff7e',
    smile: (c) => `<path d="M-3.2 -1Q0 2.6 3.2 -1" fill="none" stroke="${c}" stroke-width="1.8" stroke-linecap="round"/>`,
    open: (c) => `<rect x="-3" y="-1" width="6" height="4.6" rx="2.3" fill="${c}"/>`,
    cheeks: '<ellipse class="cheek" cx="20.2" cy="39.6" rx="2.6" ry="1.5" fill="#ff9a8a"/><ellipse class="cheek" cx="43.8" cy="39.6" rx="2.6" ry="1.5" fill="#ff9a8a"/>',
    dots: '#1f8f7a', spark: '#a4f46f'
  },
  {
    id: 'volt', name: 'Volt', bg: '#ffe6c4', accent: '#e6731a', tilt: 9, spd: .92, blink: 4.1, look: 8,
    ears: '<rect class="ear" x="4.2" y="26" width="4.6" height="10" rx="2" fill="#c25a0e"/><rect class="ear" x="55.2" y="26" width="4.6" height="10" rx="2" fill="#c25a0e"/>',
    ant: '<path class="tip" d="M33.6 17.5L28.8 11.2H32.6L29.6 4.2L37.4 12.4H33.4L36 17.5Z" fill="#ffd23d" stroke="#e6731a" stroke-width=".9" stroke-linejoin="round"/>',
    head: '<rect x="8" y="17" width="48" height="36" rx="12" fill="#ff9330" stroke="#e0701a" stroke-width="1.2"/><path d="M11 46Q32 56 53 46L53 48Q48 53 32 53Q16 53 11 48Z" fill="#b84d08" opacity=".25"/><path d="M14 21.6Q20 18.4 28 18.6" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" opacity=".6"/><circle cx="12.6" cy="47.6" r="1.4" fill="#c25a0e"/><circle cx="51.4" cy="47.6" r="1.4" fill="#c25a0e"/>',
    screen: '<rect x="12" y="22" width="40" height="17" rx="8.5" fill="#1d1530"/><path d="M17 25.4Q19 24 23 24" fill="none" stroke="#fff" stroke-width="1.1" stroke-linecap="round" opacity=".2"/>',
    eyeX: 8.2, eyeY: 30.6,
    eye: () => '<rect x="-6" y="-4.6" width="12" height="9.2" rx="4.6" fill="#ffe14a" opacity=".2"/><rect x="-4.5" y="-3.2" width="9" height="6.4" rx="3.2" fill="#ffe14a"/><rect x="-3.2" y="-2.4" width="3.2" height="1.4" rx=".7" fill="#fff" opacity=".8"/>',
    eyeColor: '#ffe14a', browColor: '#ffe14a', brow: { dy: -5.9, w: 7.5, th: 1.5 },
    mouthY: 44.6, mouthColor: '#4d2406',
    smile: (c) => `<path d="M-4 -1.4Q0 2.8 4 -1.4" fill="none" stroke="${c}" stroke-width="1.9" stroke-linecap="round"/>`,
    open: (c) => `<rect x="-3.4" y="-1.6" width="6.8" height="4.2" rx="1.8" fill="${c}"/>`,
    tongue: '#ff8d5c',
    cheeks: '<circle class="cheek" cx="17.5" cy="44" r="2.4" fill="#ff5e3a" opacity=".7"/><circle class="cheek" cx="46.5" cy="44" r="2.4" fill="#ff5e3a" opacity=".7"/>',
    dots: '#e6731a', spark: '#ffd23d', ao: '50% 100%'
  },
  {
    id: 'orbi', name: 'Orbi', bg: '#fddff0', accent: '#d6459b', tilt: 7, spd: 1.04, blink: 5.6, look: 10,
    ears: '<circle class="ear" cx="8.4" cy="35" r="3.4" fill="#ff7ac8"/><circle class="ear" cx="55.6" cy="35" r="3.4" fill="#ff7ac8"/>',
    ant: '<ellipse class="tip" cx="32" cy="10.6" rx="9.5" ry="3" fill="none" stroke="#ff9bd6" stroke-width="2.4"/><circle cx="40.6" cy="9.6" r="1.4" fill="#fff"/>',
    head: '<ellipse cx="32" cy="34.5" rx="23" ry="19.5" fill="#fdfaff" stroke="#e8c4de" stroke-width="1.2"/><path d="M12 42Q32 58 52 42Q50 53 32 54Q14 53 12 42Z" fill="#d6a0cc" opacity=".3"/><path d="M15 25Q20 18.5 28 17.8" fill="none" stroke="#fff" stroke-width="2.4" stroke-linecap="round"/>',
    screen: '<ellipse cx="32" cy="34" rx="17.8" ry="14.6" fill="#2b1337"/><path d="M19 28Q21.5 24.4 26 23.6" fill="none" stroke="#fff" stroke-width="1.2" stroke-linecap="round" opacity=".2"/>',
    eyeX: 6.8, eyeY: 32,
    eye: () => '<circle r="6" fill="#ff7ac8" opacity=".18"/><circle r="3.6" fill="none" stroke="#ff7ac8" stroke-width="2.4"/><circle r="1.2" fill="#ffd0ec"/>',
    eyeColor: '#ff7ac8', browColor: '#ff9bd6', brow: { dy: -8.6, w: 6.4 },
    mouthY: 40.6, mouthColor: '#ff9bd6',
    smile: (c) => `<path d="M-3.4 -1.2Q0 2.8 3.4 -1.2" fill="none" stroke="${c}" stroke-width="1.8" stroke-linecap="round"/>`,
    open: (c) => `<circle cx="0" cy="1" r="2.7" fill="${c}"/>`,
    cheeks: '<circle class="cheek" cx="20.4" cy="39.4" r="2.4" fill="#ff5fa8" opacity=".6"/><circle class="cheek" cx="43.6" cy="39.4" r="2.4" fill="#ff5fa8" opacity=".6"/>',
    dots: '#d6459b', spark: '#ffb8e3', ao: '50% 50%'
  },
  {
    id: 'pixo', name: 'Pixo', bg: '#1f2b3d', accent: '#7dff6a', tilt: 6, spd: .96, blink: 4.8, look: 9, pixel: true,
    ears: '<rect class="ear" x="5.4" y="27" width="4.6" height="10" rx="1" fill="#566178"/><rect class="ear" x="54" y="27" width="4.6" height="10" rx="1" fill="#566178"/>',
    ant: '<rect x="31.2" y="10.2" width="1.7" height="7.4" fill="#aab3c6"/><rect class="tip" x="28.8" y="4.6" width="6.4" height="6.4" rx="1.2" fill="#ff5d73"/><rect x="30" y="5.8" width="2" height="2" fill="#fff" opacity=".8"/>',
    head: '<rect x="10" y="16.5" width="44" height="37" rx="6" fill="#aeb7c9" stroke="#6f7a90" stroke-width="1.3"/><rect x="10.6" y="46" width="42.8" height="6.8" rx="5" fill="#6f7a90" opacity=".35"/><path d="M14 20.4H25" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="square" opacity=".7"/>',
    screen: '<rect x="14.5" y="21" width="35" height="26" rx="3" fill="#0c1a12"/><path d="M15 26H49M15 31H49M15 36H49M15 41H49" stroke="#7dff6a" stroke-width=".5" opacity=".1"/>',
    eyeX: 7.6, eyeY: 31,
    eye: () => '<rect x="-4" y="-4.6" width="8" height="9.2" rx="1" fill="#7dff6a" opacity=".18"/><rect x="-3" y="-3.6" width="6" height="7.2" rx=".8" fill="#7dff6a"/><rect x="-2.2" y="-2.8" width="1.8" height="1.8" fill="#fff" opacity=".85"/>',
    eyeColor: '#7dff6a', browColor: '#7dff6a', brow: { dy: -8, w: 6.5, th: 1.7 },
    mouthY: 39.4, mouthColor: '#7dff6a',
    smile: (c) => `<path d="M-5 -1.6V.4H-3V2.4H3V.4H5V-1.6" fill="none" stroke="${c}" stroke-width="1.7" stroke-linejoin="miter" stroke-linecap="square"/>`,
    open: (c) => `<rect x="-3.8" y="-1" width="7.6" height="4" rx=".6" fill="${c}"/>`,
    cheeks: '<rect class="cheek" x="17" y="37.6" width="3.2" height="3.2" fill="#ff7a8a" opacity=".85"/><rect class="cheek" x="43.8" y="37.6" width="3.2" height="3.2" fill="#ff7a8a" opacity=".85"/>',
    dots: '#7dff6a', spark: '#7dff6a'
  },
  {
    id: 'lumo', name: 'Lumo', bg: '#d6e9ff', accent: '#3d86e0', tilt: 7, spd: 1.1, blink: 5, look: 12,
    ears: '<circle class="ear" cx="7.6" cy="33.5" r="3.8" fill="#4a90e8"/><circle class="ear" cx="56.4" cy="33.5" r="3.8" fill="#4a90e8"/><circle cx="7.6" cy="33.5" r="1.6" fill="#c6e3ff"/><circle cx="56.4" cy="33.5" r="1.6" fill="#c6e3ff"/>',
    ant: '<path d="M22.5 18.6Q18.8 12.6 21 8" fill="none" stroke="#4a90e8" stroke-width="2.1" stroke-linecap="round"/><path class="tip" d="M21 1.8L22.2 5.6L26 6.8L22.2 8L21 11.8L19.8 8L16 6.8L19.8 5.6Z" fill="#ffd75e"/>',
    head: '<rect x="9" y="16.5" width="46" height="36" rx="18" fill="#74b6ff" stroke="#4a90e8" stroke-width="1.2"/><path d="M13 44Q32 56 51 44Q50 52.5 32 52.5Q14 52.5 13 44Z" fill="#2c6cc4" opacity=".22"/><path d="M16 21.6Q22 17.6 30 17.8" fill="none" stroke="#fff" stroke-width="2.3" stroke-linecap="round" opacity=".7"/>',
    screen: '<rect x="14" y="21" width="36" height="25" rx="12.5" fill="#151f4a"/><path d="M19 26Q21 23.4 25 23" fill="none" stroke="#fff" stroke-width="1.2" stroke-linecap="round" opacity=".18"/>',
    eyeX: 7.6, eyeY: 31.2,
    eye: () => '<path d="M-6.4 4A6.4 6.4 0 0 1 6.4 4Z" fill="#ffd75e" opacity=".2"/><path d="M-4.6 3.4A4.6 4.6 0 0 1 4.6 3.4Z" fill="#ffd75e"/><circle cx="-1.6" cy="0" r=".9" fill="#fff" opacity=".8"/>',
    eyeColor: '#ffd75e', browColor: '#ffeaa8', brow: { dy: -7.2, w: 6.5 },
    mouthY: 40.2, mouthColor: '#ffeaa8',
    smile: (c) => `<path d="M-3.4 -1Q0 3 3.4 -1" fill="none" stroke="${c}" stroke-width="1.8" stroke-linecap="round"/>`,
    open: (c) => `<path d="M-3.4 -.6H3.4A3.4 3.8 0 0 1 -3.4 -.6Z" fill="${c}"/>`,
    cheeks: '<circle class="cheek" cx="19.6" cy="38.8" r="2.7" fill="#ff9db8" opacity=".85"/><circle class="cheek" cx="44.4" cy="38.8" r="2.7" fill="#ff9db8" opacity=".85"/>',
    dots: '#3d86e0', spark: '#ffd75e', ao: '50% 100%'
  },
  {
    id: 'echo', name: 'Echo', bg: '#ebe2ff', accent: '#7a5ce0', tilt: 7, spd: 1, blink: 4.4, look: 9.5,
    ears: '<ellipse class="ear" cx="7.4" cy="34" rx="4" ry="7.4" fill="#c7b3ff"/><ellipse class="ear" cx="56.6" cy="34" rx="4" ry="7.4" fill="#c7b3ff"/><ellipse cx="7.4" cy="34" rx="1.8" ry="4.4" fill="#8d74e8"/><ellipse cx="56.6" cy="34" rx="1.8" ry="4.4" fill="#8d74e8"/>',
    ant: '<path d="M32 9.4V5.6" stroke="#c7b3ff" stroke-width="2" stroke-linecap="round"/><circle class="tip" cx="32" cy="4.4" r="2.5" fill="#ff8fb1"/>',
    head: '<path d="M11 30C11 2.6 53 2.6 53 30" fill="none" stroke="#c7b3ff" stroke-width="3.2" stroke-linecap="round"/><rect x="10" y="16.5" width="44" height="37" rx="16" fill="#4a3a8c" stroke="#2f2461" stroke-width="1.2"/><path d="M14 45Q32 56 50 45Q48 53 32 53Q16 53 14 45Z" fill="#1d1445" opacity=".35"/><path d="M16.5 22Q22 18 30 18.2" fill="none" stroke="#d9ccff" stroke-width="2.2" stroke-linecap="round" opacity=".7"/>',
    screen: '<rect x="14.5" y="21.5" width="35" height="24" rx="12" fill="#120d2b"/><path d="M19 26.4Q21 24 25 23.6" fill="none" stroke="#fff" stroke-width="1.2" stroke-linecap="round" opacity=".16"/>',
    eyeX: 7.4, eyeY: 31,
    eye: () => '<circle r="6.2" fill="#ff8fb1" opacity=".2"/><circle r="4.6" fill="#ff8fb1"/><circle cx="-1.6" cy="-1.8" r="1.8" fill="#fff" opacity=".9"/><circle cx="1.6" cy="1.9" r=".8" fill="#fff" opacity=".7"/>',
    eyeColor: '#ff8fb1', browColor: '#ff8fb1', brow: { dy: -8.6, w: 6.5 },
    mouthY: 39.8, mouthColor: '#ff8fb1',
    smile: (c) => `<path d="M-3.6 -1.2Q0 3 3.6 -1.2" fill="none" stroke="${c}" stroke-width="1.8" stroke-linecap="round"/>`,
    open: (c) => `<g class="bars"><rect class="bar" x="-4.2" y="-1.6" width="2" height="5.2" rx="1" fill="${c}"/><rect class="bar" x="-1" y="-2.4" width="2" height="6.8" rx="1" fill="${c}"/><rect class="bar" x="2.2" y="-1.6" width="2" height="5.2" rx="1" fill="${c}"/></g>`,
    cheeks: '<circle class="cheek" cx="19.6" cy="38.6" r="2.4" fill="#ff8fb1" opacity=".5"/><circle class="cheek" cx="44.4" cy="38.6" r="2.4" fill="#ff8fb1" opacity=".5"/>',
    dots: '#7a5ce0', spark: '#ff8fb1'
  },
  {
    id: 'blip', name: 'Blip', bg: '#ffe1dc', accent: '#e0493c', tilt: 10, spd: .9, blink: 4.3, look: 8.5, brows: true,
    ears: '<circle class="ear" cx="7.6" cy="34" r="3.3" fill="#ffb3a8"/><circle class="ear" cx="56.4" cy="34" r="3.3" fill="#ffb3a8"/>',
    ant: '<path d="M27.6 17.6L23.6 7.6M36.4 17.6L40.4 7.6" fill="none" stroke="#c8453a" stroke-width="1.8" stroke-linecap="round"/><circle class="tip" cx="23.2" cy="6.6" r="2.6" fill="#ffd23d"/><circle class="tip" cx="40.8" cy="6.6" r="2.6" fill="#ffd23d"/>',
    head: '<rect x="9" y="16.5" width="46" height="36.5" rx="14" fill="#ff6f61" stroke="#e0493c" stroke-width="1.2"/><path d="M12 45Q32 56 52 45Q50 53 32 53Q14 53 12 45Z" fill="#a82c20" opacity=".25"/><path d="M15 21.4Q21 17.6 29 17.8" fill="none" stroke="#fff" stroke-width="2.2" stroke-linecap="round" opacity=".6"/>',
    screen: '<rect x="14" y="21" width="36" height="25" rx="10" fill="#fff6ea" stroke="#ffd6c4" stroke-width="1"/>',
    eyeX: 7.4, eyeY: 31.6, eyeRot: 10,
    eye: (s) => `<ellipse rx="3.2" ry="4.4" transform="rotate(${s * 10})" fill="#20295c"/><circle cx="${s * -1 - .4}" cy="-1.8" r="1" fill="#fff" opacity=".9"/>`,
    eyeColor: '#20295c', browColor: '#20295c', brow: { dy: -7.8, w: 7, th: 2.1 },
    mouthY: 40.2, mouthColor: '#20295c', tongue: '#ff7a8a',
    smile: (c) => `<path d="M-3.6 -1.2Q0 3 3.6 -1.2" fill="none" stroke="${c}" stroke-width="1.9" stroke-linecap="round"/>`,
    open: (c) => `<ellipse cx="0" cy="1" rx="3" ry="2.8" fill="${c}"/>`,
    cheeks: '<circle class="cheek" cx="19.4" cy="38.8" r="2.7" fill="#ff9c8f" opacity=".7"/><circle class="cheek" cx="44.6" cy="38.8" r="2.7" fill="#ff9c8f" opacity=".7"/>',
    dots: '#e0493c', spark: '#ffb703', starC: '#ffb703'
  }
];

/* ---------- shared CSS (identical keyframes in every file; per-avatar variance through CSS variables) ---------- */
function css(a) {
  const P = 'svg.ac-' + a.id;
  const D = (s) => `calc(${s}s * var(--spd))`;
  return `
${P}{--tilt:${a.tilt}deg;--spd:${a.spd};--b0:${a.brows ? 1 : 0}}
${P} *{transform-box:fill-box;transform-origin:50% 50%}
${P} .bob{transform-origin:50% 100%;animation:ac-breath 4.2s ease-in-out infinite}
${P} .head{transform-origin:50% 100%;animation:ac-sway 6.5s ease-in-out infinite}
${P} .face{animation:ac-face ${a.look}s ease-in-out infinite}
${P} .look{animation:ac-look ${a.look}s ease-in-out infinite}
${P} .eye{animation:ac-blink ${a.blink}s infinite}
${P} .ant{transform-origin:${a.ao || '50% 100%'}}
${P} .e-happy,${P} .e-heart,${P} .e-star,${P} .m-big,${P} .m-o,${P} .m-open,${P} .hearts,${P} .spark,${P} .think{opacity:0}
${P} .brow{opacity:${a.brows ? 1 : 0}}
${P} .m-open{transform:scaleY(.3)}
${P} .ear{transform-origin:50% 50%}
${P} .think circle{fill:${a.dots}}
${P} .spark path{fill:${a.spark}}
/* states */
${P}.is-listening .head{animation:ac-tilt 2.4s ease-in-out infinite}
${P}.is-listening .look,${P}.is-listening .face{animation:none}
${P}.is-listening .eyes{transform:scale(1.12)}
${P}.is-listening .brow{opacity:1;transform:translateY(-1px)}
${P}.is-listening .ear{animation:ac-ear 1.1s ease-in-out infinite}
${P}.is-listening .ant{animation:ac-antwig 1.6s ease-in-out infinite}
${P}.is-thinking .head{animation:ac-think-head 2.8s ease-in-out infinite}
${P}.is-thinking .look{animation:ac-think-look 2.6s ease-in-out infinite}
${P}.is-thinking .face{animation:ac-think-face 2.6s ease-in-out infinite}
${P}.is-thinking .m-smile{transform:scaleX(.5) translateX(2px)}
${P}.is-thinking .browR{opacity:1;transform:translateY(-1.8px) rotate(-8deg)}
${P}.is-thinking .browL{opacity:1;transform:translateY(.4px)}
${P}.is-thinking .think{opacity:1}
${P}.is-thinking .think circle{animation:ac-dot 1.2s ease-in-out infinite}
${P}.is-thinking .think circle:nth-child(2){animation-delay:.2s}
${P}.is-thinking .think circle:nth-child(3){animation-delay:.4s}
${P}.is-thinking .tip{animation:ac-glow 1s ease-in-out infinite}
${P}.is-talking .bob{animation:ac-talkbob .9s ease-in-out infinite}
${P}.is-talking .head{animation:ac-nod 1.4s ease-in-out infinite}
${P}.is-talking .face{animation:none}
${P}.is-talking .m-smile{opacity:0}
${P}.is-talking .m-open{opacity:1;animation:ac-talk .34s ease-in-out infinite}
${P}.is-talking .bar:nth-child(1){animation:ac-bar .3s ease-in-out infinite}
${P}.is-talking .bar:nth-child(2){animation:ac-bar .24s ease-in-out infinite .06s}
${P}.is-talking .bar:nth-child(3){animation:ac-bar .36s ease-in-out infinite .12s}
${P}.is-talking .ear{animation:ac-earpulse .5s ease-in-out infinite}
${P}.is-talking .ant{animation:ac-antwig 1.2s ease-in-out infinite}
${P}.is-happy .bob{animation:ac-hop .6s ease-out 2}
${P}.is-happy .head{animation:ac-sway 1.2s ease-in-out infinite}
${P}.is-happy .e-base{opacity:0}
${P}.is-happy .e-happy{opacity:1}
${P}.is-happy .eye{animation:none}
${P}.is-happy .m-smile{opacity:0}
${P}.is-happy .m-big{opacity:1}
${P}.is-happy .cheek{opacity:1}
${P}.is-happy .spark{opacity:1;animation:ac-twinkle .9s ease-in-out 2}
${P}.is-happy .ant{animation:ac-wave 1.4s ease-in-out 1}
/* attention gestures */
${P}.is-attn-1 .bob{animation:ac-g1-bob ${D(1.7)} ease-in-out 1}
${P}.is-attn-1 .head{animation:ac-g1-head ${D(1.7)} ease-in-out 1}
${P}.is-attn-1 .eyeR .e-base{animation:ac-outmid ${D(1.7)} 1}
${P}.is-attn-1 .eyeR .e-happy{animation:ac-inmid ${D(1.7)} 1}
${P}.is-attn-1 .eyeR .eye{animation:none}
${P}.is-attn-1 .m-smile{animation:ac-g1-smile ${D(1.7)} ease-in-out 1}
${P}.is-attn-1 .cheek{animation:ac-cheek ${D(1.7)} 1}
${P}.is-attn-1 .spark{animation:ac-sparkmid ${D(1.7)} 1}
${P}.is-attn-2 .bob{animation:ac-g2-bob ${D(1.9)} ease-out 1}
${P}.is-attn-2 .eyes{animation:ac-g2-eyes ${D(1.9)} ease-out 1}
${P}.is-attn-2 .brow{animation:ac-g2-brow ${D(1.9)} ease-out 1}
${P}.is-attn-2 .m-smile{animation:ac-outmid ${D(1.9)} 1}
${P}.is-attn-2 .m-o{animation:ac-inmid ${D(1.9)} 1}
${P}.is-attn-2 .ant{animation:ac-jolt ${D(1.9)} ease-out 1}
${P}.is-attn-2 .ear{animation:ac-earpop ${D(1.9)} ease-out 1}
${P}.is-attn-3 .bob{animation:ac-laugh .3s ease-in-out 6}
${P}.is-attn-3 .head{animation:ac-wobble .6s ease-in-out 3}
${P}.is-attn-3 .e-base{animation:ac-outmid ${D(1.8)} 1}
${P}.is-attn-3 .e-happy{animation:ac-inmid ${D(1.8)} 1}
${P}.is-attn-3 .eye{animation:none}
${P}.is-attn-3 .m-smile{animation:ac-outmid ${D(1.8)} 1}
${P}.is-attn-3 .m-big{animation:ac-inmid ${D(1.8)} 1}
${P}.is-attn-3 .mouth{animation:ac-jaw .3s ease-in-out 6}
${P}.is-attn-3 .cheek{animation:ac-cheek ${D(1.8)} 1}
${P}.is-attn-3 .ear{animation:ac-earpulse .3s ease-in-out 6}
${P}.is-attn-4 .bob{animation:ac-g4-bob ${D(2)} ease-in-out 1}
${P}.is-attn-4 .head{animation:ac-lovesway ${D(2)} ease-in-out 1}
${P}.is-attn-4 .e-base{animation:ac-outmid ${D(2)} 1}
${P}.is-attn-4 .e-heart{animation:ac-inmid ${D(2)} 1,ac-beat .5s ease-in-out 3}
${P}.is-attn-4 .eye{animation:none}
${P}.is-attn-4 .hearts{animation:ac-inmid ${D(2)} 1}
${P}.is-attn-4 .hearts path{animation:ac-heartup 1.1s ease-out 1 both}
${P}.is-attn-4 .hearts path:nth-child(2){animation-delay:.35s}
${P}.is-attn-4 .hearts path:nth-child(3){animation-delay:.7s}
${P}.is-attn-4 .m-smile{animation:ac-g1-smile ${D(2)} ease-in-out 1}
${P}.is-attn-4 .cheek{animation:ac-cheek ${D(2)} 1}
${P}.is-attn-5 .head{animation:ac-g5-head ${D(2.2)} ease-in-out 1}
${P}.is-attn-5 .look{animation:ac-g5-look ${D(2.2)} ease-in-out 1}
${P}.is-attn-5 .face{animation:ac-g5-face ${D(2.2)} ease-in-out 1}
${P}.is-attn-5 .browR{animation:ac-g5-brow ${D(2.2)} ease-in-out 1}
${P}.is-attn-5 .m-smile{animation:ac-g5-mouth ${D(2.2)} ease-in-out 1}
${P}.is-attn-5 .bob{animation:ac-g5-bob ${D(2.2)} ease-in-out 1}
${P}.is-attn-6 .bob{animation:ac-g6-bob ${D(2)} ease-in-out 1}
${P}.is-attn-6 .ant{animation:ac-wave ${D(1.5)} ease-in-out 1}
${P}.is-attn-6 .tip{animation:ac-glow .5s ease-in-out 3}
${P}.is-attn-6 .e-base{animation:ac-outmid ${D(2)} 1}
${P}.is-attn-6 .e-star{animation:ac-inmid ${D(2)} 1,ac-twinkle .6s ease-in-out 3}
${P}.is-attn-6 .eye{animation:none}
${P}.is-attn-6 .spark{animation:ac-sparkmid ${D(2)} 1}
${P}.is-attn-6 .m-smile{animation:ac-g1-smile ${D(2)} ease-in-out 1}
${P}.is-attn-6 .head{animation:ac-g6-head ${D(2)} ease-in-out 1}
@keyframes ac-breath{0%,100%{transform:scale(1,1)}50%{transform:scale(.99,1.035)}}
@keyframes ac-sway{0%,100%{transform:rotate(-1.6deg)}50%{transform:rotate(1.6deg)}}
@keyframes ac-blink{0%,90%,100%{transform:scaleY(1)}93%{transform:scaleY(.08)}96%{transform:scaleY(1)}}
@keyframes ac-look{0%,10%,44%,100%{transform:translate(0,0)}16%,32%{transform:translate(-2.2px,-.6px)}50%,64%{transform:translate(2.4px,.5px)}70%,76%{transform:translate(.6px,-1.6px)}82%{transform:translate(0,0)}}
@keyframes ac-face{0%,10%,44%,100%{transform:translate(0,0)}16%,32%{transform:translate(-.9px,-.2px)}50%,64%{transform:translate(1px,.2px)}70%,76%{transform:translate(.3px,-.6px)}82%{transform:translate(0,0)}}
@keyframes ac-tilt{0%,100%{transform:rotate(calc(var(--tilt) * -.6))}50%{transform:rotate(calc(var(--tilt) * .5))}}
@keyframes ac-ear{0%,100%{transform:scale(1,1)}50%{transform:scale(1.14,1.22)}}
@keyframes ac-earpulse{0%,100%{transform:scale(1,1)}50%{transform:scale(1.12,1.1)}}
@keyframes ac-earpop{0%,15%{transform:scale(1)}25%,65%{transform:scale(1.25,1.3)}100%{transform:scale(1)}}
@keyframes ac-antwig{0%,100%{transform:rotate(-4deg)}50%{transform:rotate(5deg)}}
@keyframes ac-think-head{0%,100%{transform:rotate(calc(var(--tilt) * -.6))}50%{transform:rotate(calc(var(--tilt) * -.25))}}
@keyframes ac-think-look{0%,100%{transform:translate(-2.4px,-2.4px)}50%{transform:translate(2.4px,-2.6px)}}
@keyframes ac-think-face{0%,100%{transform:translate(-1px,-.8px)}50%{transform:translate(1px,-.9px)}}
@keyframes ac-dot{0%,100%{opacity:.25;transform:translateY(0)}50%{opacity:1;transform:translateY(-2px)}}
@keyframes ac-glow{0%,100%{opacity:.55;transform:scale(1)}50%{opacity:1;transform:scale(1.25)}}
@keyframes ac-talk{0%,100%{transform:scaleY(.3)}50%{transform:scaleY(1.12)}}
@keyframes ac-bar{0%,100%{transform:scaleY(.4)}50%{transform:scaleY(1.2)}}
@keyframes ac-talkbob{0%,100%{transform:translateY(0) scale(1,1)}50%{transform:translateY(-.9px) scale(.99,1.02)}}
@keyframes ac-nod{0%,100%{transform:rotate(-1.8deg)}25%{transform:rotate(1.6deg)}50%{transform:rotate(-.6deg)}75%{transform:rotate(1.8deg)}}
@keyframes ac-hop{0%{transform:translateY(0) scale(1.05,.93)}35%{transform:translateY(-3.6px) scale(.95,1.08)}70%{transform:translateY(0) scale(1.05,.94)}100%{transform:translateY(0) scale(1,1)}}
@keyframes ac-twinkle{0%,100%{transform:scale(.55) rotate(0)}50%{transform:scale(1.2) rotate(25deg)}}
@keyframes ac-wave{0%{transform:rotate(0)}15%{transform:rotate(24deg)}32%{transform:rotate(-20deg)}50%{transform:rotate(15deg)}68%{transform:rotate(-9deg)}84%{transform:rotate(4deg)}100%{transform:rotate(0)}}
@keyframes ac-jolt{0%,12%{transform:rotate(0)}20%{transform:rotate(-26deg)}32%{transform:rotate(18deg)}46%{transform:rotate(-10deg)}62%{transform:rotate(5deg)}100%{transform:rotate(0)}}
@keyframes ac-cheek{0%,8%{transform:scale(1)}16%,84%{transform:scale(1.4)}92%,100%{transform:scale(1)}}
@keyframes ac-outmid{0%,8%{opacity:1}16%,84%{opacity:0}92%,100%{opacity:1}}
@keyframes ac-inmid{0%,8%{opacity:0}16%,84%{opacity:1}92%,100%{opacity:0}}
@keyframes ac-sparkmid{0%,8%{opacity:0;transform:scale(.4)}20%{opacity:1;transform:scale(1.2) rotate(20deg)}34%{transform:scale(.8) rotate(0)}50%{transform:scale(1.15) rotate(20deg)}66%{transform:scale(.85)}84%{opacity:1;transform:scale(1)}100%{opacity:0;transform:scale(.4)}}
@keyframes ac-g1-bob{0%,10%{transform:translateY(0) scale(1,1)}22%{transform:translateY(0) scale(1.04,.95)}36%{transform:translateY(-2px) scale(.97,1.05)}52%{transform:translateY(0) scale(1.02,.98)}100%{transform:translateY(0) scale(1,1)}}
@keyframes ac-g1-head{0%,10%{transform:rotate(0)}26%,70%{transform:rotate(calc(var(--tilt) * -1.1))}100%{transform:rotate(0)}}
@keyframes ac-g1-smile{0%,10%{transform:scale(1)}24%,72%{transform:scale(1.3) translateY(.6px)}100%{transform:scale(1)}}
@keyframes ac-g2-bob{0%{transform:scale(1,1)}8%{transform:scale(1.1,.88)}20%{transform:translateY(-2.4px) scale(.9,1.14)}34%{transform:translateY(0) scale(1.04,.96)}50%,72%{transform:scale(.97,1.05)}86%{transform:scale(1.02,.98)}100%{transform:scale(1,1)}}
@keyframes ac-g2-eyes{0%,14%{transform:scale(1)}24%,72%{transform:scale(1.32)}88%,100%{transform:scale(1)}}
@keyframes ac-g2-brow{0%,14%{opacity:var(--b0);transform:translateY(0)}24%,72%{opacity:1;transform:translateY(-2px)}88%,100%{opacity:var(--b0);transform:translateY(0)}}
@keyframes ac-laugh{0%,100%{transform:translateY(0) scale(1.03,.96)}50%{transform:translateY(-1.8px) scale(.98,1.04)}}
@keyframes ac-wobble{0%,100%{transform:rotate(calc(var(--tilt) * -.5))}50%{transform:rotate(calc(var(--tilt) * .5))}}
@keyframes ac-jaw{0%,100%{transform:translateY(0) scaleY(1)}50%{transform:translateY(.7px) scaleY(1.12)}}
@keyframes ac-g4-bob{0%,100%{transform:scale(1,1)}25%{transform:scale(1.03,.97)}50%{transform:translateY(-1.5px) scale(.98,1.03)}75%{transform:scale(1.03,.97)}}
@keyframes ac-lovesway{0%,100%{transform:rotate(0)}20%{transform:rotate(calc(var(--tilt) * -.8))}45%{transform:rotate(calc(var(--tilt) * .8))}70%{transform:rotate(calc(var(--tilt) * -.6))}85%{transform:rotate(0)}}
@keyframes ac-beat{0%,100%{transform:scale(1)}50%{transform:scale(1.28)}}
@keyframes ac-heartup{0%{opacity:0;transform:translateY(4px) scale(.5)}25%{opacity:1}100%{opacity:0;transform:translateY(-10px) scale(1.15)}}
@keyframes ac-g5-head{0%,8%{transform:rotate(0)}22%,40%{transform:rotate(calc(var(--tilt) * -.9))}52%,66%{transform:rotate(calc(var(--tilt) * .9))}80%{transform:rotate(0)}100%{transform:rotate(0)}}
@keyframes ac-g5-look{0%,8%{transform:translate(0,0)}20%,38%{transform:translate(-3.2px,-.4px)}50%,64%{transform:translate(3.2px,-.4px)}74%,86%{transform:translate(0,-3px)}96%,100%{transform:translate(0,0)}}
@keyframes ac-g5-face{0%,8%{transform:translate(0,0)}20%,38%{transform:translate(-1.4px,0)}50%,64%{transform:translate(1.4px,0)}74%,86%{transform:translate(0,-1px)}96%,100%{transform:translate(0,0)}}
@keyframes ac-g5-brow{0%,10%{opacity:var(--b0);transform:translateY(0)}24%,78%{opacity:1;transform:translateY(-2.2px) rotate(-8deg)}92%,100%{opacity:var(--b0);transform:translateY(0)}}
@keyframes ac-g5-mouth{0%,10%{transform:scaleX(1) translateX(0)}22%,38%{transform:scaleX(.6) translateX(-1.4px)}50%,64%{transform:scaleX(.6) translateX(1.4px)}80%,100%{transform:scaleX(1) translateX(0)}}
@keyframes ac-g5-bob{0%,100%{transform:scale(1,1)}50%{transform:scale(1.01,.99)}}
@keyframes ac-g6-bob{0%,8%{transform:scale(1,1)}16%{transform:translateY(0) scale(1.07,.9)}28%{transform:translateY(-3.2px) scale(.95,1.09)}40%{transform:translateY(0) scale(1.05,.94)}52%{transform:translateY(-1.8px) scale(.98,1.04)}64%{transform:translateY(0) scale(1.02,.98)}100%{transform:scale(1,1)}}
@keyframes ac-g6-head{0%,100%{transform:rotate(0)}30%{transform:rotate(calc(var(--tilt) * .7))}60%{transform:rotate(calc(var(--tilt) * -.5))}}
@media (prefers-reduced-motion:reduce){${P} *{animation:none!important;transition:none!important}}`.replace(/\n/g, '\n');
}

/* ---------- build one SVG ---------- */
function brow(a, s) {
  const b = a.brow, th = b.th || 1.6, y = a.eyeY + b.dy, x = 32 + s * a.eyeX;
  return `<g transform="translate(${f(x)} ${f(y)})"><g class="brow ${s < 0 ? 'browL' : 'browR'}"><path d="M${f(-b.w / 2)} 0L${f(b.w / 2)} 0" stroke="${a.browColor}" stroke-width="${th}" stroke-linecap="round" fill="none"/></g></g>`;
}
function eyeG(a, s) {
  const x = 32 + s * a.eyeX;
  const base = a.eye(s);
  return `<g transform="translate(${f(x)} ${f(a.eyeY)})"><g class="${s < 0 ? 'eyeL' : 'eyeR'}"><g class="eye">` +
    `<g class="e-base">${base}</g><g class="e-happy">${happyArc(a.eyeColor)}</g><g class="e-heart">${heart}</g><g class="e-star">${star(a.starC)}</g></g></g></g>`;
}
function build(a) {
  const mc = a.mouthColor;
  const css1 = css(a).replace(/^\s+/, '');
  let svg = `<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 64 64" class="ac-${a.id} is-idle" data-gestures="6" focusable="false" aria-hidden="true"><style>${css1}</style>`;
  svg += `<circle cx="32" cy="32" r="32" fill="${a.bg}"/>`;
  svg += `<g class="think"><circle cx="45" cy="9" r="2"/><circle cx="51" cy="12.8" r="2"/><circle cx="55.6" cy="18.6" r="2"/></g>`;
  svg += `<g class="bob"><g class="head"><g class="ears">${a.ears}</g><g class="ant">${a.ant}</g>${a.head}${a.screen}`;
  svg += `<g class="face"><g class="look"><g class="eyes">${eyeG(a, -1)}${eyeG(a, 1)}</g></g>${brow(a, -1)}${brow(a, 1)}${a.cheeks}`;
  svg += `<g transform="translate(32 ${a.mouthY})"><g class="mouth"><g class="m-smile">${a.smile(mc)}</g><g class="m-open">${a.open(mc)}</g><g class="m-big">${mBig(mc, a.tongue)}</g><g class="m-o">${mO(mc)}</g></g></g>`;
  svg += `</g></g></g>`;
  svg += `<g class="hearts"><path d="M12 17c-3-2-3.2-5-1.2-5 .8 0 1.2.6 1.2 1.1 0-.5.4-1.1 1.2-1.1 2 0 1.8 3-1.2 5Z" fill="${HEART}"/><path d="M52 15c-3-2-3.2-5-1.2-5 .8 0 1.2.6 1.2 1.1 0-.5.4-1.1 1.2-1.1 2 0 1.8 3-1.2 5Z" fill="${HEART}"/><path d="M32 9c-2.6-1.7-2.8-4.4-1-4.4.7 0 1 .5 1 1 0-.5.4-1 1-1 1.8 0 1.6 2.7-1 4.4Z" fill="${HEART}"/></g>`;
  svg += `<g class="spark"><path d="M10 12L11.2 14.8L14 16L11.2 17.2L10 20L8.8 17.2L6 16L8.8 14.8Z"/><path d="M54 6.4L55 8.8L57.4 9.8L55 10.8L54 13.2L53 10.8L50.6 9.8L53 8.8Z"/></g>`;
  svg += `</svg>\n`;
  return svg;
}

for (const f1 of fs.readdirSync(out)) { if (/\.svg$/.test(f1)) { fs.unlinkSync(path.join(out, f1)); } }
AVATARS.forEach((a) => fs.writeFileSync(path.join(out, a.id + '.svg'), build(a)));
fs.writeFileSync(path.join(out, 'manifest.json'), JSON.stringify(AVATARS.map((a) => ({ id: a.id, name: a.name })), null, 2) + '\n');
console.log('wrote ' + AVATARS.length + ' avatars to ' + out);
