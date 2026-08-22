/**
 * Blockable mixin for Stimulus controllers.
 *
 * Provides `block(target)` and `unblock(id)` methods to overlay
 * a loader on any element (or document.body). Supports multiple
 * concurrent overlays.
 *
 * Usage:
 *   import { useBlockable } from '../../mixins/blockable';
 *
 *   export default class extends Controller {
 *       connect() {
 *           useBlockable(this);
 *       }
 *
 *       async load() {
 *           const id = this.block(this.element);
 *           try { await fetch(…); }
 *           finally { this.unblock(id); }
 *       }
 *   }
 */

let blockCounter = 0;

/**
 * Attaches `block` / `unblock` methods to the controller instance.
 *
 * @param {Controller} controller
 */
export function useBlockable(controller) {
    if (!controller._blockOverlays) {
        controller._blockOverlays = new Map();
    }

    Object.assign(controller, {
        /**
         * Block a target element with a loader overlay.
         *
         * @param {HTMLElement} [target=document.body] - Element to block.
         * @returns {string} Unique overlay id to pass to `unblock()`.
         */
        block(target) {
            const el = target || document.body;
            const id = `blockui-${++blockCounter}`;

            if (el !== document.body) {
                const pos = getComputedStyle(el).position;
                if (pos === 'static' || pos === '') {
                    el.style.position = 'relative';
                    el.dataset.blockuiPositionReset = '1';
                }
            }

            const overlay = document.createElement('div');
            overlay.id = id;
            overlay.className = el === document.body
                ? 'blockui blockui--fixed'
                : 'blockui blockui--absolute';
            overlay.innerHTML = `
                <div class="blockui__spinner">
                    <div class="blockui__dot"></div>
                    <div class="blockui__dot"></div>
                    <div class="blockui__dot"></div>
                </div>
            `;

            if (el === document.body) {
                document.body.appendChild(overlay);
            } else {
                el.appendChild(overlay);
            }

            // Defer `--visible` via rAF so the browser paints the
            // initial `opacity:0` state first and interpolates the
            // transition. Doubled with a `setTimeout(0)` because rAF
            // is deprioritised in headless / inactive-tab contexts
            // (Chrome MCP, automated tests) — without this fallback
            // the overlay stays invisible despite being in the DOM.
            // The double-trigger is idempotent (`classList.add`
            // ignores duplicates).
            requestAnimationFrame(() => overlay.classList.add('blockui--visible'));
            setTimeout(() => overlay.classList.add('blockui--visible'), 0);

            this._blockOverlays.set(id, { overlay, target: el });
            return id;
        },

        /**
         * Remove a specific overlay by id.
         *
         * @param {string} id - The id returned by `block()`.
         */
        unblock(id) {
            const entry = this._blockOverlays.get(id);
            if (!entry) return;

            entry.overlay.classList.remove('blockui--visible');
            setTimeout(() => {
                entry.overlay.remove();
                if (entry.target.dataset.blockuiPositionReset) {
                    entry.target.style.position = '';
                    delete entry.target.dataset.blockuiPositionReset;
                }
                this._blockOverlays.delete(id);
            }, 200);
        },

        /**
         * Remove all active overlays.
         */
        unblockAll() {
            for (const id of this._blockOverlays.keys()) {
                this.unblock(id);
            }
        },
    });
}
