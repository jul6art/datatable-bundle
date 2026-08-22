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
| `symfony/security-csrf` | `_csrf.html.twig` calls `csrf_token()`, which does not exist — the partial fails at render |
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

    # The Stimulus identifier the table controller answers to. It decides the data-attribute
    # prefix the shipped partials emit, so it has to match how the application registered the
    # controller — a build that derives identifiers from a path gives `core--datatable` to a
    # file living in `assets/controllers/core/`.
    stimulus_identifier: datatable

    csrf:
        single: datatable_action     # per-row POST actions
        bulk: bulk_action            # the /bulk-* endpoints

    # Added to the thirteen types the bundle ships, never replacing them.
    bulk_actions: [invite, validate]

    # The enum catalogues the badge renderers read. Business vocabulary, hence configuration.
    status_maps:
        quote_status:
            keys: [draft, sent, accepted, rejected]
        expense_status:
            domain: hr
            key_prefix: 'hr.expense.status.'
            keys: [draft, submitted, approved]
        country:
            path: [organization, country]
            key_prefix: 'organization.country.'
            keys: [fr, be, lu]

    # Only for a multi-tenant back office. Leave the endpoint empty otherwise.
    tenant:
        endpoint: /api/organizations
        label_key: datatable.col.organization
```

`datatable.enabled`, `datatable.stimulus_identifier`, `datatable.csrf.single` and
`datatable.csrf.bulk` are exposed as container parameters.

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
           {{ include('@Datatable/datatable/_csrf.html.twig') }}
           {{ include('@Datatable/datatable/_translations.html.twig', {
               extra_translations: datatable_status_map(['quote_status']),
           }) }}>
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

The labels a `badge()` reads come from `datatable_status_map()`, which is why the two share a
vocabulary: `badge('datatable.quote_status', …)` reads what the `quote_status` entry of
`datatable.status_maps` wrote.

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

The datatable bundle is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
