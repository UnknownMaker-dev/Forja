<?php

declare(strict_types=1);

namespace Unknown\Forja\form;

use InvalidArgumentException;
use function implode;
use function is_finite;
use function preg_match;
use function round;
use function str_replace;
use function trim;

/**
 * Resposta já validada de um {@see CustomForm}. As chaves são as dadas nos campos.
 * Pedir uma chave que não existe, ou ler um campo com o getter do tipo errado,
 * é erro de programação e lança {@see InvalidArgumentException}.
 */
final class FormResponse {

	/**
	 * @internal criado pelo {@see CustomForm}
	 * @param array<string, array{type: string, value: mixed, options: list<string>}> $fields
	 */
	public function __construct(
		private array $fields
	) {}

	/** Texto digitado num campo input. */
	public function string(string $key) : string {
		return (string) $this->value($key, CustomForm::TYPE_INPUT);
	}

	/** Estado de um toggle. */
	public function bool(string $key) : bool {
		return (bool) $this->value($key, CustomForm::TYPE_TOGGLE);
	}

	/** Valor de um slider. */
	public function float(string $key) : float {
		return (float) $this->value($key, CustomForm::TYPE_SLIDER);
	}

	/** Valor de um slider arredondado para inteiro. */
	public function int(string $key) : int {
		return (int) round((float) $this->value($key, CustomForm::TYPE_SLIDER));
	}

	/** Índice escolhido num dropdown ou stepSlider. */
	public function index(string $key) : int {
		return (int) $this->value($key, CustomForm::TYPE_DROPDOWN, CustomForm::TYPE_STEP_SLIDER);
	}

	/** Texto da opção escolhida num dropdown ou stepSlider. */
	public function option(string $key) : string {
		$index = $this->index($key);
		return $this->fields[$key]["options"][$index];
	}

	/**
	 * Lê um input como inteiro. Aceita só um número inteiro (com sinal opcional e espaços nas
	 * pontas) dentro da faixa; qualquer outra coisa devolve null para o plugin avisar o jogador.
	 */
	public function parseInt(string $key, ?int $min = null, ?int $max = null) : ?int {
		$text = trim($this->string($key));
		if(preg_match('/^[+-]?\d{1,18}$/', $text) !== 1){
			return null;
		}
		$number = (int) $text;
		if(($min !== null && $number < $min) || ($max !== null && $number > $max)){
			return null;
		}
		return $number;
	}

	/**
	 * Lê um input como número decimal (aceita vírgula ou ponto). Notação científica,
	 * infinito e NaN não são aceitos. Fora da faixa ou inválido devolve null.
	 */
	public function parseFloat(string $key, ?float $min = null, ?float $max = null) : ?float {
		$text = str_replace(",", ".", trim($this->string($key)));
		if(preg_match('/^[+-]?(\d{1,15}(\.\d{0,10})?|\.\d{1,10})$/', $text) !== 1){
			return null;
		}
		$number = (float) $text;
		if(!is_finite($number) || ($min !== null && $number < $min) || ($max !== null && $number > $max)){
			return null;
		}
		return $number;
	}

	/** True se o formulário tem um campo com esta chave. */
	public function has(string $key) : bool {
		return isset($this->fields[$key]);
	}

	/**
	 * Todos os valores: texto (input), bool (toggle), float (slider) e índice (dropdown/stepSlider).
	 *
	 * @return array<string, mixed>
	 */
	public function all() : array {
		$values = [];
		foreach($this->fields as $key => $field){
			$values[$key] = $field["value"];
		}
		return $values;
	}

	private function value(string $key, string ...$types) : mixed {
		if(!isset($this->fields[$key])){
			throw new InvalidArgumentException("O formulário não tem o campo '$key'");
		}
		$field = $this->fields[$key];
		foreach($types as $type){
			if($field["type"] === $type){
				return $field["value"];
			}
		}
		throw new InvalidArgumentException("O campo '$key' é do tipo {$field["type"]}, não " . implode("/", $types));
	}
}
