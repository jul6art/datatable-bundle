<p align="center">
    <a href="https://devinthehood.com"><img src="https://github.com/jul6art/symfony-skeleton-generator/blob/master/public/img/logo.png?raw=true" alt="logo dev in the hood" width="400"></a>
</p>

<p align="center">
    <a href="https://opensource.org/licenses/MIT" target="_blank"><img src="https://img.shields.io/badge/License-MIT-yellow.svg" alt="License"></a>
    <img src="https://img.shields.io/static/v1?label=stable&message=v1&color=orange" alt="Version">
</p>

Symfony datatable bundle
========================

A server-driven table over an API Platform collection: pagination, sorting, global search, column
filters, per-row and bulk actions, confirmation modals, and live refresh over Mercure — declared in
PHP, drawn by one Stimulus controller.

Extracted from an application that runs sixty of them.

Requirements
------------

- PHP ^8.5
- Symfony ^7.4 || ^8.0

Suggested, and what each unlocks:

| Package | Without it |
| --- | --- |
| `twig/twig` | the three Twig extensions and the two partials are not registered; only the PHP configuration providers are |
| `symfony/security-csrf` | `_csrf.html.twig` and `_preferences.html.twig` call `csrf_token()`, which does not exist — the partial fails at render. The preferences endpoint then skips its token check, `SameSite=Lax` remaining the baseline |
| `api-platform/core` | nothing to read: the filter conventions the helpers produce (`?param[after]=`) are API Platform's |
| `jul6art/api-bundle` | no `OrSearchFilter` for the global search, no `CaseInsensitiveOrderFilter` for the sort |

Front-end, in the application's own `package.json` or importmap: `datatables.net-dt` and
`datatables.net-responsive-dt`, plus `jquery` and `select2` for the autocomplete filters.

Installation
------------

```shell
composer require jul6art/datatable-bundle
```

```php
// config/bundles.php — Flex does this for you
Jul6Art\DatatableBundle\DatatableBundle::class => ['all' => true],
```

Configuration
-------------

```yaml
# config/packages/datatable.yaml
datatable:
    # Leaves the bundle installed and inert when false.
    enabled: true

    # Where the `datatable.*` keys the SERVER renders live — column headers, filter labels, the
    # tenant column. Defaults to `messages`; a project that splits its catalogues by functional
    # domain sets its own.
    #
    # ⚠️ This is NOT the domain the browser reads. Since v2 the JavaScript resolves its keys
    # against the catalogue dumped by `symfony/ux-translator`, whose single domain is configured
    # by `core.js_translations.domain` — `javascript` by default. See “JavaScript translations”.
    translation_domain: messages

    # The Stimulus identifier the table controller answers to. It decides the data-attribute
    # prefix the shipped partials emit, so it has to match how the application registered the
    # controller — a build that derives identifiers from a path gives `core--datatable` to a
    # file living in `assets/controllers/core/`.
    stimulus_identifier: datatable

    csrf:
        single: datatable_action           # per-row POST actions
        bulk: bulk_action                  # the /bulk-* endpoints
        preferences: datatable_preferences # the per-user preferences endpoint (X-CSRF-Token header)

    # The enum catalogues the badge renderers read — DECLARED, so the project's translation guard
    # knows their keys are alive. Business vocabulary, hence configuration.
    status_maps:
        quote_status:
            keys: [draft, sent, accepted, rejected]   # → datatable.quote_status.<case>
        expense_status:
            key_prefix: 'hr.expense.status.'          # → hr.expense.status.<case>
            keys: [draft, submitted, approved]

    # Only for a multi-tenant back office. Leave the endpoint empty otherwise.
    tenant:
        endpoint: /api/organizations
        label_key: datatable.col.organization
```

`datatable.enabled`, `datatable.stimulus_identifier`, `datatable.translation_domain`,
`datatable.csrf.single`, `datatable.csrf.bulk` and `datatable.csrf.preferences` are exposed as
container parameters.

