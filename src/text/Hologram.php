<?php

declare(strict_types=1);

namespace Unknown\Forja\text;

use Closure;
use Logger;
use pocketmine\math\Vector3;
use pocketmine\network\mcpe\protocol\SetActorDataPacket;
use pocketmine\network\mcpe\protocol\types\entity\EntityMetadataProperties;
use pocketmine\network\mcpe\protocol\types\entity\PropertySyncData;
use pocketmine\network\mcpe\protocol\types\entity\StringMetadataProperty;
use pocketmine\player\Player;
use pocketmine\world\format\Chunk;
use pocketmine\world\Position;
use pocketmine\world\World;
use Throwable;
use function array_values;
use function count;
use function get_debug_type;
use function is_string;
use function max;
use function spl_object_id;

/**
 * Texto flutuante num ponto do mundo, com texto fixo ou calculado por jogador.
 * Crie pelo {@see HologramManager}; os métodos marcados @internal são chamados por ele.
 */
final class Hologram {

	public const DEFAULT_VIEW_DISTANCE = 64;

	private HologramParticle $particle;
	/** @var array<int, array{player: Player, text: string}> spl_object_id => espectador e o texto que ele vê */
	private array $viewers = [];
	private int $refreshTicks = 0;
	private int $ticksSinceRefresh = 0;
	private int $viewDistance = self::DEFAULT_VIEW_DISTANCE;
	private bool $textErrorLogged = false;

	/**
	 * @param Closure|string $text texto fixo ou `Closure(Player): string`
	 * @phpstan-param (Closure(Player): string)|string $text
	 */
	public function __construct(
		private string $id,
		private string $worldName,
		private Vector3 $position,
		private Closure|string $text,
		private Logger $logger
	) {
		$this->position = $position->asVector3();
		$this->particle = new HologramParticle("");
	}

	public function getId() : string {
		return $this->id;
	}

	/** Nome da pasta do mundo. */
	public function getWorldName() : string {
		return $this->worldName;
	}

	public function getPosition() : Vector3 {
		return $this->position->asVector3();
	}

	/**
	 * @phpstan-return (Closure(Player): string)|string
	 */
	public function getText() : Closure|string {
		return $this->text;
	}

	/**
	 * Troca o texto e atualiza quem já está vendo.
	 *
	 * @phpstan-param (Closure(Player): string)|string $text
	 */
	public function setText(Closure|string $text) : void {
		$this->text = $text;
		$this->textErrorLogged = false;
		$this->refresh();
	}

	public function getRefreshTicks() : int {
		return $this->refreshTicks;
	}

	/**
	 * Reavalia o texto a cada N ticks (20 = 1 segundo). 0 desliga.
	 * Só reenvia para quem tiver o texto alterado.
	 */
	public function setRefreshTicks(int $ticks) : void {
		$this->refreshTicks = max(0, $ticks);
		$this->ticksSinceRefresh = 0;
	}

	public function getViewDistance() : int {
		return $this->viewDistance;
	}

	/** Distância máxima (em blocos) para o jogador ver o holograma. */
	public function setViewDistance(int $blocks) : void {
		$this->viewDistance = max(1, $blocks);
	}

	/**
	 * @return list<Player>
	 */
	public function getViewers() : array {
		$players = [];
		foreach($this->viewers as $viewer){
			$players[] = $viewer["player"];
		}
		return $players;
	}

	public function isViewer(Player $player) : bool {
		return isset($this->viewers[spl_object_id($player)]);
	}

	/**
	 * Reenvia o texto aos espectadores atuais, só para quem ele mudou.
	 */
	public function refresh() : void {
		$entityId = $this->particle->getEntityId();
		foreach($this->viewers as $key => $viewer){
			$player = $viewer["player"];
			if(!$player->isConnected()){
				unset($this->viewers[$key]);
				continue;
			}
			$text = $this->textFor($player);
			if($text === $viewer["text"] || $entityId === null){
				continue;
			}
			$player->getNetworkSession()->sendDataPacket(SetActorDataPacket::create(
				$entityId,
				[EntityMetadataProperties::NAMETAG => new StringMetadataProperty($text)],
				new PropertySyncData([], []),
				0
			));
			$this->viewers[$key]["text"] = $text;
		}
	}

