<?php

declare(strict_types=1);

namespace Unknown\Forja\util;

use Logger;
use pocketmine\console\ConsoleCommandSender;
use pocketmine\player\Player;
use pocketmine\Server;
use Stringable;
use Throwable;
use function addcslashes;
use function is_bool;
use function ltrim;
use function preg_match;
use function strtr;
use function trim;

/**
 * Executa comandos de recompensa pelo console, com placeholders seguros.
 *
 * ```php
 * Commands::runAsConsole($server, "give {player} diamond 5", ["player" => $player->getName()]);
 * ```
 *
 * Valores com espaço, aspas ou barra invertida são colocados entre aspas (escapadas),
 * então um nome como `Nami Swan` vira um único argumento: `give "Nami Swan" diamond 5`.
 */
final class Commands {

	private function __construct() {}

	/**
	 * @param array<string, string|int|float|bool|Stringable|Player> $placeholders chave sem chaves: ["player" => "Zoro"]
	 * @return bool true se o comando existia e foi executado
	 */
	public static function runAsConsole(Server $server, string $template, array $placeholders = [], ?Logger $logger = null) : bool {
		$command = "";
		try{
			$command = self::build($template, $placeholders);
			if($command === ""){
				return false;
			}
			$sender = new ConsoleCommandSender($server, $server->getLanguage());
			if(!$server->dispatchCommand($sender, $command)){
				($logger ?? $server->getLogger())->warning("Comando não encontrado ou cancelado: /$command");
				return false;
			}
			return true;
		}catch(Throwable $e){
			($logger ?? $server->getLogger())->error("Falha ao executar '/$command': " . $e::class . ": " . $e->getMessage());
			return false;
		}
	}

	/**
	 * Executa vários comandos (um que falha não impede os outros).
	 *
	 * @param list<string> $templates
	 * @param array<string, string|int|float|bool|Stringable|Player> $placeholders
	 * @return int quantos foram executados com sucesso
	 */
	public static function runMany(Server $server, array $templates, array $placeholders = [], ?Logger $logger = null) : int {
		$ok = 0;
		foreach($templates as $template){
			if(self::runAsConsole($server, $template, $placeholders, $logger)){
				$ok++;
			}
		}
		return $ok;
	}

	/**
	 * Substitui os placeholders `{chave}` (valores entre aspas quando preciso) e tira a "/" inicial.
	 *
	 * @param array<string, string|int|float|bool|Stringable|Player> $placeholders
	 */
	public static function build(string $template, array $placeholders = []) : string {
		$replacements = [];
		foreach($placeholders as $key => $value){
			$text = match(true){
				$value instanceof Player => $value->getName(),
				is_bool($value) => $value ? "true" : "false",
				default => (string) $value
			};
			$replacements["{" . $key . "}"] = self::quote($text);
		}
		return trim(ltrim(trim(strtr($template, $replacements)), "/"));
	}

	/** Coloca entre aspas (escapando aspas e barras) se o valor tiver espaço, aspas ou barra invertida. */
	public static function quote(string $value) : string {
		if(preg_match('/[\s"\\\\]/u', $value) !== 1){
			return $value;
		}
		return '"' . addcslashes($value, '"\\') . '"';
	}
}