`tenant.label_domain` defaults to `translation_domain` rather than to `messages`, so moving the
catalogue does not mean repeating the domain.

Usage
-----

### 1. Declare the table in PHP

One subclass per listing. Use the helpers rather than literal arrays: every label goes through the
translator, and a hand-written array is how a table ends up with one translated header next to a
raw key — which no test catches, because a table configuration has no expected output.

```php
final class UserDataTableConfigProvider extends AbstractDataTableConfigProvider
{
    public function getColumns(): array
    {
        return [
            $this->column('id', 'datatable.col.id', responsivePriority: 10),
            $this->column('fullName', 'user.field.name', 'user', render: 'userNameWithAvatar', responsivePriority: 1),
            $this->column('email', 'user.field.email', 'user', responsivePriority: 2),
            $this->readOnlyColumn('isActive', 'user.field.status', 'user', render: 'statusBadge'),
        ];
    }

    public function getFilters(): array
    {
        return [
            $this->staticFilter('isActive', 'isActive', 'user.field.status', [
                ['value' => 'true',  'label' => $this->t('datatable.status.active')],
                ['value' => 'false', 'label' => $this->t('datatable.status.inactive')],
            ], 'user'),
            $this->dateRangeFilter('createdAt', 'createdAt', 'user.filter.created', 'user', granularity: 'datetime'),
            $this->apiFilter('team', 'team', 'user.filter.team', '/api/teams'),
        ];
    }

    public function getActions(): array
    {
        return [
            $this->linkAction('show', '/admin/users/{id}', 'eye', 'action.show'),
            $this->bulkDeleteAction('/admin/users/{id}/delete', '/admin/users/bulk-delete'),
        ];
    }
}
```

> ⚠️ **`sortField` is not optional on a computed column.** Without it the front sends
> `?order[fullName]=` and the API answers unsorted — in silence. A column that cannot be sorted
> says so with `readOnlyColumn()`.

> ⚠️ **`dateRangeFilter`'s `granularity` is not cosmetic.** `'date'` (the default) sends the civil
> date as picked, for a `date_immutable` column. `'datetime'` converts it to a UTC instant, for a
> `datetime` column. Getting it wrong shifts every result by one day for every user whose browser
> is not on UTC — an invoice dated the 1st stops matching a range starting the 1st.

### 2. Render the table

```twig
<div class="panel overflow-x-auto">
    <table class="min-w-full text-sm"
           data-controller="{{ datatable_stimulus() }}"
           data-{{ datatable_stimulus() }}-api-url-value="{{ path('_api_/users{._format}_get_collection') }}"
           data-{{ datatable_stimulus() }}-columns-value="{{ columns_config|json_encode|e('html_attr') }}"
           data-{{ datatable_stimulus() }}-filters-value="{{ filters_config|json_encode|e('html_attr') }}"
           data-{{ datatable_stimulus() }}-actions-value="{{ actions_config|json_encode|e('html_attr') }}"
           data-{{ datatable_stimulus() }}-searchable-fields-value='["email","firstName","lastName"]'
           data-{{ datatable_stimulus() }}-default-order-value='[[1, "asc"]]'
           {{ include('@Datatable/datatable/_csrf.html.twig') }}>
        <thead>
            <tr>
                {% for column in columns_config %}<th>{{ column.title }}</th>{% endfor %}
                <th>{{ 'action.actions'|trans }}</th>
            </tr>
        </thead>
        <tbody></tbody>
    </table>
</div>
```

Both partials are **required**, and both go *inside* the `<table>` tag — they emit attributes, not
elements. Without `_csrf` no POST action is authorised; without `_translations` the filter chrome
renders raw keys.

`searchable-fields-value` is the list the global search hits through `OrSearchFilter`. It is not
derived from the columns on purpose: a table often searches fields it does not display.

### 3. Wire the front end

```js
// assets/app.js
import DataTable from 'datatables.net-dt';
import 'datatables.net-responsive-dt';
window.DataTable = DataTable;      // the controller waits for it rather than importing it
```

