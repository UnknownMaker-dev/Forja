<?php

declare(strict_types=1);

namespace Unknown\Forja\text;

use Closure;
use InvalidArgumentException;
use Logger;
use pocketmine\player\Player;
use pocketmine\plugin\Plugin;
use pocketmine\scheduler\ClosureTask;
use pocketmine\scheduler\TaskHandler;
use pocketmine\Server;
use pocketmine\world\Position;

/**
 * Hologramas (texto flutuante) enviados por jogador. Acesse por
 * `Forja::getInstance()->getHolograms()`.
 *
 * ```php
 * $holo = Forja::getInstance()->getHolograms()->create("loja:topo", $posicao,
 *     fn(Player $p) => "§eOlá, §f" . $p->getName());
 * $holo->setRefreshTicks(40); // reavalia o texto a cada 2 segundos
 * ```
 *
 * Cada holograma é mostrado a quem está no mesmo mundo, a até {@see Hologram::getViewDistance()}
 * blocos e com o chunk já carregado; entradas, saídas, trocas de mundo e teleportes são tratados.
 * Use ids com o nome do seu plugin como prefixo para não colidir com outros plugins.
 */
final class HologramManager {

	/** Intervalo (ticks) da checagem de quem deve ver cada holograma. */
	private const SYNC_INTERVAL = 10;

	/** @var array<string, Hologram> */
	private array $holograms = [];
	private ?TaskHandler $task = null;
	private int $tickCounter = 0;

	public function __construct(
		private Plugin $plugin,
		private Server $server,
		private Logger $logger
	) {}

	/**
	 * Cria (ou substitui, se o id já existir) um holograma.
	 *
	 * @param Closure|string $text texto fixo ou `Closure(Player): string` calculado por jogador
	 * @phpstan-param (Closure(Player): string)|string $text
	 * @throws InvalidArgumentException se a posição não tiver um mundo carregado
	 */
	public function create(string $id, Position $pos, Closure|string $text) : Hologram {
		if(!$pos->isValid()){
			throw new InvalidArgumentException("Holograma '$id': a posição precisa estar num mundo carregado");
		}
		$this->remove($id);

		$world = $pos->getWorld();
		$hologram = new Hologram($id, $world->getFolderName(), $pos->asVector3(), $text, $this->logger);
		$this->holograms[$id] = $hologram;
		$hologram->syncViewers($world);
		return $hologram;
	}

	public function get(string $id) : ?Hologram {
		return $this->holograms[$id] ?? null;
	}

	/** Remove e esconde de todos que estavam vendo. */
	public function remove(string $id) : void {
		$hologram = $this->holograms[$id] ?? null;
		if($hologram !== null){
			unset($this->holograms[$id]);
			$hologram->despawnFromAll();
		}
	}

	/**
	 * @return array<string, Hologram>
	 */
	public function all() : array {
		return $this->holograms;
	}

	/**
	 * Reenvia o texto atualizado aos espectadores atuais (só onde mudou).
	 *
	 * @param string|null $id null = todos os hologramas
	 */
	public function refresh(?string $id = null) : void {
		if($id !== null){
			if(isset($this->holograms[$id])){
				$this->holograms[$id]->refresh();
			}
			return;
		}
		foreach($this->holograms as $hologram){
			$hologram->refresh();
		}
	}

	/**
	 * @internal iniciado pela Forja ao habilitar
	 */
	public function start() : void {
		$this->task ??= $this->plugin->getScheduler()->scheduleRepeatingTask(new ClosureTask(fn() => $this->tick()), 1);
	}

	/**
	 * @internal esconde tudo e para a tarefa (Forja desabilitando)
	 */
	public function shutdown() : void {
		$this->task?->cancel();
		$this->task = null;
		foreach($this->holograms as $hologram){
			$hologram->despawnFromAll();
		}
		$this->holograms = [];
	}

	/**
	 * @internal
	 */
	public function forgetPlayer(Player $player) : void {
		foreach($this->holograms as $hologram){
			$hologram->forget($player);
		}
	}

	/**
	 * @internal
	 */
	public function onPlayerTeleport(Player $player, Position $to) : void {
		foreach($this->holograms as $hologram){
			$hologram->onViewerTeleport($player, $to);
		}
	}

	/**
	 * @internal o holograma continua registrado e volta quando o mundo carregar de novo
	 */
	public function onWorldUnload(string $worldName) : void {
		foreach($this->holograms as $hologram){
			if($hologram->getWorldName() === $worldName){
				$hologram->despawnFromAll();
			}
		}
	}

	private function tick() : void {
		$sync = ++$this->tickCounter % self::SYNC_INTERVAL === 0;
		$worldManager = $this->server->getWorldManager();
		foreach($this->holograms as $hologram){
			if($sync){
				$hologram->syncViewers($worldManager->getWorldByName($hologram->getWorldName()));
			}
			$hologram->tick(1);
		}
	}
}
