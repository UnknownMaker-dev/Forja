<?php

declare(strict_types=1);

namespace Unknown\Forja\util;

use pocketmine\item\Item;
use pocketmine\item\LegacyStringToItemParser;
use pocketmine\item\StringToItemParser;
use Throwable;
use function trim;

/**
 * Ajudantes para itens.
 */
final class Items {

	private function __construct() {}

	/**
	 * Converte um identificador ("diamond", "minecraft:red_wool", "customitems:blocked",
	 * ou formato antigo "35:14") num item. Nunca lança: desconhecido ⇒ null.
	 */
	public static function parse(string $id) : ?Item {
		$id = trim($id);
		if($id === ""){
			return null;
		}
		try{
			$item = StringToItemParser::getInstance()->parse($id);
			if($item !== null){
				return $item;
			}
		}catch(Throwable){
			// tenta o formato antigo
		}
		try{
			return LegacyStringToItemParser::getInstance()->parse($id);
		}catch(Throwable){
			return null;
		}
	}

	/**
	 * Mesmo tipo e variante (lã vermelha ≠ lã azul), ignorando a quantidade.
	 * Com `$checkNbt`, a NBT (nome, encantamentos, lore...) também precisa ser igual.
	 */
	public static function matches(Item $have, Item $want, bool $checkNbt = false) : bool {
		return $have->getStateId() === $want->getStateId()
			&& (!$checkNbt || $have->getNamedTag()->equals($want->getNamedTag()));
	}
}
