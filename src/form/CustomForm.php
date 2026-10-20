<?php

declare(strict_types=1);

namespace Unknown\Forja\form;

use Closure;
use InvalidArgumentException;
use pocketmine\form\Form;
use pocketmine\form\FormValidationException;
use pocketmine\player\Player;
use function array_is_list;
use function array_values;
use function count;
use function get_debug_type;
use function is_array;
use function is_bool;
use function is_float;
use function is_int;
use function is_string;
use function max;
use function mb_strlen;
use function min;

/**
 * Formulário com campos (texto, interruptor, slider, lista e slider de passos).
 * A resposta do cliente é validada campo a campo antes de chegar ao plugin.
 *
 * ```php
 * $player->sendForm((new CustomForm("Editar"))
 *     ->input("nome", "Nome", placeholder: "ex.: Zoro")
 *     ->slider("qtd", "Quantidade", 1, 64, default: 1)
 *     ->onSubmit(function(Player $p, FormResponse $r) : void {
 *         $p->sendMessage("Nome: " . $r->string("nome") . ", quantidade: " . $r->int("qtd"));
 *     }));
 * ```
 */
final class CustomForm implements Form {

	/** Tamanho máximo aceito para o texto de um campo input. */
	public const MAX_INPUT_LENGTH = 1024;

	public const TYPE_LABEL = "label";
	public const TYPE_INPUT = "input";
	public const TYPE_TOGGLE = "toggle";
	public const TYPE_SLIDER = "slider";
	public const TYPE_DROPDOWN = "dropdown";
	public const TYPE_STEP_SLIDER = "step_slider";

	/**
	 * @var list<array{type: string, key: ?string, json: array<string, mixed>, min: float, max: float, options: list<string>}>
	 */
	private array $elements = [];
	/** @var array<string, true> */
	private array $keys = [];
	private int $labelCount = 0;

	/** @phpstan-var (Closure(Player, FormResponse): mixed)|null */
	private ?Closure $submitHandler = null;
	/** @phpstan-var (Closure(Player): mixed)|null */
	private ?Closure $closeHandler = null;

	public function __construct(
		private string $title
	) {}

	/** Texto informativo, sem valor na resposta. */
	public function label(string $text) : self {
		$this->labelCount++;
		return $this->add(self::TYPE_LABEL, null, ["type" => "label", "text" => $text]);
	}

	public function input(string $key, string $text, string $placeholder = "", string $default = "") : self {
		return $this->add(self::TYPE_INPUT, $key, [
			"type" => "input",
			"text" => $text,
			"placeholder" => $placeholder,
			"default" => $default
		]);
	}

	public function toggle(string $key, string $text, bool $default = false) : self {
		return $this->add(self::TYPE_TOGGLE, $key, ["type" => "toggle", "text" => $text, "default" => $default]);
	}

	/**
	 * @param float|null $default null = o mínimo; fora da faixa é ajustado para dentro dela
	 * @throws InvalidArgumentException se min > max ou step <= 0
	 */
	public function slider(string $key, string $text, float $min, float $max, float $step = 1.0, ?float $default = null) : self {
		if($min > $max){
			throw new InvalidArgumentException("Slider '$key': mínimo ($min) maior que o máximo ($max)");
		}
		if($step <= 0){
			throw new InvalidArgumentException("Slider '$key': o passo precisa ser maior que 0");
		}
		$default = max($min, min($max, $default ?? $min));

		return $this->add(self::TYPE_SLIDER, $key, [
			"type" => "slider",
			"text" => $text,
			"min" => $min,
			"max" => $max,
			"step" => $step,
			"default" => $default
		], $min, $max);
	}

	/**
	 * @param list<string> $options
	 * @throws InvalidArgumentException se não houver opções
	 */
	public function dropdown(string $key, string $text, array $options, int $default = 0) : self {
		$options = self::checkOptions($key, $options);
		return $this->add(self::TYPE_DROPDOWN, $key, [
			"type" => "dropdown",
			"text" => $text,
			"options" => $options,
			"default" => self::clampIndex($default, $options)
		], options: $options);
	}

	/**
	 * @param list<string> $steps
	 * @throws InvalidArgumentException se não houver passos
	 */
	public function stepSlider(string $key, string $text, array $steps, int $default = 0) : self {
		$steps = self::checkOptions($key, $steps);
		return $this->add(self::TYPE_STEP_SLIDER, $key, [
			"type" => "step_slider",
			"text" => $text,
			"steps" => $steps,
			"default" => self::clampIndex($default, $steps)
		], options: $steps);
	}

	/**
	 * @param Closure(Player, FormResponse): mixed $handler chamado com a resposta já validada
	 */
	public function onSubmit(Closure $handler) : self {
		$this->submitHandler = $handler;
		return $this;
	}

	/**
	 * @param Closure(Player): mixed $handler chamado quando o jogador fecha o form
	 */
	public function onClose(Closure $handler) : self {
		$this->closeHandler = $handler;
		return $this;
	}

