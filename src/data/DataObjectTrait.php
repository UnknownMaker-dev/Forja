<?php

declare(strict_types=1);

namespace Unknown\Forja\data;

use ReflectionClass;
use ReflectionNamedType;
use ReflectionProperty;

/**
 * Converte um objeto de configuração em array (para salvar em YAML) e de volta.
 *
 * Toda propriedade não estática entra na conversão. Use {@see attribute\Key} para
 * mudar o nome da chave e {@see attribute\Ignore} para deixar uma propriedade de fora.
 *
 * ```php
 * final class MinhaConfig {
 *     use DataObjectTrait;
 *
 *     #[Key(name: "vida_max")]
 *     public int $maxHealth = 20;
 *
 *     public PatrolSettings $patrol; // array no YAML vira PatrolSettings no update()
 *
 *     protected static function setup() : void {
 *         self::registerDeserializer("vida_max", function(int $vida, ValidationErrors $erros) : int {
 *             if($vida <= 0) $erros->addError("Vida precisa ser maior que 0");
 *             return $vida;
 *         });
 *     }
 * }
 * ```
 */
trait DataObjectTrait {

	/** @var array{in: array<string, callable>, out: array<string, callable>} */
	private static array $dataHooks = ["in" => [], "out" => []];
	private static bool $dataHooksReady = false;

	/**
	 * Chamado uma vez por classe, na primeira conversão, para registrar os
	 * serializers/deserializers. Sobrescreva na classe que usa a trait.
	 */
	protected static function setup() : void {}

	/**
	 * Recebe o valor já convertido para o tipo da propriedade e um {@see ValidationErrors};
	 * retorna o valor final.
	 */
	protected static function registerDeserializer(string $key, callable $hook) : void {
		self::$dataHooks["in"][$key] = $hook;
	}

	/** Recebe o valor da propriedade e retorna o que vai para o array. */
	protected static function registerSerializer(string $key, callable $hook) : void {
		self::$dataHooks["out"][$key] = $hook;
	}

	private static function dataHook(string $direction, string $key) : ?callable {
		if(!self::$dataHooksReady){
			self::$dataHooksReady = true;
			static::setup();
		}
		return self::$dataHooks[$direction][$key] ?? null;
	}

	/**
	 * @return array<string, mixed>
	 */
	public function toArray() : array {
		$out = [];

		foreach(DataSchema::of(static::class) as $field){
			$value = $this->{$field->name};

			$out[$field->key] = match(true){
				is_object($value) && $field->hasNestedTypes() && method_exists($value, "toArray") => $value->toArray(),
				($hook = self::dataHook("out", $field->key)) !== null => $hook($value),
				is_array($value) => array_map(
					static fn(mixed $item) : mixed => is_object($item) && method_exists($item, "toArray") ? $item->toArray() : $item,
					$value
				),
				default => $value
			};
		}

		return $out;
	}

	/**
	 * Aplica os valores do array no objeto. Chaves ausentes mantêm o valor atual, a menos
	 * que a propriedade tenha valor padrão declarado (aí volta para o padrão).
	 * Nada é alterado se algum deserializer reportar erro.
	 *
	 * @param array<string, mixed> $data
	 * @throws DataException com a lista de erros de validação
	 */
	public function update(array $data) : static {
		$errors = new ValidationErrors();
		$pending = [];

		foreach(DataSchema::of(static::class) as $field){
			$property = $field->property;

			if(!isset($data[$field->key]) && !$property->hasDefaultValue()){
				continue;
			}

			$value = self::castToPropertyType($property, $data[$field->key] ?? $property->getDefaultValue());

			$hook = self::dataHook("in", $field->key);
			if($hook !== null){
				$value = $hook($value, $errors);
			}

			$pending[$field->name] = [$property, $value];
		}

		if($errors->hasErrors()){
			throw new DataException(implode("", array_map(static fn(string $error) : string => "- $error\n", $errors->getErrors())));
		}

		foreach($pending as $name => [$property, $value]){
			$this->{$name} = is_array($value) ? (self::buildNestedObject($property, $value) ?? $value) : $value;
		}

		return $this;
	}

	private static function castToPropertyType(ReflectionProperty $property, mixed $value) : mixed {
		$type = $property->getType();
		if(!$type instanceof ReflectionNamedType){
			return $value;
		}

		return match($type->getName()){
			"string" => (string) $value,
			"int" => (int) $value,
			"float" => (float) $value,
			"bool" => (bool) $value,
			"array" => (array) $value,
			default => $value
		};
	}

	/**
	 * Se a propriedade é tipada com uma classe que tem update(), cria uma instância
	 * (sem chamar o construtor) e preenche com o array.
	 *
	 * @param array<string, mixed> $value
	 */
	private static function buildNestedObject(ReflectionProperty $property, array $value) : ?object {
		$type = $property->getType();
		if(!$type instanceof ReflectionNamedType || $type->isBuiltin() || !class_exists($type->getName())){
			return null;
		}

		$instance = (new ReflectionClass($type->getName()))->newInstanceWithoutConstructor();
		if(!method_exists($instance, "update")){
			return null;
		}

		$instance->update($value);
		return $instance;
	}
}
