import { Controller } from '@hotwired/stimulus';

const ICON_SVG = {
    danger:  '<i class="fa-solid fa-triangle-exclamation text-xl text-red-600"></i>',
    warning: '<i class="fa-solid fa-triangle-exclamation text-xl text-amber-600"></i>',
    primary: '<i class="fa-solid fa-circle-question text-xl text-accent-600"></i>',
};

const ICON_BG = {
    danger:  'bg-red-100 dark:bg-red-900/40',
    warning: 'bg-amber-100 dark:bg-amber-900/40',
    primary: 'bg-accent-100 dark:bg-accent-900/40',
};

const CONFIRM_BTN = {
    danger:  'btn-danger',
    warning: 'btn-warning',
    primary: 'btn-primary',
};

export default class extends Controller {
    static values = {
        title:          { type: String,  default: '' },
        message:        { type: String,  default: '' },
        confirmLabel:   { type: String,  default: '' },
        cancelLabel:    { type: String,  default: '' },
        confirmVariant: { type: String,  default: 'danger' },
    };

    intercept(event) {
        if (this._confirmed) {
            this._confirmed = false;
            return;
        }
        event.preventDefault();
        this._showModal();
    }

    // --- private ---

    _showModal() {
        const variant  = this.confirmVariantValue;
        const iconBg   = ICON_BG[variant]     ?? ICON_BG.danger;
        const iconSvg  = ICON_SVG[variant]    ?? ICON_SVG.danger;
        const btnClass = CONFIRM_BTN[variant] ?? CONFIRM_BTN.danger;

        const overlay = document.createElement('div');
        overlay.className = 'fixed inset-0 z-[100] flex items-center justify-center p-4';
        overlay.innerHTML = `
            <div class="absolute inset-0 bg-black/50 backdrop-blur-sm" data-backdrop></div>
            <div class="relative bg-white dark:bg-slate-800 rounded-2xl shadow-2xl w-full max-w-md p-6 border border-slate-200 dark:border-slate-700">
                <div class="flex items-start gap-4">
                    <div class="${iconBg} rounded-full p-2.5 flex-shrink-0">
                        ${iconSvg}
                    </div>
                    <div class="flex-1 min-w-0">
                        ${this.titleValue   ? `<h3 class="text-base font-semibold text-slate-900 dark:text-slate-100 mb-1">${this.titleValue}</h3>`  : ''}
                        ${this.messageValue ? `<p class="text-sm text-slate-600 dark:text-slate-400">${this.messageValue}</p>` : ''}
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button type="button" data-cancel class="${this.cancelLabelValue ? 'btn-secondary' : 'hidden'}">
                        ${this.cancelLabelValue}
                    </button>
                    <button type="button" data-confirm class="${btnClass}">
                        ${this.confirmLabelValue}
                    </button>
                </div>
            </div>
        `;

        overlay.querySelector('[data-backdrop]').addEventListener('click', () => overlay.remove());
        overlay.querySelector('[data-cancel]').addEventListener('click',   () => overlay.remove());
        overlay.querySelector('[data-confirm]').addEventListener('click',  () => {
            overlay.remove();
            this._confirmed = true;
            this.element.requestSubmit();
        });

        document.body.appendChild(overlay);
    }
}

