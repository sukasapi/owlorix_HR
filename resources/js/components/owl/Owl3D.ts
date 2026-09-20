/**
 * PLACEHOLDER owl head built from primitive shapes. It copies the approved mockup render
 * (docs/design/mockups-src/src/js/owl3d.js) until the studio supplies its own GLB model,
 * which replaces buildOwl() (docs/DESIGN.md, "Three.js").
 *
 * Plain three.js with no React import: OwlHero loads this module lazily, and a lazy chunk
 * without React cannot end up holding a second React copy.
 */
import * as THREE from 'three';
import type { EyeState } from './OwlEyes';

export interface OwlController {
    setState: (state: EyeState) => void;
    destroy: () => void;
}

interface MountOptions {
    state: EyeState;
    /** The GPU dropped the context (driver reset, too many contexts); OwlHero shows the SVG eyes. */
    onLost: () => void;
}

// Pointer distance in CSS px that turns the head fully; further away the owl stops turning.
const REACH_X = 480;
const REACH_Y = 360;
const REST = new THREE.Vector2(0.3, 0.2);
const TURN_Y = 0.3;
const TURN_X = 0.12;
const TILT = 0.06;
// Pupil travel stays inside the iris (iris radius 0.39, pupil radius 0.18).
const PUPIL_X = 0.13;
const PUPIL_Y = 0.09;

const LID_STEPS = 12;
const LID_FOR: Record<EyeState, number> = { open: 0, attention: 0, half: 0.5, closed: 1 };
const BLINK_MS = 320;
const BLINK_EVERY_MS = 4000;
const BLINK_JITTER_MS = 3000;
const PULSE_MS = 700;
const MAX_PIXEL_RATIO = 2;

interface Eye {
    ball: THREE.Group;
    pupil: THREE.Mesh;
    shine: THREE.Mesh;
    lid: THREE.Mesh;
    arc: THREE.Mesh;
    ring: THREE.Mesh;
}

interface Owl {
    root: THREE.Group;
    head: THREE.Group;
    eyes: Eye[];
    lidGeometries: THREE.BufferGeometry[];
    paint: () => void;
    dispose: () => void;
}

/** Wing that wraps the right eye from a tuft above, around the outside, to below the eye (like the logo). */
function browShape(cx: number, cy: number) {
    const shape = new THREE.Shape();
    const at = (r: number, deg: number): [number, number] => [cx + r * Math.cos((deg * Math.PI) / 180), cy + r * Math.sin((deg * Math.PI) / 180)];
    shape.moveTo(...at(1.42, 70));
    for (let a = 64; a >= -122; a -= 6) shape.lineTo(...at(0.98, a));
    for (let a = -122; a <= 58; a += 6) shape.lineTo(...at(0.76, a));
    shape.closePath();
    return shape;
}

/** Colors come from the CSS tokens, so a token change or a theme switch reaches the 3D owl too. */
function readTokens() {
    const css = getComputedStyle(document.documentElement);
    const token = (name: string) => css.getPropertyValue(name).trim();
    return {
        feather: token('--feather'),
        teal: token('--teal'),
        mist: token('--mist'),
        gold: token('--gold'),
        pupil: token('--eye-outline'),
        socket: token('--eye-socket'),
        dark: document.documentElement.dataset.theme === 'dark',
    };
}