Register four Stimulus controllers under these identifiers — the markup the table renders names
them:

| File in this bundle's `assets/controllers/` | Identifier |
| --- | --- |
| `datatable_controller.js` | whatever `stimulus_identifier` says |
| `modal_controller.js` | `ui--modal` |
| `tooltip_controller.js` | `ui--tooltip` |
| `select2_controller.js` | `ui--select2` |

And the stylesheets, which use Tailwind's `@apply`:

```css
@import '@jul6art/datatable-bundle/styles/datatable.css';
@import '@jul6art/datatable-bundle/styles/datatable-custom.css';
@import '@jul6art/datatable-bundle/styles/select2.css';
@import '@jul6art/datatable-bundle/styles/tooltip.css';
@import '@jul6art/datatable-bundle/styles/blockui.css';
```

> ⚠️ **Add the bundle's `assets/` to Tailwind's `content`.** A class used only in this bundle's
> JavaScript is otherwise purged from the production stylesheet — and only from the production one,
> which is the worst place to find out.

### 4. Register the badge renderers of your own domain

The controller ships twenty generic renderers: `statusBadge`, `activeBadge`, `booleanBadge`,
`iri`, `userIri`, `userNameWithAvatar`, `nameLink`, `date`, `dateOnly`, `monoCode`, `truncated`,
`colorSwatch`, `country`, `currency`, `number0`, `number2`, `percent0`, `fileSize`, `durationMs`,
`chipList`.

> ⚠️ **Dates render as `DD/MM/YYYY HH:mm` (`dateOnly`: `DD/MM/YYYY`), in every language.** The
> format is deliberately NOT locale-driven: `Intl`'s short style renders `8/25/26` in English and
> `25/08/26` in French — the same digits in the opposite order, with nothing on screen to say
> which one you are reading. A `03/04/26` is unreadable without knowing the locale that produced
> it. The time part is still converted to the reader's timezone; only the layout is fixed.

Everything with business vocabulary in it is yours:

```js
// assets/datatable/renderers.js, imported once from the entry point
import { registerRenderers, badge } from '@jul6art/datatable-bundle/renderers';

registerRenderers({
    quoteStatusBadge: badge('datatable.quote_status', {
        draft: 'slate', sent: 'sky', accepted: 'emerald', rejected: 'red',
    }),
    // Anything `badge()` does not cover is a plain factory:
    invoiceNumber: (c) => (data, type, row) => `<code>${data}</code> · ${row.customerName}`,
});
```

An entry is `(controller) => (data, type, row, meta) => string`. The extra hop exists because a
renderer needs the controller — `c.t()` for its labels, `c.columnsValue` to read its own column's
configuration — and an arrow function has no `this` to bind.

The labels a `badge()` reads come straight from the catalogue: `badge('datatable.quote_status', …)`
resolves `datatable.quote_status.<case>` through `c.t()`. The `labelPath` is therefore a **catalogue
key prefix**, and it is the same string as the `key_prefix` of the matching `datatable.status_maps`
entry — the two go in pairs, and {@see Translation\DeclaredTranslationKeys} is what tells the
project's translation guard that those keys are alive.

### 5. Per-row and bulk endpoints

A `postAction()` submits a form carrying the `single` CSRF token; a bulk action posts `ids[]` with
the `bulk` one. On the controller side:

```php
#[IsGranted(PermissionCodes::USER_DELETE)]
#[Route('/admin/users/{id}/delete', methods: ['POST'])]
public function delete(User $user, Request $request): Response
{
    if (!$this->isCsrfTokenValid('datatable_action', (string) $request->request->get('_token'))) {
        // …
    }
}
```

`jul6art/core-bundle` ships `BulkActionRunner` for the aggregate side: token, `ids[]`, one query to
load them, the voter per row, one transaction.

> ⚠️ **A bulk endpoint carries its own `#[IsGranted]`.** The per-row voter loop is the second
> guard, not the first — an aggregate route has to fail fast with a 403 rather than iterate.

### Cross-tenant listings

