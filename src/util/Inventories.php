<?php

declare(strict_types=1);

namespace Unknown\Forja\util;

use pocketmine\inventory\Inventory;
use pocketmine\item\Item;
use pocketmine\player\Player;
use function min;

/**
 * Contar, remover e entregar itens sem perder nada.
 */
final class Inventories {

	private function __construct() {}

	/** Quantos itens iguais a `$want` (ver {@see Items::matches()}) existem no inventário. */
	public static function count(Inventory $inv, Item $want, bool $checkNbt = false) : int {
		$total = 0;
		foreach($inv->getContents() as $item){
			if(Items::matches($item, $want, $checkNbt)){
				$total += $item->getCount();
			}
		}
		return $total;
	}

	/**
	 * Remove exatamente `$amount` itens iguais a `$want`. Tudo ou nada: se não houver
	 * o suficiente, nada é removido e devolve false. `$amount` <= 0 não remove nada e devolve true.
	 */
	public static function removeExactly(Inventory $inv, Item $want, int $amount, bool $checkNbt = false) : bool {
		if($amount <= 0){
			return true;
		}
		if(self::count($inv, $want, $checkNbt) < $amount){
			return false;
		}

		$remaining = $amount;
		foreach($inv->getContents() as $slot => $item){
			if($remaining <= 0){
				break;
			}
			if(!Items::matches($item, $want, $checkNbt)){
				continue;
			}
			$taken = min($item->getCount(), $remaining);
			$remaining -= $taken;
			if($taken >= $item->getCount()){
				$inv->clear($slot);
			}else{
				$inv->setItem($slot, $item->setCount($item->getCount() - $taken));
			}
		}
		return true;
	}

	/**
	 * Adiciona os itens ao inventário do jogador; o que não couber é dropado nos pés dele.
	 *
	 * @return int quantos itens (somando as quantidades) foram dropados
	 */
	public static function giveOrDrop(Player $player, Item ...$items) : int {
		$dropped = 0;
		$leftovers = $player->getInventory()->addItem(...$items);
		if($leftovers === []){
			return 0;
		}
		$position = $player->getPosition();
		$world = $position->getWorld();
		foreach($leftovers as $item){
			if($item->isNull()){
				continue;
			}
			$world->dropItem($position, $item);
			$dropped += $item->getCount();
		}
		return $dropped;
	}
}
