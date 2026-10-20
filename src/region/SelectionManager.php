<?php

declare(strict_types=1);

namespace Unknown\Forja\region;

use pocketmine\math\Vector3;
use pocketmine\player\Player;
use pocketmine\Server;
use pocketmine\world\Position;
use function strtolower;

/**
 * Duas posições por jogador para montar um {@see Cuboid}. Acesse por
 * `Forja::getInstance()->getSelections()`. As posições guardam o mundo pelo nome,
 * então continuam válidas se o mundo for descarregado e carregado de novo.
 * A seleção é apagada quando o jogador sai.
 */
final class SelectionManager {

	/** @var array<string, array{0: ?array{string, Vector3}, 1: ?array{string, Vector3}}> */
	private array $selections = [];

	public function __construct(
		private Server $server
	) {}

	public function setPos1(Player $player, Position $pos) : void {
		$this->setPos($player, 0, $pos);
	}

	public function setPos2(Player $player, Position $pos) : void {
		$this->setPos($player, 1, $pos);
	}

	/** null se não marcada ou se o mundo dela não estiver carregado. */
	public function getPos1(Player $player) : ?Position {
		return $this->getPos($player, 0);
	}

	public function getPos2(Player $player) : ?Position {
		return $this->getPos($player, 1);
	}

	/** null se faltar alguma posição ou se estiverem em mundos diferentes. */
	public function getCuboid(Player $player) : ?Cuboid {
		$selection = $this->selections[self::key($player)] ?? null;
		if($selection === null || $selection[0] === null || $selection[1] === null){
			return null;
		}
		[$world1, $a] = $selection[0];
		[$world2, $b] = $selection[1];
		if($world1 !== $world2){
			return null;
		}
		return new Cuboid(
			$world1,
			$a->getFloorX(), $a->getFloorY(), $a->getFloorZ(),
			$b->getFloorX(), $b->getFloorY(), $b->getFloorZ()
		);
	}

	public function clear(Player $player) : void {
		unset($this->selections[self::key($player)]);
	}

	/**
	 * @param 0|1 $index
	 */
	private function setPos(Player $player, int $index, Position $pos) : void {
		if(!$pos->isValid()){
			return;
		}
		$key = self::key($player);
		$this->selections[$key] ??= [null, null];
		$this->selections[$key][$index] = [
			$pos->getWorld()->getFolderName(),
			new Vector3($pos->getFloorX(), $pos->getFloorY(), $pos->getFloorZ())
		];
	}

	/**
	 * @param 0|1 $index
	 */
	private function getPos(Player $player, int $index) : ?Position {
		$entry = $this->selections[self::key($player)][$index] ?? null;
		if($entry === null){
			return null;
		}
		$world = $this->server->getWorldManager()->getWorldByName($entry[0]);
		return $world === null ? null : Position::fromObject($entry[1], $world);
	}

	private static function key(Player $player) : string {
		return strtolower($player->getName());
	}
}
