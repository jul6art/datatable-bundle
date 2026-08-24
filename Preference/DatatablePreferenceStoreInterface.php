<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Preference;

use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Where one user's table preferences are kept — the one thing this bundle refuses to decide.
 *
 * The bundle interprets the preferences (sanitises, bounds, versions them) and serves the three
 * HTTP operations; it stores nothing. Persistence belongs to the application, because the shape it
 * already has for per-user data is the shape it should keep: a dedicated table here, a `Setting`
 * row scoped to `user` there, a JSON column on the account somewhere else. A bundle that shipped
 * an entity would force a migration on every consumer and own a table none of them named.
 *
 * ## What an implementation must guarantee
 *
 * - **One record per (user, key).** `write()` is an upsert, never an insert: the endpoint is a
 *   `PUT` and the client replaces the whole blob on every save. An implementation that inserts
 *   blindly hits its own unique index and surfaces a 500 on the second save.
 * - **The value is opaque.** It is the JSON produced by {@see DatatablePreferenceInterpreter},
 *   already bounded to {@see DatatablePreferenceInterpreter::MAX_BYTES}. Do not parse it, do not
 *   reformat it — a store that re-encodes changes what the next read gives back.
 * - **The user comes from the token, never from the payload.** That is why this contract takes a
 *   `UserInterface` and not an id the client could have sent: it makes writing another user's
 *   preferences structurally impossible, so no ownership voter is needed.
 * - **`read()` answers `null` for "nothing stored yet"**, which is not an error: it is the state
 *   of every user on every table until the first save.
 */
interface DatatablePreferenceStoreInterface
{
    /**
     * The raw JSON stored for this user and this table, or null when there is none.
     */
    public function read(UserInterface $user, string $key): ?string;

    /**
     * Creates or replaces the record for this user and this table.
     */
    public function write(UserInterface $user, string $key, string $json): void;

    /**
     * Removes the record, back to the application's defaults. Deleting what is not there is not an
     * error — the endpoint answers 204 either way.
     */
    public function delete(UserInterface $user, string $key): void;
}
