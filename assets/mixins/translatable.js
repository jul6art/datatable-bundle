/**
 * Re-export of the socle's mixin.
 *
 * The implementation moved to `jul6art/core-bundle` when the catalogue became the single source
 * of labels: a Stimulus controller shipped inside `vendor/` cannot import the project's
 * `assets/translator.js`, so it reads through the socle's registry instead.
 *
 * The file stays here because the controllers of this bundle — and seventeen of `superp` — import
 * it by this path, and because `bootstrap.js` derives Stimulus identifiers from the tree.
 * Rewriting those imports would buy nothing.
 *
 * ⚠️ Requires the `@jul6art/core-bundle` alias in the project's `webpack.config.js`, which
 * `bundle-assets.js` builds from `FRONT_BUNDLES`. Without it the build fails at resolution —
 * loudly, which is the right failure for a missing alias.
 */
export { useTranslatable, translatableValues } from '@jul6art/core-bundle/mixins/translatable';
