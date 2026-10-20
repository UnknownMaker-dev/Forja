<?php

declare(strict_types=1);

namespace Unknown\Forja\form;

use Closure;
use pocketmine\form\Form;
use pocketmine\form\FormValidationException;
use pocketmine\player\Player;
use function count;
use function get_debug_type;
use function is_int;
use function str_starts_with;
use function strtolower;

/**
 * Lista de botões. Cada botão pode ter uma ação própria e uma imagem
 * (caminho do resource pack, ex.: "textures/items/diamond", ou URL http/https).
 *
 * ```php
 * $player->sendForm((new MenuForm("Loja", "Escolha uma opção"))
 *     ->button("Comprar", fn(Player $p) => $this->comprar($p), "textures/items/diamond")
 *     ->button("Voltar")
 *     ->onClose(fn(Player $p) => $p->sendMessage("Até mais!")));
 * ```
 */
final class MenuForm implements Form {

	/** @var list<array{text: string, image: ?array{type: string, data: string}, action: ?Closure}> */
	private array $buttons = [];
	/** @phpstan-var (Closure(Player): mixed)|null */
	private ?Closure $closeHandler = null;

	public function __construct(
		private string $title,
		private string $content = ""
	) {}

	/**
	 * @param (Closure(Player): mixed)|null $action executada quando o botão é escolhido
	 * @param string|null $image caminho de textura ou URL (http/https); null = sem imagem
	 */
	public function button(string $text, ?Closure $action = null, ?string $image = null) : self {
		$imageData = null;
		if($image !== null && $image !== ""){
			$lower = strtolower($image);
			$isUrl = str_starts_with($lower, "http://") || str_starts_with($lower, "https://");
			$imageData = ["type" => $isUrl ? "url" : "path", "data" => $image];
		}
		$this->buttons[] = ["text" => $text, "image" => $imageData, "action" => $action];
		return $this;
	}

	/**
	 * @param Closure(Player): mixed $handler chamado quando o jogador fecha o form sem escolher
	 */
	public function onClose(Closure $handler) : self {
		$this->closeHandler = $handler;
		return $this;
	}

	public function getButtonCount() : int {
		return count($this->buttons);
	}

	/**
	 * Valida a resposta crua do cliente sem executar nenhuma ação.
	 *
	 * @return int|null índice do botão escolhido, ou null se o form foi fechado
	 * @throws FormValidationException se a resposta não é um índice válido
	 */
	public function parseResponse(mixed $data) : ?int {
		if($data === null){
			return null;
		}
		if(!is_int($data)){
			throw new FormValidationException("Resposta de menu inválida: esperado int, recebido " . get_debug_type($data));
		}
		if($data < 0 || $data >= count($this->buttons)){
			throw new FormValidationException("Botão inexistente: $data (o menu tem " . count($this->buttons) . " botões)");
		}
		return $data;
	}

	public function handleResponse(Player $player, $data) : void {
		$index = $this->parseResponse($data);
		if($index === null){
			if($this->closeHandler !== null){
				($this->closeHandler)($player);
			}
			return;
		}

		$action = $this->buttons[$index]["action"];
		if($action !== null){
			$action($player);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize() : array {
		$buttons = [];
		foreach($this->buttons as $button){
			$entry = ["text" => $button["text"]];
			if($button["image"] !== null){
				$entry["image"] = $button["image"];
			}
			$buttons[] = $entry;
		}

		return [
			"type" => "form",
			"title" => $this->title,
			"content" => $this->content,
			"buttons" => $buttons
		];
	}
}