```php
$columns = $admin->decorateColumns($provider->getColumns());
$filters = [...$provider->getFilters(), $admin->tenantFilter()];
```

`AdminDataTableConfig` inserts the tenant column — second, right after `id` — and its autocomplete
filter, so a super-admin page reuses the *same* provider a tenant user sees instead of a parallel
copy that drifts one column at a time. Leave `datatable.tenant.endpoint` empty in a single-tenant
application and never call it.

Per-user preferences
--------------------

Each user arranges a table for themselves: which columns they see, in what order, and a handful of
named **views** — a saved set of filters, one of which can open the table. Two dropdowns in the
toolbar, one attribute in the template.

This bundle interprets the preferences. It does **not** store them: persistence is a port the
application implements, because the shape it already has for per-user data is the shape it should
keep. A bundle that shipped an entity would force a migration on every consumer and own a table
none of them named.

### 1. Implement the store

```php
use Jul6Art\DatatableBundle\Preference\DatatablePreferenceStoreInterface;
use Symfony\Component\Security\Core\User\UserInterface;

final readonly class DatatablePreferenceStore implements DatatablePreferenceStoreInterface
{
    public function read(UserInterface $user, string $key): ?string { /* SELECT … */ }
    public function write(UserInterface $user, string $key, string $json): void { /* UPSERT … */ }
    public function delete(UserInterface $user, string $key): void { /* DELETE … */ }
}
```

Three guarantees an implementation owes:

- **one record per (user, key)** — `write()` is an upsert, never an insert. The endpoint is a `PUT`
  and the client replaces the whole blob on every save; a store that inserts blindly hits its own
  unique index and surfaces a 500 on the *second* save;
- **the value is opaque** — it is the JSON the interpreter produced, already bounded to 16 KB. Do
  not parse it, do not re-encode it;
- **`read()` answers `null` for "nothing stored yet"**, which is the state of every user on every
  table until their first save, not an error.

Until an implementation is registered, the endpoint **removes itself** from the container: the
feature is simply not there, rather than failing the build over a controller nobody asked for.

### 2. Import the route

Where it sits in the URL map is also what decides the firewall around it, so the bundle does not
declare it:

```yaml
# config/routes/datatable.yaml
datatable_preferences:
    resource: '@DatatableBundle/Controller/DatatablePreferenceController.php'
    type: attribute
    prefix: /datatable/preferences
```

```yaml
# config/packages/security.yaml
access_control:
    - { path: ^/datatable, roles: ROLE_USER }
```

One route serves **every** table: `GET`, `PUT` and `DELETE` on `/{key}`. That is what makes the
feature opt-in in one line of Twig instead of a controller per entity.

### 3. Opt a table in

```twig
<table data-controller="{{ datatable_stimulus() }}"
       …
       {{ include('@Datatable/datatable/_preferences.html.twig', { key: 'erp_product' }) }}></table>
```

The key names a **table**, not an entity: two screens listing the same entity with different columns
are two keys. Pattern: `[a-z0-9][a-z0-9_.-]{0,63}`.

Without the include, the table renders exactly as before — no request, no buttons. A table with
three columns does not need a column picker.

### Declaring wide, showing narrow

A column the reader can hide costs nothing to the reader who does not want it, so the arbitration
changed: the question is no longer "does this column deserve the width?" but "could anyone want to
see it?". Pass `hidden: true` for the second kind — the column is in the picker, absent from the
first paint, one tick away:

```php
$this->readOnlyColumn('parent', 'erp.product.fields.parent', 'erp', render: 'iri',
    extra: ['resolveField' => 'name'], responsivePriority: 10, hidden: true),
```

Three things worth knowing:

- the flag is honoured **only** when the table opted into preferences — without a picker a hidden
  column would be unreachable, so it is ignored and the column shows. A provider can therefore
  declare `hidden` before its template opts in;
- a column **declared since** a user's last save is added with the default the provider asked for,
  not visible. Shipping a batch of hidden columns does not widen the table of the people who had
  already arranged it;
