<?php

declare(strict_types=1);

namespace Unknown\Forja\data;

use Unknown\Forja\data\attribute\Ignore;
use Unknown\Forja\data\attribute\Key;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionProperty;

/**
 * Lista (em cache) as propriedades de uma classe que participam da conversão.
 */
final class DataSchema {

	/** @var array<class-string, list<DataField>> */
	private static array $cache = [];

	/**
	 * @param class-string $class
	 * @return list<DataField>
	 */
	public static function of(string $class) : array {
		return self::$cache[$class] ??= self::build(new ReflectionClass($class));
	}

	/**
	 * @param ReflectionClass<object> $class
	 * @return list<DataField>
	 */
	private static function build(ReflectionClass $class) : array {
		$fields = [];

		foreach($class->getProperties() as $property){
			if($property->isStatic() || count($property->getAttributes(Ignore::class)) > 0){
				continue;
			}
			$fields[] = new DataField($property, self::keyOf($property));
		}

		return $fields;
	}

	private static function keyOf(ReflectionProperty $property) : string {
		$keys = $property->getAttributes(Key::class, ReflectionAttribute::IS_INSTANCEOF);

		if(count($keys) > 1){
			throw new DataException("Property '{$property->getName()}' has more than one Key attribute");
		}

		$name = count($keys) === 1 ? $keys[0]->newInstance()->name : "";
		return $name !== "" ? $name : $property->getName();
	}
}
