# Forja

Biblioteca para plugins do PocketMine-MP 5: formulários com validação estrita, comandos com
subcomandos, configs que nunca derrubam o servidor, JSON com gravação atômica, regiões,
ponte de economia configurável, hologramas por jogador e utilitários. Outros plugins a usam
declarando `depend: [Forja]`, sem embutir nada.

> 🆓 **Projeto open source e gratuito**, distribuído sob a [licença MIT](LICENSE). Você pode usar,
> estudar, modificar e redistribuir o código livremente, inclusive em servidores comerciais,
> desde que mantenha o aviso de licença.

## Recursos

| Módulo | Namespace | O que oferece |
|---|---|---|
| Formulários | `Unknown\Forja\form` | `MenuForm`, `CustomForm`, `ModalForm` e `FormResponse`. Toda resposta do cliente é validada (tipo, faixa, quantidade de campos) antes de chegar ao seu código. |
| Comandos | `Unknown\Forja\command` | `BaseCommand` + `SubCommand` + `Args`: subcomandos com aliases, permissões, ajuda automática e leitura de argumentos com mensagens prontas. |
| Dados | `Unknown\Forja\data` | `DataObjectTrait`: converte objetos de configuração em array e de volta, com validação. |
| Config | `Unknown\Forja\config` | `SafeConfig` e `ConfigView`: leitura tipada com faixas; YAML inválido ou valor errado gera aviso e usa o padrão. |
| Armazenamento | `Unknown\Forja\storage` | `JsonFile` (leitura segura, gravação atômica, síncrona ou assíncrona) e `JsonStore` (mapa chave ⇒ valor com autosave). |
| Regiões | `Unknown\Forja\region` | `Cuboid` (contém, intersecta, serializa) e `SelectionManager` (pos1/pos2 por jogador). |
| Economia | `Unknown\Forja\economy` | `EconomyBridge`: conecta a qualquer plugin de economia só pela config, sem nomes fixos no código. |
| Hologramas | `Unknown\Forja\text` | `HologramManager`: textos flutuantes fixos ou calculados por jogador, com atualização periódica. |
| Utilitários | `Unknown\Forja\util` | `Items`, `Inventories`, `Commands`, `Cooldowns`, `Duration` e `Text`. |

## Requisitos