- it is a DISPLAY default. A hidden column is still serialised, so `hidden: true` is never a reason
  to add a field to a `read` group.

### `resolveField` works on mobile too

An `iri` column reads its label from the field named by `extra: ['resolveField' => …]`, and until
1.4.1 that field was honoured on the desktop table but not in the mobile card list: the card looked
up its column descriptor by object identity, and `_columns` is a getter over a Stimulus value —
which re-parses its JSON attribute on every read, so no two reads are ever `===`. Every lookup
missed, and every renderer fell back to its default. `iri` defaults to `resolveField: 'name'`, so
tables whose relations expose `name` never noticed; a column resolving anything else (`label`,
`fullName`, `reference`) showed a dash on a phone and the right value on a laptop.

### What the user gets

| Panel | Actions |
| --- | --- |
| **Columns** | tick to show or hide, drag to reorder, one button back to the declared layout. The last visible column cannot be hidden |
| **Views** | apply a saved set of filters, name the current one, star one as the default, delete one |

The two buttons sit in the global search's own layout cell, immediately before it, and drop onto
their own line on a narrow viewport. The views button wears the name of the view currently applied —
the only place that is visible with the panel closed.

Precedence, decided once and worth knowing:

1. the **starred view** wins — filters *and* sort. "Default view at opening" is an explicit, durable
   instruction; the session's sticky filters are an implicit convenience. The cost is stated rather
   than hidden: with a view starred, an ad-hoc filter does not survive a navigation. That is what
   starring one asks for, and un-starring it gives the sticky behaviour back;
2. **this session's state** (`sessionStorage`) — what keeps a filter across "open a row, come back"
   when nothing is starred;
3. the **saved sort preference**, then the template's `default-order`.

A view is a **seed, not a lock**: the next filter or sort change detaches it, and the panel stops
showing it as active. It never carries a page size — that is a preference of its own.

Reordering columns saves and then **reloads the page**: visibility has a DataTables API and changes
in place, order has none (ColReorder is a separate plugin), and destroying the table to rebuild it
does not work on a Stimulus-controlled element — `destroy()` re-inserts the `<table>`, the controller
reconnects, and two instances end up owning one table. The search, filters and page all come back
from `sessionStorage`.

### What the server does and does not validate

It bounds everything: counts, lengths, one default view at most, view ids derived from names and
deduplicated, 16 KB of JSON. It does **not** check that a column key or a filter parameter exists —
one route serves every table, so at that point there is no way to know which columns `erp_product`
has. The vocabulary is reconciled **client-side**, where the declared columns are in hand: an
unknown key is dropped, a column added since the last save is appended. Both happen every time a
`*DataTableConfigProvider` is edited, and neither may lose the rest of the layout.

The response is always the sanitised state, never an echo of the request — the client adopts it, so
a name that was cut or a duplicate that was suffixed shows immediately instead of coming back
changed on the next page load.

Live refresh
------------

The controller subscribes to the Mercure feed through `services/mercure-bus.js`, a single shared
`EventSource` (browsers cap them at about six per domain). A change touching a visible row reloads
the page of data; a burst shows a "refresh" banner instead of reloading N times.

Two meta tags drive it, rendered by the application's layout:

```twig
<meta name="mercure-hub" content="{{ mercure_public_url }}">
<meta name="mercure-token-url" content="{{ path('app_mercure_token') }}">
```

The token endpoint returns `{ token, subscribed: [...] }`, and `subscribed[]` is authoritative for
both the JWT allow-list and the subscription list — so the topics are decided in one place instead
of drifting between a template and a claim. `jul6art/push-bundle` mints the token
(`SubscriberCookieFactory`) and publishes the changes (`EntityChangePublisher`).

JavaScript translations
-----------------------

Since **v2** the controller reads its labels from the catalogue `symfony/ux-translator` dumps into
the browser, through the registry of `jul6art/core-bundle`. There is no translation attribute on
the table any more.

### What a project has to do

