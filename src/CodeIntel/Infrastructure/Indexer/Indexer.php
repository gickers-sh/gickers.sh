<?php

declare(strict_types=1);

namespace App\CodeIntel\Infrastructure\Indexer;

use Symfony\Component\DependencyInjection\Attribute\AutoconfigureTag;

/**
 * A SCIP index producer for a single extracted project tree.
 *
 * Deviation: the monolithic legacy CodeIntelService::indexCommit language switch is split into
 * tagged strategies (Go > external scip-php > native PHP), ordered by #[AsTaggedItem] priority.
 * The interface-level tag is applied to every implementor by Symfony autoconfiguration.
 */
#[AutoconfigureTag('app.scip_indexer')]
interface Indexer
{
    /**
     * Whether this indexer can handle the given project directory.
     */
    public function supports(string $projectDir): bool;

    /**
     * Produces a binary SCIP index for the project, or null when this indexer
     * cannot produce one (e.g. required external tool missing), so the handler
     * falls through to the next indexer.
     */
    public function index(string $projectDir): ?string;
}
