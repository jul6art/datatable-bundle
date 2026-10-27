/**
 * The preview panel of a row (2.13): the record's page, summarised, in a panel on the right — the
 * list, its page and its filters stay where they are.
 *
 * What the panel shows is the fetched page's `[data-preview]` element (the record page of
 * jul6art/admin-bundle ≥ 1.35 marks its own), minus anything marked `[data-preview-exclude]` —
 * except what that region marks `[data-preview-keep]`; a page without one shows its `<main>`. Its scripts are dropped — the Stimulus controllers in it
 * connect by themselves, as they would on any markup added to the page.
 *
 * One panel at a time: opening another row's preview replaces it, and the request of the previous
 * one is aborted rather than left to land on top of the new one.
 *
 * Every label comes from the caller (`labels`), already translated: this file draws no text.
 */
let current = null;

const escape = (value) => String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;');

export function closePreviewSheet() {
    if (!current) return;
    const { root, trigger, controller, onKeydown } = current;
    current = null;
    controller.abort();
    document.removeEventListener('keydown', onKeydown);
    root.remove();
    // Focus goes back where the reader was — the eye of the row — not to the top of the page.
    if (trigger?.isConnected) trigger.focus();
}

/**
 * @param {{ url: string, trigger?: HTMLElement, labels: { title: string, close: string, open: string, error: string, loading: string } }} options
 */
export function openPreviewSheet({ url, trigger = null, labels }) {
    closePreviewSheet();

    const root = document.createElement('div');
    root.className = 'dt-sheet-root';
    root.innerHTML = `
        <div class="dt-sheet-scrim" data-dt-sheet-close></div>
        <section class="dt-sheet" role="dialog" aria-modal="true" aria-labelledby="dt-sheet-title" tabindex="-1">
            <header class="dt-sheet-header">
                <h2 class="dt-sheet-title" id="dt-sheet-title">${escape(labels.title)}</h2>
                <button type="button" class="dt-sheet-close" data-dt-sheet-close aria-label="${escape(labels.close)}">
                    <i class="fa-solid fa-xmark" aria-hidden="true"></i>
                </button>
            </header>
            <div class="dt-sheet-body" aria-busy="true">
                <div class="dt-sheet-loading" role="status" aria-label="${escape(labels.loading)}">
                    <span class="blockui__dot"></span><span class="blockui__dot"></span><span class="blockui__dot"></span>
                </div>
            </div>
            <footer class="dt-sheet-footer">
                <a class="btn-primary dt-sheet-open" href="${escape(url)}">${escape(labels.open)}<i class="fa-solid fa-arrow-right" aria-hidden="true"></i></a>
            </footer>
        </section>`;

    const controller = new AbortController();
    const onKeydown = (event) => {
        if ('Escape' === event.key) closePreviewSheet();
    };
    current = { root, trigger, controller, onKeydown };

    root.addEventListener('click', (event) => {
        if (event.target.closest('[data-dt-sheet-close]')) closePreviewSheet();
    });
    document.addEventListener('keydown', onKeydown);
    document.body.appendChild(root);
    root.querySelector('.dt-sheet-close').focus();

    const body = root.querySelector('.dt-sheet-body');
    fetch(url, {
        credentials: 'same-origin',
        headers: { Accept: 'text/html', 'X-Datatable-Preview': '1' },
        signal: controller.signal,
    })
        .then((response) => (response.ok ? response.text() : Promise.reject(new Error(String(response.status)))))
        .then((html) => {
            const page = new DOMParser().parseFromString(html, 'text/html');
            const part = page.querySelector('[data-preview]') || page.querySelector('main');
            if (!part) throw new Error('nothing to preview');
            part.querySelectorAll('script').forEach((element) => element.remove());
            // An excluded region goes, but what it marks `data-preview-keep` stays in its place.
            part.querySelectorAll('[data-preview-exclude]').forEach((element) => {
                element.replaceWith(...element.querySelectorAll('[data-preview-keep]'));
            });
            part.setAttribute('data-preview-active', '');
            // A `loading="lazy"` image imported from a parsed document is never woken up (Chrome):
            // the QR code of a cegeta item stayed an empty frame. The panel is on screen anyway.
            part.querySelectorAll('img[loading="lazy"]').forEach((image) => image.setAttribute('loading', 'eager'));
            body.replaceChildren(document.importNode(part, true));
            body.removeAttribute('aria-busy');
        })
        .catch((error) => {
            if ('AbortError' === error?.name) return;
            body.removeAttribute('aria-busy');
            body.innerHTML = `<p class="dt-sheet-error" role="alert">${escape(labels.error)}</p>`;
        });
}
