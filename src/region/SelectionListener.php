<?php

declare(strict_types=1);

namespace Unknown\Forja\region;

use pocketmine\event\Listener;
use pocketmine\event\player\PlayerQuitEvent;

/**
 * @internal limpa a seleção de quem sai do servidor
 */
final class SelectionListener implements Listener {

	public function __construct(
		private SelectionManager $selections
	) {}

	/**
	 * @priority MONITOR
	 */
	public function onQuit(PlayerQuitEvent $event) : void {
		$this->selections->clear($event->getPlayer());
	}
}
