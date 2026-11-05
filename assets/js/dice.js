// Random Journey: the twenty-sided dice on the Spin page (one, or two when rolling with advantage).
// Loaded only when WebGL works and the visitor hasn't asked for reduced motion.
// The server has already picked the result; the die just lands on that face.
import * as THREE from '../vendor/three/three.module.min.js';

const COLS = 5;
const ROWS = 4;
const CELL = 256;

/** Texture atlas: one triangle per face with its country code. */
function faceAtlas(faces) {
    const canvas = document.createElement('canvas');
    canvas.width = COLS * CELL;
    canvas.height = ROWS * CELL;
    const ctx = canvas.getContext('2d');
    ctx.fillStyle = '#3d1418';
    ctx.fillRect(0, 0, canvas.width, canvas.height);

    faces.forEach((code, i) => {
        const x0 = (i % COLS) * CELL;
        const y0 = Math.floor(i / COLS) * CELL;
        const apex = [x0 + CELL * 0.5, y0 + CELL * 0.08];
        const left = [x0 + CELL * 0.06, y0 + CELL * 0.86];
        const right = [x0 + CELL * 0.94, y0 + CELL * 0.86];

        // Leather-red face with a darker vignette
        const g = ctx.createRadialGradient(x0 + CELL / 2, y0 + CELL * 0.6, 10, x0 + CELL / 2, y0 + CELL * 0.6, CELL * 0.55);
        g.addColorStop(0, '#7a2a30');
        g.addColorStop(1, '#4a171b');
        ctx.fillStyle = g;
        ctx.beginPath();
        ctx.moveTo(...apex);
        ctx.lineTo(...left);
        ctx.lineTo(...right);
        ctx.closePath();
        ctx.fill();

        // Gilt inner border
        ctx.strokeStyle = 'rgba(222, 186, 98, 0.85)';
        ctx.lineWidth = 3;
        const inset = (p) => [p[0] + (x0 + CELL / 2 - p[0]) * 0.12, p[1] + (y0 + CELL * 0.6 - p[1]) * 0.12];
        ctx.beginPath();
        ctx.moveTo(...inset(apex));
        ctx.lineTo(...inset(left));
        ctx.lineTo(...inset(right));
        ctx.closePath();
        ctx.stroke();

        // Country code, gold leaf
        const tg = ctx.createLinearGradient(0, y0 + CELL * 0.45, 0, y0 + CELL * 0.72);
        tg.addColorStop(0, '#fff2c4');
        tg.addColorStop(0.5, '#e2bb5c');
        tg.addColorStop(1, '#a57a26');
        ctx.fillStyle = tg;
        ctx.font = `700 ${CELL * 0.2}px Cinzel, Georgia, serif`;
        ctx.textAlign = 'center';
        ctx.textBaseline = 'middle';
        ctx.shadowColor = 'rgba(0,0,0,0.6)';
        ctx.shadowBlur = 6;
        ctx.fillText(code, x0 + CELL / 2, y0 + CELL * 0.6);
        ctx.shadowBlur = 0;

        // Face number, small, under the code
        ctx.fillStyle = 'rgba(234, 205, 140, 0.7)';
        ctx.font = `600 ${CELL * 0.07}px Cinzel, Georgia, serif`;
        ctx.fillText(String(i + 1), x0 + CELL / 2, y0 + CELL * 0.76);
    });
    return canvas;
}

/** Icosahedron whose faces each map to one atlas cell (apex = first vertex). */
function d20Geometry(radius) {
    const geo = new THREE.IcosahedronGeometry(radius, 0); // non-indexed: 20 faces x 3 vertices
    const uv = new Float32Array(20 * 3 * 2);
    for (let i = 0; i < 20; i++) {
        const u0 = (i % COLS) / COLS;
        const v1 = 1 - Math.floor(i / COLS) / ROWS; // top of the cell (textures are flipped)
        const cw = 1 / COLS;
        const ch = 1 / ROWS;
        const pts = [[0.5, 0.08], [0.06, 0.86], [0.94, 0.86]];
        pts.forEach(([px, py], k) => {
            uv[(i * 3 + k) * 2] = u0 + px * cw;
            uv[(i * 3 + k) * 2 + 1] = v1 - py * ch;
        });
    }
    geo.setAttribute('uv', new THREE.BufferAttribute(uv, 2));
    geo.computeVertexNormals();
    return geo;
}

