<?php

declare(strict_types=1);

namespace Unknown\Forja\config;

use Logger;
use function array_is_list;
use function array_key_exists;
use function array_keys;
use function array_map;
use function count;
use function get_debug_type;
use function is_array;
use function is_bool;
use function is_finite;
use function is_float;
use function is_int;
use function is_string;
use function json_encode;
use function mb_strimwidth;
use function mb_strtolower;
use function preg_match;
use function strlen;
use function strpos;
use function substr;
use function trim;
use const JSON_UNESCAPED_SLASHES;
use const JSON_UNESCAPED_UNICODE;

/**
 * Leitura tipada e tolerante de uma config já carregada. Nenhum getter lança exceção:
 * valor ausente devolve o padrão em silêncio; valor do tipo errado ou fora da faixa
 * registra um aviso explicando o problema e devolve o padrão.
 *
 * Caminhos usam ponto para entrar em seções: `economy.money.class`. Uma chave que
 * contém ponto (ex.: `meuplugin.vip`) também é encontrada.
 */
final class ConfigView {

	/**
	 * @param array<mixed> $data
	 */
	private function __construct(
		private array $data,
		private Logger $logger,
		private string $sourceName,
		private string $prefix
	) {}

	/**
	 * @param array<mixed> $data
	 * @param string $sourceName nome mostrado nos avisos (ex.: "config.yml")
	 */
	public static function fromArray(array $data, Logger $logger, string $sourceName) : self {
		return new self($data, $logger, $sourceName, "");
	}

	public function int(string $path, int $default, ?int $min = null, ?int $max = null) : int {
		$value = $this->lookup($path, $found);
		if(!$found){
			return $default;
		}

		$number = null;
		if(is_int($value)){
			$number = $value;
		}elseif(is_float($value) && is_finite($value) && $value === (float) (int) $value){
			$number = (int) $value;
		}elseif(is_string($value) && preg_match('/^\s*[+-]?\d{1,18}\s*$/', $value) === 1){
			$number = (int) trim($value);
		}

		if($number === null || ($min !== null && $number < $min) || ($max !== null && $number > $max)){
			$this->warn($path, "int" . self::describeRange($min, $max), $value, (string) $default);
			return $default;
		}
		return $number;
	}

	public function float(string $path, float $default, ?float $min = null, ?float $max = null) : float {
		$value = $this->lookup($path, $found);
		if(!$found){
			return $default;
		}

		$number = null;
		if(is_int($value) || (is_float($value) && is_finite($value))){
			$number = (float) $value;
		}elseif(is_string($value) && preg_match('/^\s*[+-]?(\d+(\.\d*)?|\.\d+)\s*$/', $value) === 1){
			$number = (float) trim($value);
		}

		if($number === null || ($min !== null && $number < $min) || ($max !== null && $number > $max)){
			$this->warn($path, "número" . self::describeRange($min, $max), $value, (string) $default);
			return $default;
		}
		return $number;
	}

	/** Aceita bool, 0/1 e os textos true/false, sim/não, on/off, yes/no. */
	public function bool(string $path, bool $default) : bool {
		$value = $this->lookup($path, $found);
		if(!$found){
			return $default;
		}

		if(is_bool($value)){
			return $value;
		}
		if($value === 0 || $value === 1){
			return $value === 1;
		}
		if(is_string($value)){
			$text = mb_strtolower(trim($value), "UTF-8");
			if($text === "true" || $text === "sim" || $text === "on" || $text === "yes" || $text === "1"){
				return true;
			}
			if($text === "false" || $text === "não" || $text === "nao" || $text === "off" || $text === "no" || $text === "0"){
				return false;
			}
		}

		$this->warn($path, "true/false", $value, $default ? "true" : "false");
		return $default;
	}

	/** Números são aceitos e convertidos para texto. */
	public function string(string $path, string $default) : string {
		$value = $this->lookup($path, $found);
		if(!$found){
			return $default;
		}

		if(is_string($value)){
			return $value;
		}
		if(is_int($value) || is_float($value)){
			return (string) $value;
		}

		$this->warn($path, "texto", $value, self::describe($default));
		return $default;
	}

