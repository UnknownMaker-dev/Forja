<?php

declare(strict_types=1);

namespace Unknown\Forja;

use LogicException;
use pocketmine\plugin\PluginBase;
use Unknown\Forja\region\SelectionListener;
use Unknown\Forja\region\SelectionManager;
use Unknown\Forja\text\HologramListener;
use Unknown\Forja\text\HologramManager;

/**
 * Ponto de entrada da biblioteca. Só mantém os serviços que precisam de estado
 * (seleções e hologramas) e os listeners deles; os demais módulos são estáticos.
 */
final class Forja extends PluginBase {

	private static ?self $instance = null;

	private ?SelectionManager $selections = null;
	private ?HologramManager $holograms = null;

	/**
	 * @throws LogicException se a Forja ainda não foi habilitada (falta `depend: [Forja]`?)
	 */
	public static function getInstance() : self {
		return self::$instance ?? throw new LogicException("A Forja não está habilitada. Declare 'depend: [Forja]' no plugin.yml do seu plugin.");
	}

	protected function onEnable() : void {
		self::$instance = $this;

		$this->selections = new SelectionManager($this->getServer());
		$this->holograms = new HologramManager($this, $this->getServer(), $this->getLogger());

		$pluginManager = $this->getServer()->getPluginManager();
		$pluginManager->registerEvents(new SelectionListener($this->selections), $this);
		$pluginManager->registerEvents(new HologramListener($this->holograms), $this);

		$this->holograms->start();
	}

	protected function onDisable() : void {
		$this->holograms?->shutdown();
		$this->holograms = null;
		$this->selections = null;
		if(self::$instance === $this){
			self::$instance = null;
		}
	}

	public function getSelections() : SelectionManager {
		return $this->selections ?? throw new LogicException("A Forja não está habilitada.");
	}

	public function getHolograms() : HologramManager {
		return $this->holograms ?? throw new LogicException("A Forja não está habilitada.");
	}
}