/** Rotation that turns face `i` towards the camera with its apex pointing up. */
function faceQuaternion(geo, i) {
    const pos = geo.getAttribute('position');
    const a = new THREE.Vector3().fromBufferAttribute(pos, i * 3);
    const b = new THREE.Vector3().fromBufferAttribute(pos, i * 3 + 1);
    const c = new THREE.Vector3().fromBufferAttribute(pos, i * 3 + 2);
    const centre = a.clone().add(b).add(c).divideScalar(3);
    const z = centre.clone().normalize();
    const y = a.clone().sub(centre);
    y.sub(z.clone().multiplyScalar(y.dot(z))).normalize();
    const x = new THREE.Vector3().crossVectors(y, z);
    const basis = new THREE.Matrix4().makeBasis(x, y, z);
    const q = new THREE.Quaternion().setFromRotationMatrix(basis).invert();
    // Tilt a little so it reads as a solid, not a flat triangle
    const tilt = new THREE.Quaternion().setFromEuler(new THREE.Euler(-0.32, 0.18, 0));
    return tilt.multiply(q);
}

const ease = (t) => 1 - Math.pow(1 - t, 3);

export async function roll(oracle) {
    const faces = JSON.parse(oracle.dataset.faces || '[]');
    let targets = [];
    try { targets = JSON.parse(oracle.dataset.targets || '[]'); } catch { targets = []; }
    if (!targets.length) targets = [Number(oracle.dataset.target || 0)];
    const stage = oracle.querySelector('[data-dice-stage]');
    const canvas = oracle.querySelector('[data-dice-canvas]');
    const hint = oracle.querySelector('[data-dice-hint]');
    if (faces.length !== 20 || !stage || !canvas) throw new Error('No dice data');

    stage.hidden = false;
    // The face labels use the page's display font; wait briefly for it
    try {
        await Promise.race([document.fonts.load('700 51px Cinzel'), new Promise((r) => setTimeout(r, 800))]);
    } catch { /* fall back to Georgia */ }
    const size = () => {
        const w = stage.clientWidth || 320;
        const h = Math.min(360, Math.max(240, w * (targets.length > 1 ? 0.5 : 0.75)));
        return [w, h];
    };
    let [w, h] = size();

    const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true });
    renderer.setPixelRatio(Math.min(2, window.devicePixelRatio || 1));
    renderer.setSize(w, h, false);
    renderer.outputColorSpace = THREE.SRGBColorSpace;

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(32, w / h, 0.1, 50);
    // Pull the camera back on narrow screens so both dice stay in view
    const fitCamera = () => {
        const halfWidth = targets.length > 1 ? 4.1 : 2.4;
        const needed = halfWidth / (Math.tan((camera.fov / 2) * Math.PI / 180) * camera.aspect);
        camera.position.set(0, 0, Math.max(targets.length > 1 ? 7.4 : 6.2, needed));
    };
    fitCamera();

    scene.add(new THREE.AmbientLight(0xffe8c0, 0.9));
    const key = new THREE.DirectionalLight(0xfff1d6, 2.2);
    key.position.set(2.5, 3.5, 5);
    scene.add(key);
    const candle = new THREE.PointLight(0xffa64d, 18, 12);
    candle.position.set(-3, -1, 3);
    scene.add(candle);

    const texture = new THREE.CanvasTexture(faceAtlas(faces));
    texture.colorSpace = THREE.SRGBColorSpace;
    texture.anisotropy = 4;
    const geo = d20Geometry(1.55);
    const edgeGeo = new THREE.EdgesGeometry(geo);

    // One die per target; two dice sit side by side
    const dice = targets.map((target, i) => {
        const mesh = new THREE.Mesh(geo, new THREE.MeshStandardMaterial({
            map: texture, roughness: 0.42, metalness: 0.18, flatShading: true, emissive: 0x2a0b05, emissiveIntensity: 0.4,
        }));
        mesh.add(new THREE.LineSegments(edgeGeo, new THREE.LineBasicMaterial({ color: 0xd9b25a })));
        scene.add(mesh);
        return {
            mesh, target,
            homeX: targets.length > 1 ? (i === 0 ? -2.1 : 2.1) : 0,
            fromX: targets.length > 1 ? (i === 0 ? -4.2 : 4.2) : -1.5,
            finalQ: faceQuaternion(geo, target),
            axis1: new THREE.Vector3(Math.random() - 0.5, 1, Math.random() - 0.5).normalize(),
            axis2: new THREE.Vector3(1, Math.random() - 0.5, 0.3).normalize(),
            turns1: (3 + Math.random() * 2) * Math.PI * 2,
            turns2: (1 + Math.random()) * Math.PI * 2,
            delay: i * 180,
        };
    });
    const q1 = new THREE.Quaternion();
    const q2 = new THREE.Quaternion();

    const duration = 2600;
    let start = null;
    let landed = false;
    let raf = 0;
    window.dispatchEvent(new CustomEvent('rj:dice-roll', { detail: { count: dice.length } }));

    const reveal = () => {
        if (oracle.classList.contains('is-revealed')) return;
        oracle.classList.remove('is-rolling');
        oracle.classList.add('is-revealed');
        window.dispatchEvent(new CustomEvent('rj:card-reveal'));
        if (hint) hint.textContent = 'Rolled ' + targets.map((t) => faces[t]).join(' and ') + '.';
    };

    const land = () => {
        if (landed) return;
        landed = true;
        dice.forEach((d) => { d.mesh.quaternion.copy(d.finalQ); d.mesh.position.set(d.homeX, 0, 0); });
        oracle.classList.add('is-landed');
        // A brief glow on the winning faces, then the cards turn over
        let glowStart = null;
        const glow = (now) => {
            glowStart ??= now;
            const t = Math.min(1, (now - glowStart) / 700);
            dice.forEach((d) => d.mesh.material.emissive.setRGB(0.35 * Math.sin(t * Math.PI), 0.2 * Math.sin(t * Math.PI), 0.04));
            renderer.render(scene, camera);
            if (t < 1) requestAnimationFrame(glow);
        };
        requestAnimationFrame(glow);
        setTimeout(reveal, 450);
    };

    const frame = (now) => {
        start ??= now;
        let done = true;
        dice.forEach((d) => {
            const t = Math.max(0, Math.min(1, (now - start - d.delay) / duration));
            if (t < 1) done = false;
            const e = ease(t);
            q1.setFromAxisAngle(d.axis1, d.turns1 * (1 - e));
            q2.setFromAxisAngle(d.axis2, d.turns2 * (1 - e));
            d.mesh.quaternion.copy(q1).multiply(q2).multiply(d.finalQ);
            // Thrown in from the side, bouncing to a stop
            const bounce = Math.abs(Math.cos(t * Math.PI * 3.5)) * Math.pow(1 - t, 2) * 0.9;
            d.mesh.position.set(d.homeX + (1 - e) * (d.fromX - d.homeX), bounce - 0.1 * (1 - t), 0);
        });
        renderer.render(scene, camera);
        if (!done && !landed) {
            raf = requestAnimationFrame(frame);
        } else {
            land();
        }
    };
    raf = requestAnimationFrame(frame);

    oracle.querySelector('[data-dice-skip]')?.addEventListener('click', () => {
        cancelAnimationFrame(raf);
        land();
        reveal();
    });

    window.addEventListener('resize', () => {
        [w, h] = size();
        renderer.setSize(w, h, false);
        camera.aspect = w / h;
        camera.updateProjectionMatrix();
        fitCamera();
        renderer.render(scene, camera);
    });
}
