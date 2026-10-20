<?php

declare(strict_types=1);

namespace Unknown\Forja\command;

use Closure;
use InvalidArgumentException;
use pocketmine\command\Command;
use pocketmine\command\CommandSender;
use pocketmine\permission\DefaultPermissionNames;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\plugin\PluginOwned;
use function array_slice;
use function array_values;
use function count;
use function strtolower;
use function trim;

/**
 * Comando com subcomandos. Registre com
 * `$server->getCommandMap()->register($plugin->getName(), $comando)` e NÃO declare o
 * comando em `commands:` do plugin.yml.
 *
 * ```php
 * final class LojaCommand extends BaseCommand {
 *     public function __construct(Plugin $plugin) {
 *         parent::__construct($plugin, "loja", "Abre as lojas", ["shop"], "meuplugin.use");
 *         $this->registerSubCommand(new AbrirSub($plugin));
 *     }
 *     protected function onDefault(CommandSender $sender) : void {
 *         // /loja sem argumentos
 *     }
 * }
 * ```
 */
abstract class BaseCommand extends Command implements PluginOwned {

	public const MESSAGE_NO_PERMISSION = "§cVocê não tem permissão.";
	public const MESSAGE_PLAYER_ONLY = "§cUse este comando no jogo.";
	public const MESSAGE_UNAVAILABLE = "§cEste comando está indisponível no momento.";

	/** @var array<string, SubCommand> nome => subcomando, na ordem de registro */
	private array $subCommands = [];
	/** @var array<string, SubCommand> nome ou alias em minúsculas => subcomando */
	private array $lookup = [];

	/** Rótulo usado na execução atual (o nome ou o alias digitado). */
	private string $currentLabel;

	/**
	 * @param list<string> $aliases
	 * @param string|null $permission permissão do comando (várias separadas por ";");
	 *                                null = qualquer jogador pode usar
	 */
	public function __construct(
		private Plugin $plugin,
		string $name,
		string $description = "",
		array $aliases = [],
		?string $permission = null
	) {
		parent::__construct($name, $description, "/" . $name, $aliases);
		$this->setPermission($permission ?? DefaultPermissionNames::GROUP_USER);
		$this->setPermissionMessage(self::MESSAGE_NO_PERMISSION);
		$this->currentLabel = $name;
	}

	public function getOwningPlugin() : Plugin {
		return $this->plugin;
	}

	/**
	 * @throws InvalidArgumentException se o nome ou um alias já estiver em uso
	 */
	public function registerSubCommand(SubCommand $subCommand) : void {
		$names = [$subCommand->getName(), ...$subCommand->getAliases()];
		foreach($names as $name){
			if(isset($this->lookup[$name])){
				throw new InvalidArgumentException("O subcomando '$name' já está registrado em /{$this->getName()}");
			}
		}
		foreach($names as $name){
			$this->lookup[$name] = $subCommand;
		}
		$this->subCommands[$subCommand->getName()] = $subCommand;
	}

	/** Procura pelo nome ou alias, sem diferenciar maiúsculas. */
	public function getSubCommand(string $nameOrAlias) : ?SubCommand {
		return $this->lookup[strtolower($nameOrAlias)] ?? null;
	}

	/**
	 * @return list<SubCommand>
	 */
	public function getSubCommands() : array {
		return array_values($this->subCommands);
	}

	/**
	 * @param string[] $args
	 */
	public function execute(CommandSender $sender, string $commandLabel, array $args) : void {
		if(!$this->plugin->isEnabled()){
			$sender->sendMessage(self::MESSAGE_UNAVAILABLE);
			return;
		}
		// O CommandMap já testa a permissão (e envia a mensagem); isto cobre chamadas diretas.
		if(!$this->testPermissionSilent($sender)){
			$sender->sendMessage(self::MESSAGE_NO_PERMISSION);
			return;
		}

		$this->currentLabel = $commandLabel !== "" ? $commandLabel : $this->getName();
		$args = array_values($args);

		if(count($args) === 0){
			$this->guarded($sender, fn() => $this->onDefault($sender));
			return;
		}

		$subCommand = $this->getSubCommand($args[0]);
		if($subCommand === null){
			$first = strtolower($args[0]);
			if($first === "ajuda" || $first === "help"){
				$this->sendHelp($sender);
				return;
			}
			$this->guarded($sender, fn() => $this->onUnknownSubCommand($sender, new Args($args)));
			return;
		}

		if(!$subCommand->hasPermission($sender)){
			$sender->sendMessage(self::MESSAGE_NO_PERMISSION);
			return;
		}
		if($subCommand->isPlayerOnly() && !$sender instanceof Player){
			$sender->sendMessage(self::MESSAGE_PLAYER_ONLY);
			return;
		}

		try{
			$subCommand->run($sender, new Args(array_slice($args, 1)));
		}catch(UsageException){
			$sender->sendMessage("§cUso: " . $this->formatUsage($subCommand));
		}catch(CommandFeedback $feedback){
			$sender->sendMessage($feedback->getMessage());
		}
	}

	/**
	 * Executado quando o comando é usado sem argumentos. Padrão: mostra a ajuda.
	 */
	protected function onDefault(CommandSender $sender) : void {
		$this->sendHelp($sender);
	}

	/**
	 * Executado quando o primeiro argumento não é um subcomando conhecido.
	 * Padrão: avisa e mostra a ajuda. Sobrescreva para comandos que aceitam
	 * argumentos diretos (ex.: `/pagar <jogador> <valor>`); `$args` inclui o primeiro.
	 */
	protected function onUnknownSubCommand(CommandSender $sender, Args $args) : void {
		$sender->sendMessage("§cSubcomando desconhecido: §f" . $args->string(0));
		$this->sendHelp($sender);
	}

	/**
	 * Lista os subcomandos que o autor pode usar.
	 */
	public function sendHelp(CommandSender $sender, ?string $label = null) : void {
		$label ??= $this->currentLabel;
		$lines = [];
		foreach($this->subCommands as $subCommand){
			if(!$subCommand->canUse($sender)){
				continue;
			}
			$line = "§e" . $this->formatUsage($subCommand, $label);
			if($subCommand->getDescription() !== ""){
				$line .= " §7- " . $subCommand->getDescription();
			}
			$lines[] = $line;
		}

		if(count($lines) === 0){
			$sender->sendMessage("§cNenhum subcomando de /$label disponível para você.");
			return;
		}
		$sender->sendMessage("§6Comandos de §f/{$label}§6:");
		foreach($lines as $line){
			$sender->sendMessage($line);
		}
	}

	/** Rótulo usado na execução atual (nome ou alias digitado pelo jogador). */
	protected function getCurrentLabel() : string {
		return $this->currentLabel;
	}

	private function formatUsage(SubCommand $subCommand, ?string $label = null) : string {
		return trim("/" . ($label ?? $this->currentLabel) . " " . $subCommand->getName() . " " . $subCommand->getUsage());
	}

	/**
	 * Roda um hook do próprio comando tratando as exceções de feedback.
	 *
	 * @param Closure() : void $hook
	 */
	private function guarded(CommandSender $sender, Closure $hook) : void {
		try{
			$hook();
		}catch(UsageException){
			$this->sendHelp($sender);
		}catch(CommandFeedback $feedback){
			$sender->sendMessage($feedback->getMessage());
		}
	}
}