	/**
	 * Valida a resposta crua do cliente sem executar nenhuma ação.
	 *
	 * Aceita um valor por elemento (labels = null). Também aceita a resposta sem as
	 * posições dos labels, que alguns clientes enviam; qualquer outra contagem é inválida.
	 *
	 * @return FormResponse|null a resposta, ou null se o form foi fechado
	 * @throws FormValidationException se a resposta não corresponde aos campos do form
	 */
	public function parseResponse(mixed $data) : ?FormResponse {
		if($data === null){
			return null;
		}
		if(!is_array($data) || !array_is_list($data)){
			throw new FormValidationException("Resposta de formulário inválida: esperado lista, recebido " . get_debug_type($data));
		}

		$total = count($this->elements);
		$withoutLabels = $total - $this->labelCount;
		$count = count($data);
		if($count === $total){
			$includesLabels = true;
		}elseif($this->labelCount > 0 && $count === $withoutLabels){
			$includesLabels = false;
		}else{
			throw new FormValidationException("Resposta de formulário com $count valores; esperado $total");
		}

		$fields = [];
		$position = 0;
		foreach($this->elements as $element){
			if($element["type"] === self::TYPE_LABEL){
				if($includesLabels){
					if($data[$position] !== null){
						throw new FormValidationException("Label na posição $position deveria ser null, recebido " . get_debug_type($data[$position]));
					}
					$position++;
				}
				continue;
			}

			$key = (string) $element["key"];
			$fields[$key] = [
				"type" => $element["type"],
				"value" => self::validateValue($element, $data[$position], $key),
				"options" => $element["options"]
			];
			$position++;
		}

		return new FormResponse($fields);
	}

	public function handleResponse(Player $player, $data) : void {
		$response = $this->parseResponse($data);
		if($response === null){
			if($this->closeHandler !== null){
				($this->closeHandler)($player);
			}
			return;
		}

		if($this->submitHandler !== null){
			($this->submitHandler)($player, $response);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize() : array {
		$content = [];
		foreach($this->elements as $element){
			$content[] = $element["json"];
		}
		return [
			"type" => "custom_form",
			"title" => $this->title,
			"content" => $content
		];
	}

	/**
	 * @param array{type: string, key: ?string, json: array<string, mixed>, min: float, max: float, options: list<string>} $element
	 * @throws FormValidationException
	 */
	private static function validateValue(array $element, mixed $value, string $key) : mixed {
		switch($element["type"]){
			case self::TYPE_INPUT:
				if(!is_string($value)){
					throw new FormValidationException("Campo '$key': esperado texto, recebido " . get_debug_type($value));
				}
				if(mb_strlen($value, "UTF-8") > self::MAX_INPUT_LENGTH){
					throw new FormValidationException("Campo '$key': texto maior que " . self::MAX_INPUT_LENGTH . " caracteres");
				}
				return $value;

			case self::TYPE_TOGGLE:
				if(!is_bool($value)){
					throw new FormValidationException("Campo '$key': esperado bool, recebido " . get_debug_type($value));
				}
				return $value;

			case self::TYPE_SLIDER:
				if(!is_int($value) && !is_float($value)){
					throw new FormValidationException("Campo '$key': esperado número, recebido " . get_debug_type($value));
				}
				$number = (float) $value;
				if($number < $element["min"] || $number > $element["max"]){
					throw new FormValidationException("Campo '$key': $number fora da faixa [{$element["min"]}, {$element["max"]}]");
				}
				return $number;

			default: // dropdown e step_slider
				if(!is_int($value)){
					throw new FormValidationException("Campo '$key': esperado índice inteiro, recebido " . get_debug_type($value));
				}
				if($value < 0 || $value >= count($element["options"])){
					throw new FormValidationException("Campo '$key': índice $value fora da faixa (0 a " . (count($element["options"]) - 1) . ")");
				}
				return $value;
		}
	}

	/**
	 * @param array<string, mixed> $json
	 * @param list<string> $options
	 */
	private function add(string $type, ?string $key, array $json, float $min = 0.0, float $max = 0.0, array $options = []) : self {
		if($key !== null){
			if(isset($this->keys[$key])){
				throw new InvalidArgumentException("Chave '$key' repetida no formulário");
			}
			$this->keys[$key] = true;
		}
		$this->elements[] = ["type" => $type, "key" => $key, "json" => $json, "min" => $min, "max" => $max, "options" => $options];
		return $this;
	}

	/**
	 * @param array<mixed> $options
	 * @return list<string>
	 */
	private static function checkOptions(string $key, array $options) : array {
		if(count($options) === 0){
			throw new InvalidArgumentException("Campo '$key': precisa de pelo menos uma opção");
		}
		$list = [];
		foreach(array_values($options) as $option){
			if(!is_string($option)){
				throw new InvalidArgumentException("Campo '$key': as opções precisam ser texto");
			}
			$list[] = $option;
		}
		return $list;
	}

	/**
	 * @param list<string> $options
	 */
	private static function clampIndex(int $index, array $options) : int {
		return $index >= 0 && $index < count($options) ? $index : 0;
	}
}
