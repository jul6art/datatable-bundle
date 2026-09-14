<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Export;

use Jul6Art\AclBundle\Contract\AclUserInterface;
use Jul6Art\DatatableBundle\DataTable\AbstractDataTableConfigProvider;
use Jul6Art\DatatableBundle\Preference\DatatablePreferenceStoreInterface;
use Jul6Art\DataflowBundle\Io\Http\TabularResponseFactory;
use Jul6Art\DataflowBundle\Io\TabularWriterInterface;
use Jul6Art\DataflowBundle\Report\ReportRunner;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\Security\Core\User\UserInterface;

/**
 * Turns a datatable's DECLARED columns, a user's SAVED preferences, and the CURRENT request's
 * filters into a real export — reusing `dataflow-bundle`'s report engine rather than a second
 * writing pipeline (lot 2.8).
 *
 * ## Why this exists at all
 *
 * ⚠️ **The preferences already ARE the selection to export.** `DatatablePreferenceInterpreter`
 * keeps a user's visible columns, in display order; a table without per-user preferences has none
 * to read, and every declared, non-hidden column exports in DECLARATION order instead — see
 * {@see DatatableReportSpecBuilder}, which owns that translation and is what a project's own tests
 * can exercise directly, without a database.
 *
 * ## Why a service, not a route this bundle owns
 *
 * ⚠️ Unlike {@see \Jul6Art\DatatableBundle\Controller\DatatablePreferenceController}, there is no
 * ONE route for every table: the entity, the URL, and the firewall around it are the consumer's
 * own, exactly as they already are for the API Platform collection the table reads. This service
 * is the piece that would be duplicated across every such controller — call it from yours:
 *
 * ```php
 * #[Route('/admin/users/export', name: 'admin_user_export')]
 * public function export(Request $request, DatatableViewExporter $exporter): StreamedResponse
 * {
 *     return $exporter->stream(
 *         new UserDataTableConfigProvider($this->translator),
 *         'admin_user',
 *         $this->getUser(),
 *         $request,
 *         new CsvWriter(CsvDialect::excelFr()),
 *         'users',
 *     );
 * }
 * ```
 *
 * ## What makes a table exportable, and what happens when it is not
 *
 * ⚠️ {@see AbstractDataTableConfigProvider::rootEntity()} returns `null` by default — every
 * existing config provider, unmodified, is NOT exportable. `stream()` throws `\LogicException`
 * (via {@see DatatableReportSpecBuilder}) for one that has not overridden it, at the moment export
 * is actually attempted, not at boot: the alternative (a compiler pass refusing to build) would
 * need to inspect every provider in the application to decide something a single overridable
 * method already decides per table.
 */
final readonly class DatatableViewExporter
{
    public function __construct(
        private ReportRunner $runner,
        private TabularResponseFactory $responses,
        private DatatablePreferenceStoreInterface $preferences,
        private DatatableReportSpecBuilder $specBuilder,
    ) {
    }

    /**
     * @param (\Closure(\Doctrine\ORM\QueryBuilder, string): void)|null $scope the same tenant-scoping
     *                                                                        closure `ReportRunner::run()`
     *                                                                        takes — this service knows
     *                                                                        no more about a tenant
     *                                                                        than the runner does
     *
     * @throws \LogicException when $provider->rootEntity() is null
     */
    public function stream(
        AbstractDataTableConfigProvider $provider,
        string $tableKey,
        UserInterface&AclUserInterface $actor,
        Request $request,
        TabularWriterInterface $writer,
        string $basename,
        ?\Closure $scope = null,
    ): StreamedResponse {
        $spec = $this->specBuilder->build($provider, $this->preferences->read($actor, $tableKey), $request);

        $result = $this->runner->run($spec, $actor, limit: ReportRunner::DEFAULT_LIMIT, scope: $scope);

        return $this->responses->stream($writer, $result->header(), $result->rows(), $basename);
    }
}
