<?php

declare(strict_types=1);

namespace Unknown\Forja\region;

use pocketmine\math\Vector3;
use pocketmine\world\Position;
use function floor;
use function is_finite;
use function is_float;
use function is_int;
use function is_string;
use function max;
use function min;

/**
 * Caixa alinhada aos blocos, com coordenadas inclusivas, num mundo identificado pelo
 * nome da pasta. Os cantos são ordenados no construtor (min ≤ max em cada eixo).
 */
final class Cuboid {

	private int $minX;
	private int $minY;
	private int $minZ;
	private int $maxX;
	private int $maxY;
	private int $maxZ;

	public function __construct(
		private string $world,
		int $x1, int $y1, int $z1,
		int $x2, int $y2, int $z2
	) {
		$this->minX = min($x1, $x2);
		$this->minY = min($y1, $y2);
		$this->minZ = min($z1, $z2);
		$this->maxX = max($x1, $x2);
		$this->maxY = max($y1, $y2);
		$this->maxZ = max($z1, $z2);
	}

	/**
	 * Cuboide entre os blocos de duas posições. null se estiverem em mundos diferentes
	 * ou se algum mundo não estiver mais carregado.
	 */
	public static function fromPositions(Position $a, Position $b) : ?self {
		if(!$a->isValid() || !$b->isValid()){
			return null;
		}
		$world = $a->getWorld()->getFolderName();
		if($b->getWorld()->getFolderName() !== $world){
			return null;
		}
		return new self(
			$world,
			$a->getFloorX(), $a->getFloorY(), $a->getFloorZ(),
			$b->getFloorX(), $b->getFloorY(), $b->getFloorZ()
		);
	}

	/**
	 * True se o ponto está dentro (mesmo mundo; considera o bloco onde o ponto está,
	 * então x = -0.5 pertence ao bloco -1).
	 */
	public function contains(Position $pos) : bool {
		if(!$pos->isValid()){
			return false;
		}
		return $this->containsBlock(
			$pos->getWorld()->getFolderName(),
			(int) floor($pos->x),
			(int) floor($pos->y),
			(int) floor($pos->z)
		);
	}

	public function containsBlock(string $world, int $x, int $y, int $z) : bool {
		return $world === $this->world
			&& $x >= $this->minX && $x <= $this->maxX
			&& $y >= $this->minY && $y <= $this->maxY
			&& $z >= $this->minZ && $z <= $this->maxZ;
	}

	/** True se os dois cuboides estão no mesmo mundo e têm pelo menos um bloco em comum. */
	public function intersects(Cuboid $other) : bool {
		return $other->world === $this->world
			&& $this->minX <= $other->maxX && $this->maxX >= $other->minX
			&& $this->minY <= $other->maxY && $this->maxY >= $other->minY
			&& $this->minZ <= $other->maxZ && $this->maxZ >= $other->minZ;
	}

	/**
	 * Novo cuboide estendido na vertical até cobrir [$minY, $maxY] (nunca encolhe).
	 * Ex.: `$cuboid->expandVertically(World::Y_MIN, World::Y_MAX - 1)` para ir do fundo ao topo.
	 */
	public function expandVertically(int $minY, int $maxY) : self {
		return new self(
			$this->world,
			$this->minX, min($this->minY, $minY, $maxY), $this->minZ,
			$this->maxX, max($this->maxY, $minY, $maxY), $this->maxZ
		);
	}

	/** Quantidade de blocos. */
	public function volume() : int {
		return ($this->maxX - $this->minX + 1) * ($this->maxY - $this->minY + 1) * ($this->maxZ - $this->minZ + 1);
	}

	/** Centro geométrico (com os blocos das bordas inteiros dentro). */
	public function center() : Vector3 {
		return new Vector3(
			($this->minX + $this->maxX + 1) / 2.0,
			($this->minY + $this->maxY + 1) / 2.0,
			($this->minZ + $this->maxZ + 1) / 2.0
		);
	}

	public function getWorld() : string { return $this->world; }

	public function getMinX() : int { return $this->minX; }

	public function getMinY() : int { return $this->minY; }

	public function getMinZ() : int { return $this->minZ; }

	public function getMaxX() : int { return $this->maxX; }

	public function getMaxY() : int { return $this->maxY; }

	public function getMaxZ() : int { return $this->maxZ; }

	/**
	 * @return array{world: string, minX: int, minY: int, minZ: int, maxX: int, maxY: int, maxZ: int}
	 */
	public function toArray() : array {
		return [
			"world" => $this->world,
			"minX" => $this->minX,
			"minY" => $this->minY,
			"minZ" => $this->minZ,
			"maxX" => $this->maxX,
			"maxY" => $this->maxY,
			"maxZ" => $this->maxZ
		];
	}

	/**
	 * Inverso de {@see toArray()}. Mundo vazio, chave faltando ou coordenada que não é
	 * inteira devolve null.
	 *
	 * @param array<mixed> $data
	 */
	public static function fromArray(array $data) : ?self {
		$world = $data["world"] ?? null;
		if(!is_string($world) || $world === ""){
			return null;
		}

		$coords = [];
		foreach(["minX", "minY", "minZ", "maxX", "maxY", "maxZ"] as $key){
			$value = $data[$key] ?? null;
			if(is_float($value) && is_finite($value) && $value === floor($value) && $value >= -2_000_000_000 && $value <= 2_000_000_000){
				$value = (int) $value;
			}
			if(!is_int($value)){
				return null;
			}
			$coords[] = $value;
		}

		return new self($world, $coords[0], $coords[1], $coords[2], $coords[3], $coords[4], $coords[5]);
	}

	public function equals(Cuboid $other) : bool {
		return $this->toArray() === $other->toArray();
	}
}
