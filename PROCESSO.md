# Do zero até produção: como este sistema foi construído

Este documento explica, passo a passo, todas as decisões e etapas que levaram da leitura do case até o repositório publicado. O objetivo é você conseguir explicar em uma entrevista **por que** cada peça existe, não só **o que** ela faz.

---

## Etapa 0 — Entender o problema antes de escrever código

O case pedia um sistema de votação em Drupal com:
- Entidades próprias (proibido usar `node`)
- Uma pergunta com várias opções de resposta
- Um voto por usuário por pergunta
- Configuração por pergunta: mostrar ou não os resultados
- Um "kill switch" global: desabilitar toda a votação
- Uma API REST externa **implementada manualmente** (proibido JSON:API/Serializer)
- Ambiente reprodutível via Lando, dump de banco, collection Postman, documentação mínima

Antes de tocar em código, três decisões de arquitetura precisavam ser fechadas, porque mudam tudo depois:

1. **Versão do Drupal** → Drupal 10 (LTS, ampla documentação, compatível com módulos usados).
2. **Como identificar quem vota** → esse é o ponto mais importante do case. Existem duas superfícies de voto (CMS e API externa), e cada uma tem uma noção diferente de "usuário":
   - No CMS: usuário Drupal autenticado, identificado pelo `uid`.
   - Na API externa: a aplicação que chama a API se autentica com uma **API key própria** (identifica a aplicação, não a pessoa). Quem realmente vota é identificado por um `external_user_id` que a aplicação envia no corpo da requisição.
   - **Consequência de design:** para as duas superfícies compartilharem a mesma regra de "um voto por pessoa por pergunta", foi criado um conceito de **voter_key** normalizado: `user:<uid>` ou `external:<external_user_id>`. Isso permite ter uma única regra de unicidade no banco, independente de o voto vir do CMS ou da API.
3. **Onde montar o ambiente** → do zero, numa pasta nova, via Lando + Composer.

Essas três respostas condicionam a modelagem de dados inteira. Se você mudar a resposta 2, muda a entidade `VotingVote`, o serviço de negócio e os dois controllers.

---

## Etapa 1 — Montar o ambiente de desenvolvimento

Ordem de instalação (o ambiente não tinha nada disso previamente):

1. **Docker Engine** — motor de containers, pré-requisito de tudo.
2. **Docker Compose** — orquestra múltiplos containers (viria com o Docker, mas foi confirmado separadamente).
3. **Lando** — camada de conveniência sobre Docker feita para desenvolvimento local Drupal/PHP. Ele sobe automaticamente containers de PHP, banco de dados e servidor web a partir de uma "recipe".
4. **Git** — controle de versão (já esperado em qualquer máquina de dev).

Comando de inicialização:
```bash
lando init --source cwd --recipe drupal10 --webroot web --name simple-voting-drupal
```
Isso gera o `.lando.yml`, que descreve a receita do ambiente (versão do PHP, versão do banco, versão do Drush). Foi ajustado manualmente para **PHP 8.3** e **MySQL 8.0** (mais alinhado com o que se usa em produção Drupal 10 hoje).

```bash
lando start
```
Sobe os containers (appserver + database), gera URLs locais automáticas.

**Por que Lando e não só Docker puro?** Porque ele já sabe o que um projeto Drupal precisa (variáveis de ambiente, drush, composer, proxy), evitando escrever um `docker-compose.yml` do zero e reinventar convenções que a comunidade Drupal já padronizou.

---

## Etapa 2 — Instalar o Drupal em si

```bash
lando composer create-project drupal/recommended-project:^10 tmp_drupal
```
Isso baixa o Drupal core + estrutura recomendada (pasta `web/`, `composer.json`, autoload) via Composer — não é um "zip do Drupal", é o fluxo moderno oficial, onde todo o core e as dependências são gerenciados como pacotes PHP.

Depois os arquivos foram movidos para a raiz do projeto (o `lando init` já esperava a pasta `web/` como webroot).

```bash
lando composer require drush/drush:^12
lando drush site:install standard --account-name=admin --account-pass=admin -y
```
- `drush` é a ferramenta de linha de comando do Drupal (instala, roda queries, limpa cache, executa código PHP arbitrário no contexto do site).
- `site:install standard` roda o instalador do Drupal automaticamente (sem passar pelo wizard web), usando o perfil `standard` (o mesmo conjunto de módulos base que você teria clicando em "Instalar" no navegador).

Neste ponto: Drupal funcionando, site respondendo HTTP 200, mas **sem nenhuma lógica de negócio ainda**.

---

