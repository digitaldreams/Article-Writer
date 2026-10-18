<?php

namespace App\Planning;

/** A job message about one reference (extract, digest), so other slices can find a reference's jobs. */
interface ReferenceJob
{
    public int $referenceId { get; }
}
