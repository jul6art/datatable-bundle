<?php

declare(strict_types=1);

namespace Jul6Art\DatatableBundle\Twig;

use Twig\Attribute\AsTwigFunction;

/**
 * Tells a template three things it cannot derive on its own: the Stimulus identifier the table
 * controller answers to, and the two CSRF token ids the controller-side checks expect.
 *
 * ```twig
 * <table data-controller="{{ datatable_stimulus() }}"
 *        data-{{ datatable_stimulus() }}-api-url-value="{{ path('_api_/users{._format}_get_collection') }}">
 * ```
 *
 * ## Why the identifier is not a constant
 *
 * A Stimulus identifier is decided by whatever registers the controller. A build that walks
 * `assets/controllers/**` derives it from the path, so the very same controller answers to
 * `datatable` in one application and `core--datatable` in another. The data-attribute prefix
 * follows the identifier, so a partial that hard-coded one would emit attributes the controller
 * never reads — and a Stimulus controller that receives no values does not fail, it renders an
 * empty table.
 *
 * ## Why the token ids are not constants either
 *
 * They are the contract between this table and the application's controllers. An application that
 * already validates `datatable_action` in two hundred routes does not rename them to adopt a
 * bundle.
 */
final readonly class DataTableCsrfExtension
{
    public function __construct(
        private string $stimulusIdentifier = 'datatable',
        private string $singleTokenId = 'datatable_action',
        private string $bulkTokenId = 'bulk_action',
    ) {
    }

    #[AsTwigFunction(name: 'datatable_stimulus')]
    public function stimulusIdentifier(): string
    {
        return $this->stimulusIdentifier;
    }

    /**
     * @param string $kind `single` for the per-row POST actions, `bulk` for the /bulk-* endpoints
     *
     * @throws \InvalidArgumentException on anything else, rather than returning a token id nobody
     *                                   validates
     */
    #[AsTwigFunction(name: 'datatable_csrf_token_id')]
    public function csrfTokenId(string $kind): string
    {
        return match ($kind) {
            'single' => $this->singleTokenId,
            'bulk' => $this->bulkTokenId,
            default => throw new \InvalidArgumentException(\sprintf('Unknown datatable CSRF token kind "%s". Expected "single" or "bulk".', $kind)),
        };
    }
}