## Etapa 3 — Modelar o domínio (a parte mais importante do case)

Um módulo custom em Drupal é uma pasta com um arquivo `nome.info.yml` (metadados) dentro de `web/modules/custom/`. A partir daí, cada tipo de arquivo tem um papel:

### 3.1 — Entidades de conteúdo (`src/Entity/`)

O case proíbe usar `node`. Isso força a criar **Content Entities** próprias — o mesmo mecanismo por trás de `node`, `user`, `taxonomy_term`, mas para um domínio novo. Cada entidade tem sua própria tabela no banco.

Três entidades:

- **`VotingQuestion`** — a pergunta. Campos: `title`, `description`, `show_results` (boolean), `status` (ativa/inativa, via `EntityPublishedTrait`), `uid` (autor, via `EntityOwnerTrait`).
- **`VotingOption`** — uma opção de resposta. Referencia a pergunta (`entity_reference` para `voting_question`), tem `title`, `description`, `image` e `weight` (ordem de exibição).
- **`VotingVote`** — o voto em si. Referencia pergunta e opção, e guarda o `voter_key` normalizado (explicado na Etapa 0), além de `uid` (se veio do CMS) ou `external_user_id` (se veio da API).

**Por que traits (`EntityChangedTrait`, `EntityPublishedTrait`, `EntityOwnerTrait`)?** Drupal já resolveu os padrões "quando foi editado", "está publicado?", "quem é o dono" de forma reutilizável. Reaproveitar evita reinventar campos e comportamento que o core já dá de graça (e que outros módulos/admin UI já sabem exibir).

### 3.2 — A garantia de "um voto por pessoa" no nível certo

Esse é o ponto mais defensável tecnicamente do projeto: a unicidade do voto **não é só uma verificação em PHP antes de salvar**. Isso seria vulnerável a uma condição de corrida (duas requisições simultâneas passando pela verificação "ainda não votou" antes de qualquer uma delas salvar).

A solução foi criar um **índice único composto no banco**, em `(question, voter_key)`. Como Content Entities customizadas não expõem isso diretamente na definição de campo, foi necessário:
- Criar `VotingVoteStorageSchema`, uma subclasse de `SqlContentEntityStorageSchema` que intercepta a definição de schema da tabela e injeta a `unique keys`.
- Registrar essa classe como handler `storage_schema` na anotação da entidade `VotingVote`.

Resultado prático: mesmo que dois requests cheguem ao mesmo tempo, só um `INSERT` consegue ter sucesso — o banco rejeita o segundo. O código trata esse erro (`EntityStorageException`) e devolve a mesma mensagem de "já votou" que teria dado se a checagem prévia tivesse pego o caso.

### 3.3 — O serviço central de regras de negócio (`src/Service/VoteService.php`)

Em vez de duplicar a lógica de "pode votar? já votou? qual o resultado?" dentro do controller do CMS **e** dentro do controller da API, existe **um único serviço** (`VoteService`) usado pelos dois. Isso é o princípio de não repetir regra de negócio em duas camadas de apresentação diferentes — se um dia a regra mudar, muda em um lugar só, e as duas superfícies (CMS e API) continuam idênticas em comportamento.

Ele expõe:
- `getVotableQuestion()` — valida se a pergunta existe, está ativa e a votação não foi desabilitada globalmente.
- `castVoteForUser()` / `castVoteForExternalUser()` — dois pontos de entrada (um por tipo de voto), que convergem no mesmo método privado `castVote()`.
- `hasVoted()` — checagem de duplicidade.
- `getResults()` — agregação de votos por opção via query SQL direta (`COUNT` + `GROUP BY`), mais eficiente do que carregar todas as entidades de voto em PHP para contar.

Erros de negócio são sinalizados via uma exceção própria, `VotingException`, com um campo `reason` (string) que os controllers usam para decidir o código HTTP ou a mensagem certa — sem precisar fazer `if` em texto de mensagem de erro.

### 3.4 — Autenticação e configuração globais

- **`VotingSettings`** — wrapper simples sobre a config `simple_voting.settings` (arquivo YAML gerenciável via admin), com um único método `isVotingDisabled()`. É o "kill switch" global do case.
- **`ApiKeyAuth`** — um *authentication provider* customizado do Drupal. Ele intercepta requisições que tragam o header `X-API-Key`, compara com a chave configurada (`hash_equals`, para evitar timing attack), e se válida, autentica a requisição como uma conta especial (sem uid de usuário real) com uma role dedicada (`external_voting_api_client`), que tem só a permissão mínima necessária (`use simple voting external api`).

