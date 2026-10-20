<?php

declare(strict_types=1);

namespace Unknown\Forja\text;

use pocketmine\world\particle\FloatingTextParticle;

/**
 * @internal FloatingTextParticle que expõe o id da entidade, para atualizar o texto
 *           sem recriar a entidade no cliente
 */
final class HologramParticle extends FloatingTextParticle {

	public function getEntityId() : ?int {
		return $this->entityId;
	}
}
