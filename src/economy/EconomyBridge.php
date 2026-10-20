<?php

declare(strict_types=1);

namespace Unknown\Forja\economy;

use Logger;
use pocketmine\player\Player;
use pocketmine\Server;
use ReflectionClass;
use ReflectionIntersectionType;
use ReflectionMethod;
use ReflectionNamedType;
use ReflectionParameter;
use ReflectionUnionType;
use RuntimeException;
use Throwable;
use Unknown\Forja\config\ConfigView;
use function class_exists;
use function floor;
use function get_debug_type;
use function interface_exists;
use function is_a;
use function is_finite;
use function is_float;
use function is_int;
use function is_numeric;
use function is_object;
use function is_string;
use function number_format;
use function trim;

/**
 * Ponte para qualquer plugin de economia, configurada só pela config: nome da classe,
 * como obter a instância e os nomes dos métodos. Nenhuma exceção da economia externa
 * chega ao seu plugin: ela é registrada e a operação conta como falha.
 *
 * ```yaml
 * economy:
 *   class: ""           # nome completo da classe; vazio = sem economia
 *   instance: ""        # método estático que devolve a instância (ex.: getInstance); vazio = métodos estáticos
 *   plugin: ""          # só usa se este plugin estiver habilitado
 *   balance: getMoney   # (Player) -> int|float
 *   take: reduceMoney   # (Player, valor) -> mixed (false = falhou)
 *   give: addMoney      # (Player, valor) -> mixed (false = falhou)
 *   format: ""          # (valor) -> string
 *   currency: "moedas"  # sufixo do formato padrão
 * ```
 *
 * Se o método espera o nome do jogador (string) em vez do Player, o nome é enviado.
 * Se o valor é tipado como int, só valores inteiros são aceitos.
 */
final class EconomyBridge {

	/** @var array<string, true> mensagens já registradas (evita repetir a cada chamada) */
	private array $logged = [];

	public function __construct(
		private Server $server,
		private Logger $logger,
		private string $class,
		private string $instanceMethod = "",
		private string $plugin = "",
		private string $balanceMethod = "getMoney",
		private string $takeMethod = "reduceMoney",
		private string $giveMethod = "addMoney",
		private string $formatMethod = "",
		private string $currency = "moedas"
	) {
		$this->class = trim($this->class);
		$this->instanceMethod = trim($this->instanceMethod);
		$this->plugin = trim($this->plugin);
	}

	/**
	 * @param ConfigView $section a seção `economy` (ou a raiz que contém `economy`)
	 */
	public static function fromConfig(ConfigView $section, Server $server, Logger $logger) : self {
		if(!$section->has("class") && $section->has("economy")){
			$section = $section->section("economy");
		}
		return new self(
			$server,
			$logger,
			$section->string("class", ""),
			$section->string("instance", ""),
			$section->string("plugin", ""),
			trim($section->string("balance", "getMoney")),
			trim($section->string("take", "reduceMoney")),
			trim($section->string("give", "addMoney")),
			trim($section->string("format", "")),
			$section->string("currency", "moedas")
		);
	}

	/** True se a classe e os métodos de saldo e débito existem (e o plugin, se definido, está habilitado). */
	public function isAvailable() : bool {
		try{
			$target = $this->resolveTarget();
			return $target !== null
				&& $this->method($target, $this->balanceMethod, "balance") !== null
				&& $this->method($target, $this->takeMethod, "take") !== null;
		}catch(Throwable $e){
			$this->logFailure("verificar a economia", $e);
			return false;
		}
	}

	/** Saldo do jogador; 0 se a economia estiver indisponível ou falhar. */
	public function balance(Player $player) : float {
		try{
			return $this->readBalance($player) ?? 0.0;
		}catch(Throwable $e){
			$this->logFailure("consultar o saldo de {$player->getName()}", $e);
			return 0.0;
		}
	}

	/**
	 * Debita o valor se o jogador tiver saldo suficiente.
	 *
	 * @return bool false se o valor não é positivo, a economia está indisponível,
	 *              o saldo é insuficiente ou a economia falhou
	 */
	public function take(Player $player, float $amount) : bool {
		if(!is_finite($amount) || $amount <= 0){
			return false;
		}
		try{
			$target = $this->resolveTarget();
			$take = $target === null ? null : $this->method($target, $this->takeMethod, "take");
			if($take === null){
				return false;
			}
			$balance = $this->readBalance($player);
			if($balance === null || $balance < $amount){
				return false;
			}
			return $this->call($target, $take, $player, $amount) !== false;
		}catch(Throwable $e){
			$this->logFailure("debitar $amount de {$player->getName()}", $e);
			return false;
		}
	}

	/** Credita o valor. false se não positivo, sem método `give` ou se a economia falhar. */
	public function give(Player $player, float $amount) : bool {
		if(!is_finite($amount) || $amount <= 0){
			return false;
		}
		try{
			$target = $this->resolveTarget();
			$give = $target === null ? null : $this->method($target, $this->giveMethod, "give");
			if($give === null){
				return false;
			}
			return $this->call($target, $give, $player, $amount) !== false;
		}catch(Throwable $e){
			$this->logFailure("creditar $amount para {$player->getName()}", $e);
			return false;
		}
	}