function buildOwl(): Owl {
    const owned: { dispose: () => void }[] = [];
    const own = <T extends { dispose: () => void }>(item: T) => {
        owned.push(item);
        return item;
    };

    const root = new THREE.Group();
    const head = new THREE.Group();
    root.add(head);

    // Lighting tints match the mockup render and do not change with the theme.
    root.add(new THREE.HemisphereLight(0xffffff, 0x2b6874, 1.25));
    const key = new THREE.DirectionalLight(0xfff1d6, 2.4);
    key.position.set(3.5, 4, 6);
    root.add(key);
    const rim = new THREE.DirectionalLight(0x9fd6de, 1.1);
    rim.position.set(-5, 1.5, -2);
    root.add(rim);

    const headGeometry = own(new THREE.SphereGeometry(1.55, 64, 48));
    const headColors = new THREE.BufferAttribute(new Float32Array(headGeometry.attributes.position.count * 3), 3);
    headGeometry.setAttribute('color', headColors);
    const headMaterial = own(new THREE.MeshStandardMaterial({ vertexColors: true, roughness: 0.72, metalness: 0.02 }));
    const skull = new THREE.Mesh(headGeometry, headMaterial);
    skull.scale.set(1.1, 1, 0.9);
    head.add(skull);

    const faceMaterial = own(new THREE.MeshStandardMaterial({ roughness: 0.85 }));
    const irisMaterial = own(new THREE.MeshStandardMaterial({ roughness: 0.32, metalness: 0.08 }));
    const pupilMaterial = own(new THREE.MeshStandardMaterial({ roughness: 0.25 }));
    const featherMaterial = own(new THREE.MeshStandardMaterial({ roughness: 0.58, side: THREE.DoubleSide }));
    const beakMaterial = own(new THREE.MeshStandardMaterial({ roughness: 0.45 }));
    const ringMaterial = own(new THREE.MeshStandardMaterial({ roughness: 0.4, metalness: 0.1 }));
    const shineMaterial = own(new THREE.MeshBasicMaterial({ color: 0xffffff }));
    const shadowMaterial = own(new THREE.MeshBasicMaterial({ transparent: true, depthWrite: false }));

    const discGeometry = own(new THREE.SphereGeometry(0.64, 48, 32));
    const irisGeometry = own(new THREE.SphereGeometry(0.39, 48, 32));
    const pupilGeometry = own(new THREE.SphereGeometry(0.18, 32, 24));
    const shineGeometry = own(new THREE.SphereGeometry(0.055, 16, 12));
    const arcGeometry = own(new THREE.TorusGeometry(0.3, 0.045, 12, 40, Math.PI));
    // The ring leaves a gap on the nose side, where the two face discs overlap.
    const ringGeometry = own(new THREE.TorusGeometry(0.7, 0.045, 12, 64, Math.PI * 1.6));
    // Upper eyelid as a dome cut at growing depths; swapping geometry avoids rebuilding meshes per frame.
    const lidGeometries = Array.from({ length: LID_STEPS + 1 }, (_, step) =>
        own(new THREE.SphereGeometry(0.6, 40, 20, 0, Math.PI * 2, 0, (Math.PI * step) / LID_STEPS)),
    );

    const eyes = [-1, 1].map((side): Eye => {
        const socket = new THREE.Group();
        socket.position.set(side * 0.56, 0.08, 1.2);
        head.add(socket);

        const disc = new THREE.Mesh(discGeometry, faceMaterial);
        disc.scale.set(1, 1, 0.34);
        disc.rotation.y = side * 0.22;
        socket.add(disc);

        const ball = new THREE.Group();
        socket.add(ball);
        const iris = new THREE.Mesh(irisGeometry, irisMaterial);
        iris.scale.set(1, 1, 0.38);
        iris.position.set(side * 0.02, -0.02, 0.19);
        ball.add(iris);
        const pupil = new THREE.Mesh(pupilGeometry, pupilMaterial);
        pupil.scale.set(1, 1, 0.45);
        pupil.position.set(0, -0.01, 0.32);
        ball.add(pupil);
        const shine = new THREE.Mesh(shineGeometry, shineMaterial);
        shine.position.set(-0.1, 0.12, 0.4);
        ball.add(shine);

        const lid = new THREE.Mesh(lidGeometries[0], featherMaterial);
        lid.scale.set(1, 1, 0.85);
        lid.rotation.y = side * 0.22;
        lid.visible = false;
        socket.add(lid);

        const arc = new THREE.Mesh(arcGeometry, pupilMaterial);
        arc.rotation.z = Math.PI;
        arc.position.set(0, 0.04, 0.24);
        socket.add(arc);

        const ring = new THREE.Mesh(ringGeometry, ringMaterial);
        // Center the gap on the inner side: pointing +x for the left eye, -x for the right eye.
        ring.rotation.z = (side < 0 ? 0 : Math.PI) + Math.PI * 0.2;
        ring.position.z = 0.16;
        ring.visible = false;
        socket.add(ring);

        return { ball, pupil, shine, lid, arc, ring };
    });

    const browGeometry = own(
        new THREE.ExtrudeGeometry(browShape(0.56, 0.08), { depth: 0.16, bevelEnabled: true, bevelThickness: 0.05, bevelSize: 0.04, bevelSegments: 4, curveSegments: 24 }),
    );
    for (const side of [-1, 1]) {
        const brow = new THREE.Mesh(browGeometry, featherMaterial);
        brow.scale.set(side, 1, 1);
        brow.position.set(0, 0, 1.2);
        head.add(brow);
    }

    const beak = new THREE.Mesh(own(new THREE.ConeGeometry(0.15, 0.44, 32)), beakMaterial);
    beak.rotation.x = Math.PI * 0.94;
    beak.position.set(0, -0.44, 1.46);
    head.add(beak);

    // Contact shadow stays on the ground while the head turns.
    const shadow = new THREE.Mesh(own(new THREE.CircleGeometry(1.35, 48)), shadowMaterial);
    shadow.rotation.x = -Math.PI / 2;
    shadow.scale.set(1.15, 0.55, 1);
    shadow.position.set(0, -1.78, 0);
    root.add(shadow);

    const paint = () => {
        const tokens = readTokens();
        const feather = new THREE.Color(tokens.feather);
        const teal = new THREE.Color(tokens.teal);
        const mist = new THREE.Color(tokens.mist);

        // The logo's feather gradient: deep blue top, teal middle, mist bottom.
        const positions = headGeometry.attributes.position;
        const color = new THREE.Color();
        for (let i = 0; i < positions.count; i++) {
            const t = (positions.getY(i) + 1.55) / 3.1;
            if (t > 0.5) color.copy(teal).lerp(feather, Math.min(1, (t - 0.5) / 0.38));
            else color.copy(mist).lerp(teal, Math.max(0, t / 0.5));
            headColors.setXYZ(i, color.r, color.g, color.b);
        }
        headColors.needsUpdate = true;

        faceMaterial.color.set(tokens.socket).lerp(new THREE.Color(0xffffff), 0.55);
        irisMaterial.color.set(tokens.gold);
        beakMaterial.color.set(tokens.gold);
        ringMaterial.color.set(tokens.gold);
        pupilMaterial.color.set(tokens.pupil);
        featherMaterial.color.copy(feather);
        shadowMaterial.color.set(tokens.pupil);
        shadowMaterial.opacity = tokens.dark ? 0.32 : 0.14;
    };

    return { root, head, eyes, lidGeometries, paint, dispose: () => owned.forEach((item) => item.dispose()) };
}