	/**
	 * Lista de textos. Um texto sozinho vira uma lista com um item; números viram texto;
	 * itens de outros tipos são ignorados com aviso.
	 *
	 * @param list<string> $default
	 * @return list<string>
	 */
	public function stringList(string $path, array $default = []) : array {
		$value = $this->lookup($path, $found);
		if(!$found){
			return $default;
		}

		if(is_string($value)){
			return [$value];
		}
		if(!is_array($value) || !array_is_list($value)){
			$this->warn($path, "lista de textos", $value, count($default) === 0 ? "lista vazia" : "a lista padrão");
			return $default;
		}

		$list = [];
		foreach($value as $index => $item){
			if(is_string($item)){
				$list[] = $item;
			}elseif(is_int($item) || is_float($item)){
				$list[] = (string) $item;
			}else{
				$this->warn("$path.$index", "texto", $item, "item ignorado");
			}
		}
		return $list;
	}

	/**
	 * Uma subseção como outra view (os avisos mostram o caminho completo).
	 * Seção ausente ou que não é seção devolve uma view vazia.
	 */
	public function section(string $path) : self {
		$value = $this->lookup($path, $found);
		if(!$found){
			$value = [];
		}elseif(!is_array($value)){
			$this->warn($path, "seção", $value, "seção vazia");
			$value = [];
		}
		return new self($value, $this->logger, $this->sourceName, $this->prefix . $path . ".");
	}

	/**
	 * Chaves do nível atual.
	 *
	 * @return list<string>
	 */
	public function keys() : array {
		return array_map(static fn(int|string $key) : string => (string) $key, array_keys($this->data));
	}

	/** True se o caminho existe e não é nulo. */
	public function has(string $path) : bool {
		$this->lookup($path, $found);
		return $found;
	}

	/** Valor cru, sem validação. */
	public function raw(string $path, mixed $default = null) : mixed {
		$value = $this->lookup($path, $found);
		return $found ? $value : $default;
	}

	/**
	 * @return array<mixed>
	 */
	public function toArray() : array {
		return $this->data;
	}

	public function getSourceName() : string {
		return $this->sourceName;
	}

	public function getLogger() : Logger {
		return $this->logger;
	}

	/**
	 * @param-out bool $found
	 */
	private function lookup(string $path, ?bool &$found) : mixed {
		$found = false;
		$value = self::resolve($this->data, $path, $found);
		if($found && $value === null){
			$found = false; // "chave:" vazia no YAML conta como ausente
		}
		return $value;
	}

	/**
	 * Procura a chave literal primeiro; depois tenta dividir no primeiro ponto possível.
	 *
	 * @param array<mixed> $data
	 */
	private static function resolve(array $data, string $path, bool &$found) : mixed {
		if(array_key_exists($path, $data)){
			$found = true;
			return $data[$path];
		}

		$offset = 0;
		while(($dot = strpos($path, ".", $offset)) !== false){
			$head = substr($path, 0, $dot);
			if(isset($data[$head]) && is_array($data[$head])){
				$value = self::resolve($data[$head], substr($path, $dot + 1), $found);
				if($found){
					return $value;
				}
			}
			$offset = $dot + 1;
			if($offset >= strlen($path)){
				break;
			}
		}

		$found = false;
		return null;
	}

	private function warn(string $path, string $expected, mixed $received, string $using) : void {
		$this->logger->warning("[{$this->sourceName}] chave '{$this->prefix}$path': esperado $expected, recebido " . self::describe($received) . "; usando $using");
	}

	private static function describeRange(int|float|null $min, int|float|null $max) : string {
		return match(true){
			$min !== null && $max !== null => " entre $min e $max",
			$min !== null => " maior ou igual a $min",
			$max !== null => " menor ou igual a $max",
			default => ""
		};
	}

	private static function describe(mixed $value) : string {
		if(is_string($value)){
			return '"' . mb_strimwidth($value, 0, 60, "...", "UTF-8") . '"';
		}
		if(is_bool($value)){
			return $value ? "true" : "false";
		}
		if(is_int($value) || is_float($value)){
			return (string) $value;
		}
		if(is_array($value)){
			$json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			return (array_is_list($value) ? "lista " : "seção ") . ($json !== false ? mb_strimwidth($json, 0, 60, "...", "UTF-8") : "");
		}
		return get_debug_type($value);
	}
}