- [PocketMine-MP](https://github.com/pmmp/PocketMine-MP) 5 (API `5.0.0`) ou forks compatíveis, como o Altay.
- PHP 8.1 ou mais recente (o binário que acompanha o servidor já serve).

## Instalação

### No servidor

1. Baixe o `.phar` da Forja (ou a pasta do código) e coloque em `plugins/`.
   Para usar a pasta do código diretamente é preciso ter o plugin
   [DevTools](https://github.com/pmmp/DevTools) instalado.
2. Reinicie o servidor. O console deve mostrar `Enabling Forja v<versão>`.

A Forja não tem comandos, permissões nem arquivos de configuração próprios.

### No seu plugin

Declare a dependência no `plugin.yml` para que a Forja carregue antes do seu plugin:

```yaml
name: MeuPlugin
main: MeuNome\MeuPlugin\Main
version: 1.0.0
api: 5.0.0
depend: [Forja]
```

Não copie as classes da Forja para dentro do seu plugin; use-as pelo namespace `Unknown\Forja\...`.

## Guia da API

Os exemplos abaixo assumem `use` das classes citadas e `$this` sendo o seu `PluginBase`.

### form — formulários

```php
use Unknown\Forja\form\{MenuForm, CustomForm, ModalForm, FormResponse};

$player->sendForm((new MenuForm("Loja", "Escolha uma opção"))
	->button("Comprar", fn(Player $p) => $this->abrirCompra($p), "textures/items/diamond") // imagem: caminho ou URL
	->button("Voltar")                                                                   // sem ação
	->onClose(fn(Player $p) => $p->sendMessage("Até mais!")));

$player->sendForm((new CustomForm("Editar loja"))
	->label("Preencha os campos")
	->input("nome", "Nome", placeholder: "ex.: Central")
	->input("preco", "Preço", default: "10")
	->toggle("ativa", "Ativa", true)
	->slider("qtd", "Quantidade", 1, 64, step: 1, default: 1)
	->dropdown("cor", "Cor", ["Vermelho", "Azul"])
	->stepSlider("nivel", "Nível", ["Fácil", "Normal", "Difícil"], default: 1)
	->onSubmit(function(Player $p, FormResponse $r) : void {
		$preco = $r->parseFloat("preco", min: 0.01);
		if($preco === null){
			$p->sendMessage("§cPreço inválido.");
			return;
		}
		$p->sendMessage("Loja {$r->string("nome")}: {$r->int("qtd")}x, cor {$r->option("cor")}");
	}));

$player->sendForm((new ModalForm("Confirmar", "Apagar a loja?", yes: "Sim", no: "Não"))
	->onChoice(function(Player $p, bool $sim) : void { /* ... */ }));
```

- Resposta inválida do cliente (tipo errado, índice fora da faixa, quantidade de campos errada,
  texto com mais de 1024 caracteres) lança `FormValidationException`, que o servidor trata sem crash.
- Fechar o form chama `onClose()` (também disponível no `ModalForm`).
- `FormResponse`: `string`, `bool`, `float`, `int` (slider arredondado), `index`, `option`,
  `parseInt(key, min, max)` e `parseFloat(key, min, max)` (devolvem `null` se o texto não for um
  número válido na faixa), `has` e `all`. Chave inexistente lança `InvalidArgumentException`.
- Os callbacks rodam depois que o jogador responde: busque de novo pelo id o que for editar
  (pode ter sido apagado nesse meio tempo) e confira a permissão de novo.

### command — comandos com subcomandos

```php
use Unknown\Forja\command\{Args, BaseCommand, CommandFeedback, SubCommand};

final class LojaCommand extends BaseCommand {
	public function __construct(Plugin $plugin) {
		parent::__construct($plugin, "loja", "Abre as lojas", ["shop"], "meuplugin.use");
		$this->registerSubCommand(new AbrirSub());
	}

	protected function onDefault(CommandSender $sender) : void {
		$sender->sendMessage("Use /loja ajuda");
	}
}

final class AbrirSub extends SubCommand {
	public function __construct() {
		parent::__construct("abrir", "Abre uma loja", "<id> [jogador] [qtd]", ["open"], "meuplugin.use", playerOnly: false);
	}

	public function run(CommandSender $sender, Args $args) : void {
		$id = $args->string(0);                         // ausente ⇒ "§cUso: /loja abrir <id> [jogador] [qtd]"
		$alvo = $args->optPlayer(1) ?? $sender;         // não encontrado ⇒ "§cJogador não encontrado: X"
		$qtd = $args->optInt(2, 1, min: 1, max: 64);    // inválido ou fora da faixa ⇒ mensagem pronta
		if($id === "fechada"){
			throw new CommandFeedback("§cEssa loja está fechada.");
		}
	}
}

// no onEnable (NUNCA declare o comando em "commands:" do plugin.yml):
$this->getServer()->getCommandMap()->register($this->getName(), new LojaCommand($this));
```

- Subcomandos e aliases não diferenciam maiúsculas. `/loja`, `/loja ajuda` e `/loja help`
  mostram só os subcomandos que quem digitou pode usar: `§e/loja abrir <id> [jogador] [qtd] §7- Abre uma loja`.
- Sem permissão: `§cVocê não tem permissão.` (uma vez). Subcomando `playerOnly` no console:
  `§cUse este comando no jogo.`
- `Args`: `count`, `has`, `all`, `string`/`optString`, `int`/`optInt`, `float`/`optFloat` (aceita vírgula),
  `bool`/`optBool` (sim/não, true/false, on/off, 1/0), `player`/`optPlayer`, `rest`/`optRest`, `choice`.
  Argumento obrigatório ausente lança `UsageException` (mostra o uso); valor inválido lança
  `CommandFeedback` com a explicação.
- Comandos sem subcomandos que recebem argumentos diretos (ex.: `/pagar <jogador> <valor>`)
  podem sobrescrever `onUnknownSubCommand(CommandSender $sender, Args $args)`.
- Permissão `null` no `BaseCommand` = qualquer jogador pode usar.

### data — objetos de configuração

```php
use Unknown\Forja\data\{DataObjectTrait, ValidationErrors};
use Unknown\Forja\data\attribute\{Key, Ignore};

final class LojaSettings {
	use DataObjectTrait;

	#[Key(name: "limite_max")]
	public int $limite = 10;
	public string $nome = "Loja";
	#[Ignore]
	public array $cache = [];

	protected static function setup() : void {
		self::registerDeserializer("limite_max", function(int $valor, ValidationErrors $erros) : int {
			if($valor <= 0) $erros->addError("limite_max precisa ser maior que 0");
			return $valor;
		});
	}
}

$settings = (new LojaSettings())->update($config->section("loja")->toArray()); // DataException se inválido
$array = $settings->toArray();
```

### config — leitura que nunca derruba

```php
use Unknown\Forja\config\SafeConfig;

$config = SafeConfig::load($this, "config.yml");          // salva o padrão de resources/ se faltar
$limite = $config->int("loja.limite", 10, min: 1, max: 64);
$taxa = $config->float("loja.taxa", 0.05, min: 0.0, max: 1.0);
$ativo = $config->bool("loja.ativa", true);                 // aceita true/false, sim/não, 0/1
$prefixo = $config->string("mensagens.prefixo", "§6[Loja]§r ");
$mundos = $config->stringList("loja.mundos");
foreach($config->section("lojas")->keys() as $id){ /* ... */ }
```

Valor do tipo errado ou fora da faixa registra um aviso como
`[config.yml] chave 'loja.limite': esperado int entre 1 e 64, recebido "abc"; usando 10` e usa o
padrão. YAML inválido registra o erro (arquivo e motivo), não altera o arquivo e todos os getters
devolvem o padrão. Nesse caso `$config->hasLoadError()` devolve `true`: se o plugin regrava esse
arquivo (por exemplo, a partir de um editor no jogo), não grave enquanto ele estiver quebrado, senão
os dados do arquivo seriam substituídos por uma view vazia.
`SafeConfig::fromFile($caminho, $logger)` e `ConfigView::fromArray($array, $logger, "nome")`
servem para outros arquivos.

### storage — JSON seguro

```php
use Unknown\Forja\storage\{JsonFile, JsonStore};

$dados = JsonFile::read($this->getDataFolder() . "lojas.json");   // ausente ⇒ []; corrompido ⇒ backup + []
JsonFile::write($this->getDataFolder() . "lojas.json", $dados);   // atômico, cria pastas
JsonFile::writeAsync($this->getDataFolder() . "lojas.json", $dados);

$saldos = new JsonStore($this->getDataFolder() . "saldos.json");
$saldos->startAutosave($this, 300);                               // a cada 5 min, só se houver mudanças
$chave = JsonStore::playerKey($player);                            // nome em minúsculas
$saldos->set($chave, $saldos->get($chave, 0) + 10);
// no onDisable:
$saldos->save();
```

Um JSON inválido nunca é apagado: ele é movido para `<arquivo>.corrompido-<data>` e o erro é
registrado. Gravações do mesmo arquivo nunca ficam fora de ordem (uma gravação síncrona espera as
assíncronas pendentes daquele arquivo).

### region — cuboides e seleção

```php
use Unknown\Forja\Forja;
use Unknown\Forja\region\Cuboid;

$selecoes = Forja::getInstance()->getSelections();
$selecoes->setPos1($player, $player->getPosition());
$selecoes->setPos2($player, $bloco->getPosition());
$area = $selecoes->getCuboid($player);                 // null se faltar posição ou mundos diferentes
if($area !== null){
	$area = $area->expandVertically(World::Y_MIN, World::Y_MAX - 1);
	$this->areas->set("spawn", $area->toArray());
}

$area = Cuboid::fromArray($this->areas->get("spawn", []));  // dados inválidos ⇒ null
if($area !== null && $area->contains($player->getPosition())){ /* ... */ }
```

`contains()` usa o bloco onde o ponto está (`x = -0.5` pertence ao bloco `-1`). As posições
guardam o mundo pelo nome, e a seleção de quem sai do servidor é apagada.

### economy — ponte para qualquer economia

```yaml
# config.yml do seu plugin
economy:
  class: ""           # nome completo da classe; vazio = sem economia
  instance: ""        # método estático que devolve a instância (ex.: getInstance); vazio = métodos estáticos
  plugin: ""          # opcional: só usa se este plugin estiver habilitado
  balance: getMoney   # (Player) -> int|float
  take: reduceMoney   # (Player, valor) -> mixed (false = falhou)
  give: addMoney      # opcional (Player, valor)
  format: ""          # opcional (valor) -> string
  currency: "moedas"  # sufixo do formato padrão
```

```php
use Unknown\Forja\economy\EconomyBridge;

$economia = EconomyBridge::fromConfig($config->section("economy"), $this->getServer(), $this->getLogger());
if(!$economia->isAvailable()){
	$player->sendMessage("§cEconomia indisponível.");
	return;
}
if($economia->take($player, 150)){                     // confere o saldo antes de debitar
	$player->sendMessage("Pago: " . $economia->format(150)); // "150 moedas"
}
```

Se o método da economia espera o nome do jogador (string) em vez do `Player`, o nome é enviado;
se o valor é tipado como `int`, só valores inteiros são aceitos. Qualquer exceção da economia é
registrada e tratada como falha, nunca chega ao seu plugin.

### text — hologramas

```php
use Unknown\Forja\Forja;

$hologramas = Forja::getInstance()->getHolograms();
$holo = $hologramas->create("meuplugin:ranking", $posicao, fn(Player $p) => "§eOlá, §f" . $p->getName());
$holo->setRefreshTicks(40);   // reavalia o texto a cada 2 segundos (só reenvia se mudou)
$holo->setViewDistance(48);   // padrão: 64 blocos

$hologramas->refresh("meuplugin:ranking"); // força a atualização agora
$hologramas->remove("meuplugin:ranking");  // some para todos que estavam vendo
```

Cada jogador vê o holograma quando está no mesmo mundo, perto o bastante e com o chunk carregado;
entrar, sair, trocar de mundo e teleportar são tratados. Use o nome do seu plugin como prefixo
dos ids para não colidir com outros plugins.

### util — utilitários

```php
use Unknown\Forja\util\{Commands, Cooldowns, Duration, Inventories, Items, Text};

$item = Items::parse("red_wool");                          // null se desconhecido; nunca lança
Items::matches($naMao, $item);                             // tipo + variante (lã vermelha ≠ azul)
Items::matches($naMao, $item, checkNbt: true);             // também nome, lore, encantamentos

$inv = $player->getInventory();
Inventories::count($inv, $item);
if(Inventories::removeExactly($inv, $item, 10)){ /* removeu exatamente 10, ou nada */ }
$dropados = Inventories::giveOrDrop($player, $premio1, $premio2); // o que não couber cai no chão

Commands::runAsConsole($this->getServer(), "give {player} diamond 5", ["player" => $player->getName()]);
Commands::runMany($this->getServer(), ["say {player} venceu!", "give {player} emerald 1"], ["player" => $player]);

$cooldowns = new Cooldowns();
if($cooldowns->isActive("kit:" . $chave)){
	$player->sendMessage("Aguarde " . Duration::format((int) ceil($cooldowns->remaining("kit:" . $chave))));
	return;
}
$cooldowns->set("kit:" . $chave, 60);

Duration::parse("1h30m");   // 5400 (aceita s, m/min, h, d, w; número sozinho = segundos; inválido = null)
Duration::format(93900);    // "1d 2h 5min"
Text::color("&aVerde &lnegrito");              // "§aVerde §lnegrito"
Text::progressBar(0.75, 10);                   // 7,5 ⇒ 8 barras verdes e 2 cinzas
```

`Commands` coloca entre aspas os valores com espaço, então um jogador chamado `Nami Swan`
vira um único argumento. Falhas são registradas e o método devolve `false`.

## Contribuindo

Issues e pull requests são bem-vindos. Ao contribuir, você concorda que sua contribuição será
licenciada sob a mesma licença MIT do projeto.

## Licença

Distribuído sob a licença MIT — veja [LICENSE](LICENSE).

## Aviso de responsabilidade

Este plugin é fornecido gratuitamente, **"no estado em que se encontra" (as is)**, sem garantia
de qualquer tipo, expressa ou implícita. Os autores e mantenedores **não se responsabilizam** por
perda de dados, mundos ou itens, quedas ou falhas do servidor, prejuízos financeiros, nem por
qualquer uso que terceiros façam deste código ou dos servidores em que ele for instalado. Faça
backup antes de instalar ou atualizar e teste em um servidor de testes antes de usar em
produção. Ao usar, modificar ou redistribuir este plugin, você assume total responsabilidade
por isso.

Este projeto não é afiliado, endossado ou patrocinado pela Mojang, Microsoft, PocketMine-MP,
nem pelos detentores dos direitos de obras ou marcas eventualmente citadas.