/**
 * Draws the owl into the canvas and returns its controls. Frames are drawn only when something
 * changes (pointer move, blink, state change, resize, theme), never in a constant loop.
 * Throws when WebGL cannot start; the caller falls back to the SVG eyes.
 */
export function mountOwl(canvas: HTMLCanvasElement, { state: initialState, onLost }: MountOptions): OwlController {
    const renderer = new THREE.WebGLRenderer({ canvas, antialias: true, alpha: true, powerPreference: 'low-power' });
    renderer.setPixelRatio(Math.min(window.devicePixelRatio || 1, MAX_PIXEL_RATIO));

    const scene = new THREE.Scene();
    const camera = new THREE.PerspectiveCamera(28, 1, 0.1, 100);
    camera.position.set(0, 0.5, 8);
    camera.lookAt(0, 0, 0);

    const owl = buildOwl();
    scene.add(owl.root);

    let state = initialState;
    const look = REST.clone();
    const target = REST.clone();
    let lid = LID_FOR[state];
    let blinkStart = -1;
    let pulseStart = state === 'attention' ? performance.now() : -1;
    let frame = 0;
    let lastFrame = 0;
    let blinkTimer = 0;

    const invalidate = () => {
        if (frame || document.hidden) return;
        frame = requestAnimationFrame(draw);
    };

    function draw(now: number) {
        frame = 0;
        // After an idle stretch the gap between frames can be seconds long; cap it so easing does not jump.
        const dt = lastFrame ? Math.min((now - lastFrame) / 1000, 1 / 30) : 1 / 60;
        lastFrame = now;
        if (update(dt, performance.now())) invalidate();
        else lastFrame = 0;
        renderer.render(scene, camera);
    }

    function update(dt: number, now: number): boolean {
        let moving = false;

        look.lerp(target, 1 - Math.exp(-dt * 9));
        if (look.distanceToSquared(target) > 1e-6) moving = true;
        else look.copy(target);
        owl.head.rotation.set(TILT + look.y * TURN_X, look.x * TURN_Y, 0);

        const lidTarget = LID_FOR[state];
        lid += (lidTarget - lid) * (1 - Math.exp(-dt * 14));
        if (Math.abs(lidTarget - lid) > 0.004) moving = true;
        else lid = lidTarget;

        let blink = 0;
        if (blinkStart >= 0) {
            const t = now - blinkStart;
            if (t >= BLINK_MS) {
                blinkStart = -1;
            } else {
                blink = Math.sin((t / BLINK_MS) * Math.PI);
                moving = true;
            }
        }

        let ringScale = 1;
        if (state === 'attention' && pulseStart >= 0) {
            const t = now - pulseStart;
            if (t >= PULSE_MS * 2) {
                pulseStart = -1;
            } else {
                ringScale = 1 + 0.1 * Math.abs(Math.sin((t / PULSE_MS) * Math.PI));
                moving = true;
            }
        }

        const shut = state === 'closed' && lid > 0.97;
        const step = Math.round(Math.max(lid, blink) * LID_STEPS);
        for (const eye of owl.eyes) {
            eye.arc.visible = shut;
            eye.ball.visible = !shut;
            eye.lid.visible = !shut && step > 0;
            eye.lid.geometry = owl.lidGeometries[step];
            eye.pupil.position.x = look.x * PUPIL_X;
            eye.pupil.position.y = -0.01 - look.y * PUPIL_Y;
            eye.shine.position.x = -0.1 + look.x * PUPIL_X;
            eye.shine.position.y = 0.12 - look.y * PUPIL_Y;
            eye.ring.visible = state === 'attention';
            eye.ring.scale.setScalar(ringScale);
        }
        return moving;
    }

    const resize = () => {
        const width = canvas.clientWidth;
        const height = canvas.clientHeight;
        if (!width || !height) return;
        renderer.setSize(width, height, false);
        camera.aspect = width / height;
        camera.updateProjectionMatrix();
        renderer.render(scene, camera);
        invalidate();
    };
    const resizeObserver = new ResizeObserver(resize);
    resizeObserver.observe(canvas);

    const themeObserver = new MutationObserver(() => {
        owl.paint();
        invalidate();
    });
    themeObserver.observe(document.documentElement, { attributes: true, attributeFilter: ['data-theme'] });

    const onPointerMove = (event: PointerEvent) => {
        const rect = canvas.getBoundingClientRect();
        target.set(
            THREE.MathUtils.clamp((event.clientX - rect.left - rect.width / 2) / REACH_X, -1, 1),
            THREE.MathUtils.clamp((event.clientY - rect.top - rect.height / 2) / REACH_Y, -1, 1),
        );
        invalidate();
    };
    const onPointerOut = (event: PointerEvent) => {
        if (event.relatedTarget) return;
        target.copy(REST);
        invalidate();
    };

    const scheduleBlink = () => {
        window.clearTimeout(blinkTimer);
        blinkTimer = window.setTimeout(() => {
            if (state !== 'closed') {
                blinkStart = performance.now();
                invalidate();
            }
            scheduleBlink();
        }, BLINK_EVERY_MS + Math.random() * BLINK_JITTER_MS);
    };

    // Hidden tab: no blink timer and no frames. OwlHero releases the context after a longer absence.
    const onVisibility = () => {
        if (document.hidden) {
            window.clearTimeout(blinkTimer);
            cancelAnimationFrame(frame);
            frame = 0;
            lastFrame = 0;
        } else {
            scheduleBlink();
            invalidate();
        }
    };

    const onContextLost = () => onLost();

    window.addEventListener('pointermove', onPointerMove, { passive: true });
    document.addEventListener('pointerout', onPointerOut);
    document.addEventListener('visibilitychange', onVisibility);
    canvas.addEventListener('webglcontextlost', onContextLost);

    owl.paint();
    update(0, performance.now());
    resize();
    if (!document.hidden) scheduleBlink();

    return {
        setState(next) {
            if (next === state) return;
            state = next;
            if (next === 'attention') pulseStart = performance.now();
            invalidate();
        },
        destroy() {
            // Listeners go first, so releasing the context below is not reported as a failure.
            canvas.removeEventListener('webglcontextlost', onContextLost);
            window.removeEventListener('pointermove', onPointerMove);
            document.removeEventListener('pointerout', onPointerOut);
            document.removeEventListener('visibilitychange', onVisibility);
            resizeObserver.disconnect();
            themeObserver.disconnect();
            window.clearTimeout(blinkTimer);
            cancelAnimationFrame(frame);
            owl.dispose();
            renderer.dispose();
            if (!renderer.getContext().isContextLost()) renderer.forceContextLoss();
        },
    };
}
