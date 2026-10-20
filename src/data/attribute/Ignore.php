<?php

declare(strict_types=1);

namespace Unknown\Forja\data\attribute;

use Attribute;

/**
 * A propriedade marcada fica fora do toArray() e do update().
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Ignore {}
