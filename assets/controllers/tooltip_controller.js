import { Controller } from '@hotwired/stimulus';

/**
 * Tooltip Controller — Styled tooltips for any element.
 *
 * Usage:
 *   <button data-controller="ui--tooltip"
 *           data-ui--tooltip-text-value="Show details"
 *           data-ui--tooltip-position-value="top">
 *       <svg>…</svg>
 *   </button>
 *
 * Positions: top (default), bottom, left, right
 */
export default class extends Controller {
    static values = {
        text: String,
        position: { type: String, default: 'top' },
    };

    connect() {
        this._onMouseEnter = () => this._show();
        this._onMouseLeave = () => this._hide();
        this._onFocusIn = () => this._show();
        this._onFocusOut = () => this._hide();

        this.element.addEventListener('mouseenter', this._onMouseEnter);
        this.element.addEventListener('mouseleave', this._onMouseLeave);
        this.element.addEventListener('focusin', this._onFocusIn);
        this.element.addEventListener('focusout', this._onFocusOut);
    }

    disconnect() {
        this._hide();
        this.element.removeEventListener('mouseenter', this._onMouseEnter);
        this.element.removeEventListener('mouseleave', this._onMouseLeave);
        this.element.removeEventListener('focusin', this._onFocusIn);
        this.element.removeEventListener('focusout', this._onFocusOut);
    }

    _show() {
        if (this._tooltip || !this.textValue) return;

        const tooltip = document.createElement('div');
        tooltip.className = 'tooltip';
        tooltip.setAttribute('role', 'tooltip');
        tooltip.textContent = this.textValue;

        document.body.appendChild(tooltip);
        this._tooltip = tooltip;

        this._position(tooltip);

        requestAnimationFrame(() => {
            tooltip.classList.add('tooltip--visible');
        });
    }

    _hide() {
        if (!this._tooltip) return;

        this._tooltip.classList.remove('tooltip--visible');

        const el = this._tooltip;
        this._tooltip = null;
        setTimeout(() => el.remove(), 150);
    }

    _position(tooltip) {
        const rect = this.element.getBoundingClientRect();
        const pos = this.positionValue;

        tooltip.dataset.position = pos;

        const scrollX = window.scrollX;
        const scrollY = window.scrollY;

        let top, left;

        switch (pos) {
            case 'bottom':
                top = rect.bottom + scrollY + 6;
                left = rect.left + scrollX + rect.width / 2;
                break;
            case 'left':
                top = rect.top + scrollY + rect.height / 2;
                left = rect.left + scrollX - 6;
                break;
            case 'right':
                top = rect.top + scrollY + rect.height / 2;
                left = rect.right + scrollX + 6;
                break;
            default: // top
                top = rect.top + scrollY - 6;
                left = rect.left + scrollX + rect.width / 2;
                break;
        }

        tooltip.style.top = `${top}px`;
        tooltip.style.left = `${left}px`;
    }
}