Esse padrão — autenticar a **aplicação**, não uma pessoa — é comum em integrações machine-to-machine, e é diferente de OAuth/login de usuário porque não há uma "sessão" de usuário do outro lado, só um sistema conversando com outro.

---

## Etapa 4 — As duas superfícies de uso

### 4.1 — CMS (`VotingPageController` + `Form/*`)

- `VotingQuestionForm` / `VotingOptionForm`: formulários administrativos padrão do Drupal (`ContentEntityForm`), que geram automaticamente os campos de edição a partir da definição da entidade. Poucochas de código customizado: só o suficiente para o form de opção já vir pré-associado à pergunta certa (via parâmetro de rota) e para redirecionar para o lugar certo depois de salvar.
- `SimpleVotingSettingsForm`: formulário de configuração global (toggle de votação + API key), usando `ConfigFormBase` — o padrão do Drupal para telas de configurações simples ligadas a um arquivo de config.
- `VotingPageController`: a parte visível pro usuário final. Lista perguntas abertas, mostra o formulário de voto (HTML montado manualmente, sem um framework de formulário, porque é simples e não precisa de todo o aparato do Form API para dois campos), bloqueia novamente no submit se a pessoa já votou, e mostra a tabela de resultados quando aplicável.

### 4.2 — API externa (`VotingApiController` + `simple_voting.routing.yml`)

Quatro endpoints, todos manuais (JSON montado com array PHP + `JsonResponse`, sem qualquer camada de serialização automática):

| Rota | Verbo | Função |
|---|---|---|
| `/api/v1/voting/questions` | GET | lista perguntas ativas |
| `/api/v1/voting/questions/{id}` | GET | detalhe + opções |
| `/api/v1/voting/questions/{id}/votes` | POST | registra voto (`option_id` + `external_user_id` no body) |
| `/api/v1/voting/questions/{id}/results` | GET | resultados agregados |

Cada rota declara `_auth: [simple_voting_api_key]` nas suas opções — isso é o que conecta a rota ao `ApiKeyAuth` criado na etapa anterior. Erros de negócio (`VotingException`) são mapeados para códigos HTTP semanticamente corretos (404 não encontrado, 403 proibido/desabilitado, 409 conflito quando já votou).

---

## Etapa 5 — Testar de verdade (não assumir que "parece certo")

Aqui a diferença entre "escrevi o código" e "sei que funciona" apareceu na prática: **8 bugs reais** só foram descobertos ao rodar o sistema, não ao ler o código:

1. `VotingQuestion` precisava declarar explicitamente as interfaces `EntityOwnerInterface`/`EntityPublishedInterface` (usar só os traits não bastava para o Drupal reconhecer a capacidade).
2. Conflito de nome de propriedade (`$routeMatch`) entre a classe do formulário e a classe-base do Drupal — precisou renomear.
3. O controller da API não implementava `ContainerInjectionInterface`, então o Drupal não sabia como instanciá-lo com os serviços certos.
4. Um `array_map` preservava chaves não sequenciais, fazendo a lista de opções virar um objeto JSON em vez de array — corrigido com `array_values()`.
5. `addExpression()` do Query Builder do Drupal retorna uma string (o alias da coluna), não o próprio objeto de query — encadear `->groupBy()` depois dele quebrava. Precisou separar as chamadas.
6. O HTML do formulário de voto, entregue como `#markup` puro, era filtrado pelo sanitizador XSS padrão do Drupal, que **remove tags `<form>` e `<input>`** por segurança. A correção foi envolver o HTML (que já era montado de forma seguro, escapando dados de usuário) em `Markup::create()`, sinalizando explicitamente "eu confio nesse HTML".
7. A página de votação estava sendo cacheada mesmo variando por usuário (quem já votou vê algo diferente de quem não votou) — corrigido adicionando metadados de cache (`max-age: 0`, contexto `user`).
8. Uma query de resultados sem os módulos de dependência certos (`text`) falhava só dentro do ambiente de teste automatizado, não em produção — descoberto ao rodar PHPUnit.

Essa lista importa numa entrevista: mostra que o processo teve verificação real em cada etapa (rodar, ver erro, entender a causa raiz, corrigir, testar de novo), e não só "escrevi e deu certo de primeira".

### Como cada coisa foi verificada

