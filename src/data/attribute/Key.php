<?php

declare(strict_types=1);

namespace Unknown\Forja\data\attribute;

use Attribute;

/**
 * Define o nome da chave no array/YAML. Vazio = usa o nome da propriedade.
 */
#[Attribute(Attribute::TARGET_PROPERTY | Attribute::TARGET_PARAMETER)]
final class Key {

	public function __construct(
		public readonly string $name = ""
	) {}
}
