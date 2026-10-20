<?php

declare(strict_types=1);

namespace Unknown\Forja\data;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;
use ReflectionUnionType;
use RuntimeException;

/**
 * Uma propriedade que participa da conversão: o nome da propriedade e a chave usada no array.
 */
final class DataField {

	public readonly string $name;
	private ?bool $nested = null;

	public function __construct(
		public readonly ReflectionProperty $property,
		public readonly string $key
	) {
		$this->name = $property->getName();
	}

	/**
	 * True se o tipo declarado inclui alguma classe. Toda classe do tipo precisa
	 * usar {@see DataObjectTrait} (nela ou num pai) ou ser um enum.
	 *
	 * @throws RuntimeException se o tipo tiver uma classe que não pode ser convertida
	 */
	public function hasNestedTypes() : bool {
		if($this->nested !== null){
			return $this->nested;
		}

		$type = $this->property->getType();
		$namedTypes = match(true){
			$type instanceof ReflectionNamedType => [$type],
			$type instanceof ReflectionUnionType => $type->getTypes(),
			default => []
		};

		$classCount = 0;
		foreach($namedTypes as $namedType){
			if(!$namedType instanceof ReflectionNamedType || $namedType->isBuiltin()){
				continue;
			}

			$class = new ReflectionClass($namedType->getName());
			if(!$class->isEnum() && !self::usesDataTrait($class)){
				throw new RuntimeException("The type '{$class->getName()}' is not a data object type");
			}
			$classCount++;
		}

		return $this->nested = $classCount > 0;
	}

	/**
	 * @param ReflectionClass<object> $class
	 */
	private static function usesDataTrait(ReflectionClass $class) : bool {
		for($current = $class; $current !== false; $current = $current->getParentClass()){
			if(in_array(DataObjectTrait::class, $current->getTraitNames(), true)){
				return true;
			}
		}
		return false;
	}
}