1. Install the socle, as described in the `core-bundle` README (`symfony/ux-translator`, the
   `@symfony/ux-translator` alias, `registerTranslator()` in `assets/app.js`).
2. Add `core-bundle` to `FRONT_BUNDLES` in `bundle-assets.js`: the mixin now re-exports
   `@jul6art/core-bundle/mixins/translatable`, and without the alias the build fails to resolve.
3. Move the `datatable.*` keys the browser reads into the `javascript` domain — and with them the
   `modal.*` keys, **renamed** `datatable.modal.*` (see below).
4. Remove every `{{ include('@Datatable/datatable/_translations.html.twig') }}` from the templates.
5. Point `declaredKeys()` / `declaredPrefixes()` of the project's `AbstractJsTranslationTestCase`
   at `Translation\DeclaredTranslationKeys`.

### Breaking changes, and what each one was

| Gone | Why | What replaces it |
| --- | --- | --- |
| `@Datatable/datatable/_translations.html.twig` | it posted 8.7 kB of escaped JSON into every page carrying a table, re-sent on every request and never cached | the catalogue, dumped once into the JS bundle |
| `datatable_status_map()` | it TRANSPORTED enum labels; the browser now has them | `status_maps` stays, as a **declaration** read by `DeclaredTranslationKeys` |
| `datatable_bulk_translations()` | same, for the bulk bar and the modals | the keys are read directly |
| `|merge_recursive` | it existed to graft one translation tree onto another | — |
| `status_maps.*.path` | it said where to nest the dictionary in that tree | — |
| `status_maps.*.domain` | one domain now, for the whole browser | `core.js_translations.domain` |
| `bulk_actions` | it existed to enumerate which `modal.<type>.*` keys to translate and ship | the prefix `datatable.modal.` is declared instead |
| `modal.<type>.*` (key names) | the controller has always read them as `datatable.modal.<type>.*`; the Twig extension re-prefixed them on the way out | rename the catalogue keys to `datatable.modal.*` |

⚠️ **Two aria-labels were fixed on the way.** The controller read `bulk.select_all` while the
partial sent `datatable.bulk.select_all`, so every bulk-selection checkbox of every back office
carried the literal string `bulk.select_all` as its aria-label. Nothing could see it: the guard
checked each half in its own file. The keys are now `datatable.bulk.select_all` and
`datatable.bulk.select_row` on both sides — which, since there is only one side left, is simply
the key.

### New keys to translate

`getLanguageConfig()` no longer carries a `{ fr, en }` table of hard-coded sentences — eleven of
them in French, with English left almost empty, which is why a five-locale product fell back to a
half-filled English on three of its languages. They are now catalogue keys:

```yaml
# translations/javascript.<locale>.yaml
datatable:
    dt:
        processing: 'Traitement…'
        search: 'Rechercher :'
        length_menu: 'Afficher _MENU_ éléments'
        info: 'Affichage de _START_ à _END_ sur _TOTAL_ éléments'
        info_empty: 'Affichage de 0 à 0 sur 0 élément'
        info_filtered: '(filtré de _MAX_ éléments au total)'
        loading: 'Chargement…'
        zero_records: 'Aucun élément trouvé'
        empty_table: 'Aucune donnée disponible'
        aria:
            sort_ascending: ': activer pour trier la colonne par ordre croissant'
            sort_descending: ': activer pour trier la colonne par ordre décroissant'
```

⚠️ `_MENU_`, `_START_`, `_END_`, `_TOTAL_` and `_MAX_` are DataTables' own placeholders,
substituted long after the translator is done. They travel inside the translated string and must
survive translation untouched.

Quality assurance
-----------------

```shell
composer qa            # cs-check + rector-check + phpstan (level max) + phpunit
```

Run `composer qa`, not the single tool you have in mind: the CI's "Coding standards" job runs
Rector too, and its `lowest deps` job installs the minimum of every constraint — which is where
this ecosystem has repeatedly found what a local run could not.

License
-------

The Datatable bundle is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

&copy; 2026 [jul6art](https://devinthehood.com)
