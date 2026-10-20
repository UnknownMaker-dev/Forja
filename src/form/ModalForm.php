<?php

declare(strict_types=1);

namespace Unknown\Forja\form;

use Closure;
use pocketmine\form\Form;
use pocketmine\form\FormValidationException;
use pocketmine\player\Player;
use function get_debug_type;
use function is_bool;

/**
 * Pergunta de sim/não com dois botões.
 *
 * ```php
 * $player->sendForm((new ModalForm("Confirmar", "Tem certeza?", yes: "Sim", no: "Não"))
 *     ->onChoice(function(Player $p, bool $sim) : void { ... }));
 * ```
 */
final class ModalForm implements Form {

	/** @phpstan-var (Closure(Player, bool): mixed)|null */
	private ?Closure $choiceHandler = null;
	/** @phpstan-var (Closure(Player): mixed)|null */
	private ?Closure $closeHandler = null;

	public function __construct(
		private string $title,
		private string $content,
		private string $yes = "Sim",
		private string $no = "Não"
	) {}

	/**
	 * @param Closure(Player, bool): mixed $handler recebe true para o botão "sim" e false para o "não"
	 */
	public function onChoice(Closure $handler) : self {
		$this->choiceHandler = $handler;
		return $this;
	}

	/**
	 * Chamado quando o jogador fecha o form (ESC) sem escolher nenhum botão.
	 * Sem este handler, fechar não faz nada.
	 *
	 * @param Closure(Player): mixed $handler
	 */
	public function onClose(Closure $handler) : self {
		$this->closeHandler = $handler;
		return $this;
	}

	/**
	 * Valida a resposta crua do cliente sem executar nenhuma ação.
	 *
	 * @return bool|null a escolha, ou null se o form foi fechado
	 * @throws FormValidationException se a resposta não é bool
	 */
	public function parseResponse(mixed $data) : ?bool {
		if($data === null){
			return null;
		}
		if(!is_bool($data)){
			throw new FormValidationException("Resposta de confirmação inválida: esperado bool, recebido " . get_debug_type($data));
		}
		return $data;
	}

	public function handleResponse(Player $player, $data) : void {
		$choice = $this->parseResponse($data);
		if($choice === null){
			if($this->closeHandler !== null){
				($this->closeHandler)($player);
			}
			return;
		}

		if($this->choiceHandler !== null){
			($this->choiceHandler)($player, $choice);
		}
	}

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize() : array {
		return [
			"type" => "modal",
			"title" => $this->title,
			"content" => $this->content,
			"button1" => $this->yes,
			"button2" => $this->no
		];
	}
}
