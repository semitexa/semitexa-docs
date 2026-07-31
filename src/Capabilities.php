<?php

declare(strict_types=1);

namespace Semitexa\Docs;

use Semitexa\Core\Attribute\Capability;

/**
 * What this package offers, for the capability catalog.
 *
 * The package ships no attributes of its own, so there is nothing for a
 * mechanism-level declaration to hang on — and without this the package is
 * invisible to anyone whose project has not installed it, which is precisely
 * the audience worth telling. The convention is one `Capabilities` class per
 * package: a definite place to look, and a definite place for a guard to check.
 *
 * Nothing reads this at runtime.
 */
#[Capability(
    id: 'docs.official',
    summary: 'The framework documentation shipped as an installed module and served by the project itself.',
    useWhen: 'The conventions have to be readable from inside a running project, at the version that project actually installed.',
    avoidWhen: 'A production site with no reason to serve framework docs — weight its end users never asked for.',
    replaces: [
        'framework documentation pasted into the project, drifting from the version installed',
        'reading package source to recover a convention nobody wrote down',
    ],
)]
final class Capabilities
{
}
