<?php

declare(strict_types=1);

namespace Unknown\Forja\command;

use pocketmine\command\CommandSender;
use pocketmine\player\Player;
use function array_map;
use function array_values;
use function explode;
use function strtolower;

/**
 * Um subcomando de um {@see BaseCommand}.
 *
 * ```php
 * final class AbrirSub extends SubCommand {
 *     public function __construct() {
 *         parent::__construct("abrir", "Abre uma loja", "<id> [jogador]", ["open"], "meuplugin.use");
 *     }
 *     public function run(CommandSender $sender, Args $args) : void {
 *         $id = $args->string(0);
 *     }
 * }
 * ```
 */
abstract class SubCommand {

	private string $name;
	/** @var list<string> */
	private array $aliases;

	/**
	 * @param list<string> $aliases
	 * @param string|null $permission null = quem pode usar o comando principal pode usar este;
	 *                                várias separadas por ";" = basta ter uma
	 * @param bool $playerOnly true = o console não pode usar
	 */
	public function __construct(
		string $name,
		private string $description = "",
		private string $usage = "",
		array $aliases = [],
		private ?string $permission = null,
		private bool $playerOnly = false
	) {
		$this->name = strtolower($name);
		$this->aliases = array_values(array_map(strtolower(...), $aliases));
	}

	/**
	 * Executa o subcomando. Lance {@see UsageException} para mostrar o uso e
	 * {@see CommandFeedback} para responder com uma mensagem.
	 */
	abstract public function run(CommandSender $sender, Args $args) : void;

	public function getName() : string {
		return $this->name;
	}

	/**
	 * @return list<string>
	 */
	public function getAliases() : array {
		return $this->aliases;
	}

	public function getDescription() : string {
		return $this->description;
	}

	public function getUsage() : string {
		return $this->usage;
	}

	public function getPermission() : ?string {
		return $this->permission;
	}

	public function isPlayerOnly() : bool {
		return $this->playerOnly;
	}

	/** True se o autor tem a permissão deste subcomando (não considera playerOnly). */
	public function hasPermission(CommandSender $sender) : bool {
		if($this->permission === null || $this->permission === ""){
			return true;
		}
		foreach(explode(";", $this->permission) as $permission){
			if($sender->hasPermission($permission)){
				return true;
			}
		}
		return false;
	}

	/** True se o autor pode executar este subcomando agora (permissão e playerOnly). */
	public function canUse(CommandSender $sender) : bool {
		return $this->hasPermission($sender) && (!$this->playerOnly || $sender instanceof Player);
	}
}