	/**
	 * @internal mostra para quem deve ver e esconde de quem não deve mais
	 */
	public function syncViewers(?World $world) : void {
		if($world === null || !$world->isLoaded()){
			$this->despawnFromAll();
			return;
		}

		$maxDistanceSquared = $this->viewDistance ** 2;
		$chunkX = $this->position->getFloorX() >> Chunk::COORD_BIT_SIZE;
		$chunkZ = $this->position->getFloorZ() >> Chunk::COORD_BIT_SIZE;

		$eligible = [];
		foreach($world->getPlayers() as $player){
			if(
				$player->isConnected()
				&& $player->getPosition()->distanceSquared($this->position) <= $maxDistanceSquared
				&& $player->hasReceivedChunk($chunkX, $chunkZ)
			){
				$eligible[spl_object_id($player)] = $player;
			}
		}

		foreach($this->viewers as $key => $viewer){
			if(!isset($eligible[$key])){
				$this->despawnFrom($viewer["player"]);
			}
		}
		foreach($eligible as $key => $player){
			if(!isset($this->viewers[$key])){
				$this->spawnTo($player);
			}
		}
	}

	/**
	 * @internal conta os ticks da atualização periódica
	 */
	public function tick(int $ticks) : void {
		if($this->refreshTicks <= 0 || count($this->viewers) === 0){
			return;
		}
		$this->ticksSinceRefresh += $ticks;
		if($this->ticksSinceRefresh >= $this->refreshTicks){
			$this->ticksSinceRefresh = 0;
			$this->refresh();
		}
	}

	/**
	 * @internal chamado antes de um teleporte: esconde se o destino estiver fora do alcance
	 */
	public function onViewerTeleport(Player $player, Position $to) : void {
		if(!$this->isViewer($player)){
			return;
		}
		$sameWorld = $to->isValid() && $to->getWorld()->getFolderName() === $this->worldName;
		if(!$sameWorld || $to->distanceSquared($this->position) > $this->viewDistance ** 2){
			$this->despawnFrom($player);
		}
	}

	/**
	 * @internal esquece o jogador sem enviar pacotes (ele saiu)
	 */
	public function forget(Player $player) : void {
		unset($this->viewers[spl_object_id($player)]);
	}

	/**
	 * @internal esconde de todos os espectadores
	 */
	public function despawnFromAll() : void {
		foreach(array_values($this->viewers) as $viewer){
			$this->despawnFrom($viewer["player"]);
		}
		$this->viewers = [];
	}

	private function spawnTo(Player $player) : void {
		$text = $this->textFor($player);
		$this->particle->setTitle($text);
		$this->particle->setInvisible(false);
		$this->sendParticle($player);
		$this->viewers[spl_object_id($player)] = ["player" => $player, "text" => $text];
	}

	private function despawnFrom(Player $player) : void {
		unset($this->viewers[spl_object_id($player)]);
		if($this->particle->getEntityId() === null || !$player->isConnected()){
			return;
		}
		$this->particle->setInvisible(true);
		$this->sendParticle($player);
		$this->particle->setInvisible(false);
	}

	private function sendParticle(Player $player) : void {
		$session = $player->getNetworkSession();
		foreach($this->particle->encode($this->position) as $packet){
			$session->sendDataPacket($packet);
		}
	}

	private function textFor(Player $player) : string {
		if(is_string($this->text)){
			return $this->text;
		}
		try{
			$text = ($this->text)($player);
			if(is_string($text)){
				return $text;
			}
			$this->logTextError("devolveu " . get_debug_type($text) . " em vez de texto");
		}catch(Throwable $e){
			$this->logTextError($e::class . ": " . $e->getMessage());
		}
		return "";
	}

	private function logTextError(string $reason) : void {
		if(!$this->textErrorLogged){
			$this->textErrorLogged = true;
			$this->logger->error("Holograma '{$this->id}': o texto $reason");
		}
	}
}
