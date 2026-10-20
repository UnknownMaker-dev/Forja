<?php

declare(strict_types=1);

namespace Unknown\Forja\text;

use pocketmine\event\entity\EntityTeleportEvent;
use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;
use pocketmine\event\world\WorldUnloadEvent;
use pocketmine\player\Player;

/**
 * @internal mantém os hologramas certos visíveis para cada jogador
 */
final class HologramListener implements Listener {

	public function __construct(
		private HologramManager $holograms
	) {}

	/**
	 * @priority MONITOR
	 */
	public function onQuit(PlayerQuitEvent $event) : void {
		$this->holograms->forgetPlayer($event->getPlayer());
	}

	/**
	 * @priority MONITOR
	 */
	public function onTeleport(EntityTeleportEvent $event) : void {
		$player = $event->getEntity();
		if($player instanceof Player){
			$this->holograms->onPlayerTeleport($player, $event->getTo());
		}
	}

	/**
	 * @priority MONITOR
	 */
	public function onWorldUnload(WorldUnloadEvent $event) : void {
		$this->holograms->onWorldUnload($event->getWorld()->getFolderName());
	}
}
