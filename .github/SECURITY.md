# Security Policy

## Supported versions

`jul6art/datatable-bundle` is installed by other applications through Composer, so a fix
here reaches them the moment they update. Only the current major line gets one.

| Version | Supported |
| --- | --- |
| `2.x` | ✅ |
| `1.x` | ❌ |
| any older tag or fork | ❌ |

Support means security fixes on the latest release of that line — upgrade to it before
reporting, in case the problem is already gone.

## What is in scope

A table is a read of your database driven by request input, and a bulk action is a write
over many rows at once. That is the shape of what matters here:

* **A bulk or row action performed without a per-row access decision**, or one whose subject
  can be swapped for a row the caller may not touch.
* **A column filter, sort or search reaching a property the resource does not expose**, or
  being reflected into DQL rather than going through the resource's filters.
* **Preferences crossing accounts** — one user reading, writing or exhausting another's
  columns or named views, including through an identifier taken from the request.
* **A Mercure topic that pushes what a subscriber may not see** — row data in the payload,
  or a topic broad enough that a subscriber learns of records they cannot read.
* **Cross-site scripting through a table** — a column label, a rendered value, an action
  label or a confirmation modal escaping its context.
* **An unbounded page size** reachable from the client, or an export path that ignores the
  collection's bounds.

Out of scope: vulnerabilities in Symfony, Doctrine, API Platform or any other third-party
package — report those to the project that owns the code, and they will reach you through
your own `composer update`. Also out of scope: an application that misconfigures this bundle
in a way the README warns against, though a warning that turns out to be easy to miss is
worth an issue of its own.

## Reporting a vulnerability

**Do not open a public issue for a security problem.**

Use [GitHub's private vulnerability reporting](https://github.com/jul6art/datatable-bundle/security/advisories/new)
(the **Security** tab → *Report a vulnerability*). It opens a draft advisory only
you and the maintainers can read, and it is the channel this project prefers —
no email address needs to be published for it to work.

Please include:

* the version of `jul6art/datatable-bundle` and of Symfony you are running,
* the relevant part of your bundle configuration,
* the shortest reproduction you have — ideally a failing test against this
  repository, since that is what a fix will be built on,
* what an attacker gains: which check is bypassed, which data is read or
  written, and whether authentication is required.

## What to expect

* An acknowledgement within **7 days**.
* An assessment — accepted, out of scope, or needing more detail — within
  **14 days**.
* For an accepted report: a fix released on the supported line, a
  [security advisory](https://github.com/jul6art/datatable-bundle/security/advisories)
  describing the impact and the version to upgrade to, and credit in it unless
  you ask otherwise.

Please give the maintainers a reasonable window to ship a release before disclosing
publicly. This project runs no bug-bounty programme and offers no payment.