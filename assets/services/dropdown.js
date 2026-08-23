/**
 * The behaviour behind the per-row actions dropdown.
 *
 * ⚠️ The markup rendered by `_renderActionsDropdown()` calls `window._toggleDtDropdown(this)`
 * inline. Shipping that markup without the function made the menu button render and do NOTHING
 * when clicked — no error, no hint, just a dead ⋮ button. The two belong together, so the
 * controller installs this on connect.
 *
 * The menu is positioned `fixed` on purpose: a table cell is inside `overflow: hidden`, and an
 * absolutely positioned menu would be clipped by it. Fixed means the position has to be
 * recomputed while the menu is open (scroll, resize), hence the rAF tracking loop.
 */
export function installActionsDropdown() {
    if (window._toggleDtDropdown) return;

    window._dtDropdownRaf = null;

    window._positionDtDropdown = (btn, menu) => {
        const rect = btn.getBoundingClientRect();
        const menuHeight = menu.offsetHeight || 200;
        const menuWidth = 192;
        const spaceBelow = window.innerHeight - rect.bottom;
        const openUp = spaceBelow < menuHeight && rect.top > menuHeight;

        if (openUp) {
            menu.style.top = '';
            menu.style.bottom = (window.innerHeight - rect.top + 4) + 'px';
        } else {
            menu.style.top = (rect.bottom + 4) + 'px';
            menu.style.bottom = '';
        }

        let left = rect.right - menuWidth;
        if (left < 8) left = 8;
        menu.style.left = left + 'px';
    };

    window._startDtDropdownTracking = (btn, menu) => {
        const tick = () => {
            if (menu.classList.contains('hidden')) {
                window._dtDropdownRaf = null;
                return;
            }
            window._positionDtDropdown(btn, menu);
            window._dtDropdownRaf = requestAnimationFrame(tick);
        };
        window._dtDropdownRaf = requestAnimationFrame(tick);
    };

    window._toggleDtDropdown = (btn) => {
        const menu = btn.parentElement.querySelector('.dt-dropdown-menu');
        if (!menu) return;

        const wasHidden = menu.classList.contains('hidden');

        document.querySelectorAll('.dt-dropdown-menu').forEach(m => m.classList.add('hidden'));

        if (window._dtDropdownRaf) {
            cancelAnimationFrame(window._dtDropdownRaf);
            window._dtDropdownRaf = null;
        }

        if (!wasHidden) return;

        window._positionDtDropdown(btn, menu);
        menu.classList.remove('hidden');
        window._startDtDropdownTracking(btn, menu);
    };

    document.addEventListener('click', (event) => {
        if (!event.target.closest('.dt-dropdown')) {
            document.querySelectorAll('.dt-dropdown-menu').forEach(m => m.classList.add('hidden'));
        }
    });
}