	/**
	 * Formata um valor: usa o método `format` da economia se ele devolver um texto
	 * não vazio; senão "1.234,50 moedas" (sem casas decimais quando inteiro).
	 */
	public function format(float $amount) : string {
		if($this->formatMethod !== ""){
			try{
				$target = $this->resolveTarget();
				$format = $target === null ? null : $this->method($target, $this->formatMethod, "format");
				if($format !== null){
					$text = $this->call($target, $format, null, $amount);
					if(is_string($text) && $text !== ""){
						return $text;
					}
				}
			}catch(Throwable $e){
				$this->logFailure("formatar $amount", $e);
			}
		}

		$decimals = is_finite($amount) && $amount === floor($amount) ? 0 : 2;
		$text = number_format($amount, $decimals, ",", ".");
		return $this->currency !== "" ? "$text {$this->currency}" : $text;
	}

	public function getCurrency() : string {
		return $this->currency;
	}

	private function readBalance(Player $player) : ?float {
		$target = $this->resolveTarget();
		$balance = $target === null ? null : $this->method($target, $this->balanceMethod, "balance");
		if($balance === null){
			return null;
		}
		$value = $this->call($target, $balance, $player, null);
		if(is_int($value) || is_float($value) || (is_string($value) && is_numeric($value))){
			$number = (float) $value;
			return is_finite($number) ? $number : null;
		}
		$this->logOnce("O método de saldo '{$this->balanceMethod}' devolveu " . get_debug_type($value) . " em vez de número.");
		return null;
	}

	/**
	 * Classe (para métodos estáticos) ou instância onde os métodos serão chamados.
	 */
	private function resolveTarget() : object|string|null {
		if($this->class === ""){
			return null;
		}
		if($this->plugin !== ""){
			$plugin = $this->server->getPluginManager()->getPlugin($this->plugin);
			if($plugin === null || !$plugin->isEnabled()){
				$this->logOnce("O plugin de economia '{$this->plugin}' não está habilitado; economia indisponível.");
				return null;
			}
		}
		if(!class_exists($this->class)){
			$this->logOnce("A classe de economia '{$this->class}' não existe; economia indisponível.");
			return null;
		}
		if($this->instanceMethod === ""){
			return $this->class;
		}

		$getter = $this->method($this->class, $this->instanceMethod, "instance");
		if($getter === null){
			return null;
		}
		$instance = $getter->invoke(null);
		if(!is_object($instance)){
			$this->logOnce("'{$this->class}::{$this->instanceMethod}()' devolveu " . get_debug_type($instance) . " em vez de um objeto; economia indisponível.");
			return null;
		}
		return $instance;
	}

	/**
	 * Método público chamável no alvo (estático se o alvo é o nome da classe).
	 */
	private function method(object|string $target, string $name, string $configKey) : ?ReflectionMethod {
		if($name === ""){
			return null;
		}
		$class = is_string($target) ? $target : $target::class;
		if(!class_exists($class) || !(new ReflectionClass($class))->hasMethod($name)){
			$this->logOnce("Economia: o método '$class::$name' (chave '$configKey') não existe.");
			return null;
		}
		$method = new ReflectionMethod($class, $name);
		if(!$method->isPublic() || ((is_string($target) || $configKey === "instance") && !$method->isStatic())){
			$this->logOnce("Economia: o método '$class::$name' (chave '$configKey') precisa ser público" . (is_string($target) || $configKey === "instance" ? " e estático" : "") . ".");
			return null;
		}
		return $method;
	}

	/**
	 * Chama o método adaptando os argumentos aos tipos declarados por ele.
	 */
	private function call(object|string $target, ReflectionMethod $method, ?Player $player, ?float $amount) : mixed {
		$params = $method->getParameters();
		$args = [];
		$index = 0;
		if($player !== null){
			$param = $params[$index++] ?? null;
			$args[] = $param !== null && !self::accepts($param, Player::class) && self::accepts($param, "string")
				? $player->getName()
				: $player;
		}
		if($amount !== null){
			$param = $params[$index] ?? null;
			if($param === null || self::accepts($param, "float")){
				$args[] = $amount;
			}elseif(self::accepts($param, "int")){
				if($amount !== floor($amount)){
					throw new RuntimeException("o método '{$method->getName()}' só aceita valores inteiros e recebeu $amount");
				}
				$args[] = (int) $amount;
			}else{
				$args[] = $amount;
			}
		}
		return $method->invokeArgs(is_object($target) ? $target : null, $args);
	}

	/**
	 * True se o parâmetro aceita o tipo (sem tipo e mixed aceitam tudo).
	 */
	private static function accepts(ReflectionParameter $param, string $type) : bool {
		$declared = $param->getType();
		if($declared === null){
			return true;
		}
		$named = match(true){
			$declared instanceof ReflectionNamedType => [$declared],
			$declared instanceof ReflectionUnionType => $declared->getTypes(),
			$declared instanceof ReflectionIntersectionType => [],
			default => []
		};
		foreach($named as $candidate){
			if(!$candidate instanceof ReflectionNamedType){
				continue;
			}
			$name = $candidate->getName();
			if($name === "mixed" || $name === $type){
				return true;
			}
			if(!$candidate->isBuiltin() && (class_exists($type) || interface_exists($type)) && is_a($type, $name, true)){
				return true;
			}
			if($name === "object" && class_exists($type)){
				return true;
			}
		}
		return false;
	}

	private function logOnce(string $message) : void {
		if(!isset($this->logged[$message])){
			$this->logged[$message] = true;
			$this->logger->warning($message);
		}
	}

	private function logFailure(string $action, Throwable $e) : void {
		$this->logger->error("Economia: falha ao $action: " . $e::class . ": " . $e->getMessage());
	}
}
