/**
 * Translatable mixin for Stimulus controllers.
 *
 * Provides a `t(key)` method that resolves dot-separated keys
 * against a `translations` Object value passed from Twig.
 *
 * Usage:
 *   import { useTranslatable } from '../../mixins/translatable';
 *
 *   export default class extends Controller {
 *       static values = {
 *           ...translatableValues,
 *           // other values…
 *       };
 *
 *       connect() {
 *           useTranslatable(this);
 *       }
 *   }
 *
 * In Twig:
 *   data-my-controller-translations-value="{{ { 'key': 'val'|trans }|json_encode|e('html_attr') }}"
 */

/**
 * Value descriptor to spread into `static values`.
 */
export const translatableValues = {
    translations: { type: Object, default: {} },
};

/**
 * Attaches a `t(key)` method to the controller instance.
 *
 * @param {Controller} controller - The Stimulus controller instance.
 */
export function useTranslatable(controller) {
    Object.assign(controller, {
        t(key) {
            const keys = key.split('.');
            let value = this.translationsValue;
            for (const k of keys) {
                value = value?.[k];
                if (value === undefined) return key;
            }
            return value || key;
        },
    });
}
