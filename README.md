# Simple Voting

Sistema de votação simples implementado como módulo custom do Drupal 10 (`simple_voting`), com entidades de conteúdo próprias (sem usar `node`), interface administrativa integrada ao CMS, página de votação para usuários autenticados e uma API REST externa implementada manualmente (sem JSON:API).

## Requisitos

- Docker
- [Lando](https://docs.lando.dev/)

## Como rodar o projeto

```bash
git clone <repo>
cd simple-voting-drupal
lando start
lando composer install
```

O `lando start` provisiona PHP 8.3, MySQL 8.0 e Nginx. O site já vem instalado (perfil `standard`) com o módulo `simple_voting` habilitado.

- URL local: a que o `lando start` exibir no final (ex: `http://simple-voting-drupal.lndo.site:8000`)
- Login admin: `admin` / `admin`

Para recriar o banco do zero em vez de usar o dump fornecido:

```bash
lando drush site:install standard --account-name=admin --account-pass=admin -y
lando drush en simple_voting -y
```

### Importando o dump de banco fornecido

```bash
lando drush sql:cli < database/dump.sql
```

## Configuração da API externa

Antes de usar a API, defina a API key em **Configuration > Simple Voting settings** (`/admin/config/content/simple-voting`), ou via drush:

```bash
lando drush php:eval "\Drupal::configFactory()->getEditable('simple_voting.settings')->set('api_key', 'minha-chave-secreta')->save();"
```

Todas as requisições à API externa devem enviar essa chave no header `X-API-Key`.

## Estrutura do módulo

`web/modules/custom/simple_voting/`

- **Entidades** (`src/Entity`): `VotingQuestion`, `VotingOption`, `VotingVote`. Não são `node`; são content entities dedicadas, com tabelas próprias.
- **Regra de voto único**: a entidade `VotingVote` tem um índice único composto no banco em `(question, voter_key)` (ver `VotingVoteStorageSchema`), então nem uma condição de corrida entre requisições simultâneas consegue gerar voto duplicado — a garantia é no nível do banco, não só na aplicação.
- **`voter_key`**: normaliza o identificador do votante como `user:<uid>` (voto pelo CMS) ou `external:<external_user_id>` (voto pela API externa), permitindo que as duas superfícies compartilhem a mesma regra de unicidade.
- **`VoteService`** (`src/Service`): serviço único com toda a lógica de negócio (validar pergunta ativa, checar voto duplicado, registrar voto, calcular resultados). Tanto o controller do CMS quanto o da API externa delegam a ele, garantindo que as duas superfícies nunca se comportem de forma diferente.
- **Toggle global**: `simple_voting.settings:voting_disabled` bloqueia votos em qualquer pergunta, tanto no CMS quanto na API, com um único switch em `/admin/config/content/simple-voting`.
- **Autenticação da API externa** (`src/Authentication/Provider/ApiKeyAuth.php`): provider de autenticação Drupal custom, valida o header `X-API-Key` contra a chave configurada.
- **API externa** (`src/Controller/VotingApiController.php`): endpoints REST manuais, sem JSON:API nem Serializer — JSON montado à mão.

## Endpoints da API externa

Todos exigem o header `X-API-Key`.

| Método | Rota | Descrição |
|---|---|---|
| GET | `/api/v1/voting/questions` | Lista perguntas ativas |
| GET | `/api/v1/voting/questions/{id}` | Detalhe da pergunta com opções |
| POST | `/api/v1/voting/questions/{id}/votes` | Registra um voto. Body: `{"option_id": 1, "external_user_id": "abc-123"}` |
| GET | `/api/v1/voting/questions/{id}/results` | Resultados agregados (respeita a flag "show results" da pergunta) |

Uma collection Postman pronta está em `postman/Simple_Voting_API.postman_collection.json`.

## Interface administrativa

- `/admin/content/voting-question` — listar/criar/editar perguntas
- Dentro de cada pergunta: adicionar opções de resposta (título, descrição, imagem)
- `/admin/config/content/simple-voting` — configurações globais (desabilitar votação, API key)

## Votação pelo CMS

Usuários autenticados com a permissão "Cast a vote" acessam `/voting` para ver as perguntas abertas e votar. Após votar, se a pergunta tiver "Show results" habilitado, os resultados agregados são exibidos.

## Testes automatizados

```bash
lando ssh -c "cd /app && SIMPLETEST_DB=mysql://drupal10:drupal10@database/drupal10 SIMPLETEST_BASE_URL=http://localhost vendor/bin/phpunit -c web/core web/modules/custom/simple_voting/tests/src/Kernel/VoteServiceTest.php"
```

Cobrem: voto único por usuário/aplicação externa, rejeição de voto duplicado, opção pertencente a outra pergunta, pergunta inativa, toggle global de votação, e cálculo de resultados (incluindo opções sem nenhum voto).