- **API externa**: testada via `curl` direto contra as rotas (autenticação com e sem chave, voto, voto duplicado, resultados).
- **CMS**: simulado via `curl` com sessão de cookie autenticada (login, GET no formulário, POST do voto, checagem do HTML resultante) — inclusive criando um segundo usuário de teste para confirmar que usuários diferentes podem votar independentemente.
- **Toggle global**: ligado e desligado via `drush php:eval`, confirmando bloqueio nas duas superfícies.
- **Testes automatizados** (`tests/src/Kernel/VoteServiceTest.php`): usando `KernelTestBase` (testes que rodam com um banco de dados real e o sistema de plugins do Drupal ativo, mas sem servidor HTTP), cobrindo: voto único, duplicidade (usuário interno e externo), voters independentes, opção de outra pergunta, pergunta inativa, toggle global, resultados com zero votos. Resultado final: **8 testes, 60 assertions, 0 falhas**.

---

## Etapa 6 — Empacotar para entrega

- **Dump do banco** (`database/dump.sql`): gerado via `drush sql:dump`, contendo schema + dados de teste (pergunta, opções, votos).
- **Collection Postman** (`postman/*.json`): os quatro endpoints da API externa, com variáveis (`base_url`, `api_key`, IDs) para facilitar reuso, incluindo um caso propositalmente de voto duplicado para demonstrar o erro 409.
- **README.md**: como subir o ambiente, como importar o dump, como configurar a API key, lista de endpoints, estrutura do módulo, como rodar os testes.

---

## Etapa 7 — Endurecer antes de publicar (segurança)

Antes de tornar o repositório **público** no GitHub, uma checagem específica de segurança:

1. `web/sites/default/settings.php` estava versionado — esse arquivo contém o `hash_salt` (usado internamente pelo Drupal para gerar tokens de sessão/CSRF) e as credenciais de conexão ao banco. Removido do controle de versão; agora precisa ser gerado localmente (`cp default.settings.php settings.php`) antes do `lando start`.
2. O dump do banco continha **hashes bcrypt reais** das senhas de teste (`admin`/`voter2`). Mesmo sendo bcrypt (resistente a força bruta), senhas de dicionário como "admin" são quebráveis rapidamente — os hashes foram substituídos por um placeholder textual antes do commit.
3. A API key de teste apareceu no dump (config exportada) — também substituída por um placeholder.
4. Uma variável de ambiente não utilizada no `.lando.yml` foi removida (resquício de uma decisão de design anterior que não chegou a ser implementada daquela forma).

Isso é o tipo de revisão que qualquer processo de "publicar código real" deveria ter — separar segredo de configuração e nunca subir credenciais reais, mesmo que sejam "só de ambiente local".

---

## Etapa 8 — Publicação

- Instalação da GitHub CLI (`gh`) sem privilégio de root, via binário `.tar.gz` extraído em `~/.local/bin` (o `apt install` normal exigiria senha de sudo interativa, que não estava disponível no fluxo automatizado).
- Autenticação via **device flow** (código de uso único inserido manualmente no navegador — o mesmo mecanismo que TVs/consoles usam para logar em serviços de streaming).
- Criação do repositório público e push:
  ```bash
  gh repo create simple-voting-drupal --public --source=. --remote=origin
  git push -u origin master
  ```
- Confirmação pós-publicação: visibilidade `PUBLIC`, ausência de `settings.php`, zero hashes bcrypt no dump remoto — tudo verificado consultando o conteúdo real do repositório publicado, não assumido.

---

## Perguntas que provavelmente vão aparecer na entrevista (e por quê)

- **"Por que não usar `node` para a pergunta?"** → O case pede isso explicitamente, mas a resposta técnica é: content entities dedicadas dão um schema de banco mais limpo (sem os campos genéricos que `node` sempre traz) e deixam claro que "pergunta de votação" não é um tipo de conteúdo editorial genérico.
- **"Como você garante que não existe voto duplicado sob concorrência?"** → índice único composto no banco, não checagem em aplicação. Ser capaz de explicar a diferença entre "checar antes de salvar" (tem race condition) e "constraint no banco" (não tem) é o ponto mais forte tecnicamente da solução.
- **"Por que a API usa API key e não OAuth/JWT?"** → o case define a API como para "aplicações externas", não usuários finais logados — API key por aplicação é o padrão mais simples e adequado para esse cenário machine-to-machine, e quem realmente vota é identificado por um campo no payload, não pela autenticação.
- **"O que aconteceria se o admin desabilitar votação no meio de uma votação em andamento?"** → a checagem acontece a cada tentativa de voto (`getVotableQuestion()`), então o efeito é imediato: nenhuma nova tentativa passa, sem precisar de lógica extra de "cancelar votos em andamento".
- **"Por que separar `VoteService` do controller?"** → separação de responsabilidade: controller lida com HTTP (request/response, status codes), serviço lida com regra de negócio pura, reutilizável nos dois pontos de entrada.
