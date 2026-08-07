<?php

declare(strict_types=1);

namespace App\Services\Social;

use LogicException;

/**
 * Compatibility tombstone for the retired personal LinkedIn publisher.
 *
 * The class name remains loadable for historical references, but no runtime
 * surface can construct a member publisher or reach member authoring code.
 */
final class LinkedInPublisher
{
    public function __construct()
    {
        throw new LogicException('Personal LinkedIn publisher is unavailable.');
    }
}
