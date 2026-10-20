<?php

declare(strict_types=1);

namespace Unknown\Forja\command;

use pocketmine\player\Player;
use pocketmine\Server;
use function array_map;
use function array_slice;
use function array_values;
use function count;
use function implode;
use function is_finite;
use function preg_match;
use function str_replace;
use function mb_strtolower;

/**
 * Argumentos de um subcomando, com leitura validada. Os índices começam em 0
 * (o nome do subcomando não conta).
 *
 * - Argumento obrigatório ausente ⇒ {@see UsageException} (o comando mostra o uso).
 * - Argumento presente mas inválido ⇒ {@see CommandFeedback} com a explicação.
 */
final class Args {

	/** @var list<string> */
	private array $args;

	/**
	 * @param array<string|int|float> $args
	 * @param Server|null $server usado para procurar jogadores; null = o servidor atual
	 */
	public function __construct(
		array $args,
		private ?Server $server = null
	) {
		$this->args = array_values(array_map(static fn(mixed $arg) : string => (string) $arg, $args));
	}

	public function count() : int {
		return count($this->args);
	}

	public function has(int $index) : bool {
		return isset($this->args[$index]);
	}

	/**
	 * @return list<string>
	 */
	public function all() : array {
		return $this->args;
	}

	public function string(int $index) : string {
		return $this->args[$index] ?? throw new UsageException("Argumento $index ausente");
	}

	public function optString(int $index, string $default = "") : string {
		return $this->args[$index] ?? $default;
	}

	public function int(int $index, ?int $min = null, ?int $max = null) : int {
		$raw = $this->string($index);
		if(preg_match('/^[+-]?\d{1,18}$/', $raw) !== 1){
			throw new CommandFeedback("§cNúmero inteiro inválido: §f$raw");
		}
		$value = (int) $raw;
		self::checkRange($value, $min, $max);
		return $value;
	}

	public function optInt(int $index, int $default, ?int $min = null, ?int $max = null) : int {
		return $this->has($index) ? $this->int($index, $min, $max) : $default;
	}

	/** Aceita ponto ou vírgula como separador decimal. */
	public function float(int $index, ?float $min = null, ?float $max = null) : float {
		$raw = $this->string($index);
		$normalized = str_replace(",", ".", $raw);
		if(preg_match('/^[+-]?(\d{1,15}(\.\d{0,10})?|\.\d{1,10})$/', $normalized) !== 1 || !is_finite((float) $normalized)){
			throw new CommandFeedback("§cNúmero inválido: §f$raw");
		}
		$value = (float) $normalized;
		self::checkRange($value, $min, $max);
		return $value;
	}

	public function optFloat(int $index, float $default, ?float $min = null, ?float $max = null) : float {
		return $this->has($index) ? $this->float($index, $min, $max) : $default;
	}

	/** Aceita sim/não, true/false, on/off e 1/0. */
	public function bool(int $index) : bool {
		$raw = $this->string($index);
		return match(mb_strtolower($raw, "UTF-8")){
			"sim", "s", "true", "on", "1", "yes" => true,
			"não", "nao", "n", "false", "off", "0", "no" => false,
			default => throw new CommandFeedback("§cValor inválido: §f{$raw}§c. Use sim ou não.")
		};
	}

	public function optBool(int $index, bool $default) : bool {
		return $this->has($index) ? $this->bool($index) : $default;
	}

	/**
	 * Jogador online pelo nome exato (sem diferenciar maiúsculas).
	 *
	 * @throws CommandFeedback se não estiver online
	 */
	public function player(int $index) : Player {
		$name = $this->string($index);
		$server = $this->server ?? Server::getInstance();
		return $server->getPlayerExact($name) ?? throw new CommandFeedback("§cJogador não encontrado: $name");
	}

	/** null se o argumento não foi informado; se foi e não está online, lança {@see CommandFeedback}. */
	public function optPlayer(int $index) : ?Player {
		return $this->has($index) ? $this->player($index) : null;
	}

	/** Junta os argumentos do índice até o fim com espaços. Obrigatório. */
	public function rest(int $index) : string {
		if(!$this->has($index)){
			throw new UsageException("Argumento $index ausente");
		}
		return implode(" ", array_slice($this->args, $index));
	}

	public function optRest(int $index, string $default = "") : string {
		return $this->has($index) ? $this->rest($index) : $default;
	}

	/**
	 * Uma das opções (sem diferenciar maiúsculas). Devolve a opção como escrita na lista.
	 *
	 * @param list<string> $choices
	 */
	public function choice(int $index, array $choices) : string {
		$raw = $this->string($index);
		$lower = mb_strtolower($raw, "UTF-8");
		foreach($choices as $choice){
			if(mb_strtolower($choice, "UTF-8") === $lower){
				return $choice;
			}
		}
		throw new CommandFeedback("§cOpção inválida: §f{$raw}§c. Use: " . implode(", ", $choices));
	}

	private static function checkRange(int|float $value, int|float|null $min, int|float|null $max) : void {
		if($min !== null && $max !== null && ($value < $min || $value > $max)){
			throw new CommandFeedback("§cO valor precisa estar entre $min e $max.");
		}
		if($min !== null && $value < $min){
			throw new CommandFeedback("§cO valor precisa ser no mínimo $min.");
		}
		if($max !== null && $value > $max){
			throw new CommandFeedback("§cO valor precisa ser no máximo $max.");
		}
	}
}
