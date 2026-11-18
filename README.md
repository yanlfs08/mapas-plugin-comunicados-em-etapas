# Plugin de Observações em Etapas para o Mapas Culturais

Plugin para o [Mapas Culturais](https://github.com/mapasculturais/mapasculturais) que permite ao gestor publicar **observações** (título, mensagem, imagem e anexos) em **qualquer fase** de um edital. Cada observação aparece na **linha do tempo da inscrição** de todos os inscritos daquela fase.

Serve para avisos como a divulgação de um resultado preliminar, uma mudança de prazo, orientações sobre documentos ou a ata de uma reunião da comissão.

| Gestor publica | Gestor acompanha | Candidato vê na linha do tempo |
|---|---|---|
| ![Formulário](docs/capturas/gestor-formulario.png) | ![Observação publicada](docs/capturas/gestor-observacao-publicada.png) | ![Linha do tempo](docs/capturas/candidato-linha-do-tempo.png) |

## Sumário

1. [O que o plugin faz](#o-que-o-plugin-faz)
2. [Regras](#regras)
3. [Requisitos](#requisitos)
4. [Instalação](#instalação)
   - [Instalação direta (sem Docker)](#a-instalação-direta-sem-docker)
   - [Instalação com Docker (projeto base do Mapas)](#b-instalação-com-docker-projeto-base-do-mapas)
   - [Conferindo se o plugin está ativo](#conferindo-se-o-plugin-está-ativo)
   - [Configurações opcionais](#configurações-opcionais)
   - [Atualizando o plugin](#atualizando-o-plugin)
   - [Problemas comuns](#problemas-comuns)
5. [Como usar](#como-usar)
6. [Modelo de dados](#modelo-de-dados)
7. [Referência técnica](#referência-técnica)
   - [Estrutura de arquivos](#estrutura-de-arquivos)
   - [Metadado e grupos de arquivo](#metadado-e-grupos-de-arquivo)
   - [Endpoints](#endpoints)
   - [Pontos de encaixe nas telas](#pontos-de-encaixe-nas-telas)
   - [Funções da classe `Plugin`](#funções-da-classe-plugin)
   - [Componentes Vue](#componentes-vue)
8. [Atualizações do Mapas](#atualizações-do-mapas)
9. [Testes](#testes)
10. [Limitações conhecidas](#limitações-conhecidas)
11. [Desinstalação](#desinstalação)

## O que o plugin faz

**Gestor.** Na edição da oportunidade, aba **Configuração de fases**, cada fase (período de inscrição, fases de avaliação, fases de coleta de dados e publicação final) ganha, no fim, a seção **Observações para os inscritos desta fase**. Nela, o gestor:

- publica uma observação com **título** (opcional), **mensagem**, **uma imagem** e **até 5 anexos**;
- vê as observações já publicadas na fase, com data, autor, imagem e anexos;
- **edita** uma observação: muda o texto, troca ou remove a imagem, remove anexos e acrescenta outros;
- **exclui** uma observação, o que apaga também a imagem e os anexos.

**Candidato.** Na linha do tempo da inscrição (*Minhas inscrições* → inscrição → aba *Acompanhamento*), cada observação aparece **dentro da fase a que pertence**, como um quadro **[Observação]** no mesmo formato do quadro *[Recurso]* do Mapas. O quadro mostra o título, a data de publicação, a mensagem (com as quebras de linha), a imagem (que abre em tamanho real ao clicar) e os anexos para baixar. As mais recentes aparecem primeiro.

## Regras

| Regra | Como funciona |
|---|---|
| Quem publica | O gestor do edital (quem tem controle sobre a oportunidade). |
| Quem vê | Todos que têm inscrição **na fase** da observação. Observações da fase de inscrições aparecem para todos os inscritos. Observações de uma fase posterior aparecem só para quem chegou a ela. Inscrições na lixeira não contam. |
| Fase | Cada observação pertence a uma fase e não muda de fase ao ser editada. As fases de avaliação contam separado da fase de coleta de dados (por exemplo, *Fase de inscrições* e *Habilitação* são itens diferentes da linha do tempo). |
| Conteúdo | Pelo menos um destes: título, mensagem, imagem ou anexo. Mensagem de até 10.000 caracteres, em texto simples (sem HTML). |
| Imagem | Uma por observação: JPG, PNG, GIF ou WEBP, até 10 MB. |
| Anexos | Até 5 por observação: PDF, imagens, documentos de texto (DOC, DOCX, ODT), planilhas (XLS, XLSX, ODS), CSV, TXT ou ZIP, até 10 MB cada. O tipo é conferido pelo conteúdo do arquivo, não pela extensão. |
| Privacidade | Imagens e anexos são **arquivos privados**: não ficam na pasta pública e só são entregues pelo endpoint do plugin, que confere se quem pede é gestor ou inscrito na fase. As observações também não aparecem na API pública. |

## Requisitos

- Mapas Culturais **7.8.x**, com o tema **BaseV2**. Testado na **7.8.21**.
- Acesso para editar a configuração do Mapas e reiniciar o PHP (ou os contêineres).
- O PHP precisa aceitar o tamanho dos arquivos: `upload_max_filesize` e `post_max_size` (no projeto base, 20 MB) e, no nginx, `client_max_body_size`.

O plugin **não cria tabelas** e **não precisa de `db-update`**. Ele usa metadados e arquivos das entidades do próprio Mapas.

## Instalação

O plugin precisa ficar na pasta `src/plugins/Observacoes` do Mapas. O nome da pasta tem que ser exatamente `Observacoes`, porque é também o namespace PHP do plugin.

### A) Instalação direta (sem Docker)

1. **Baixe o plugin** para a pasta de plugins do Mapas:

   ```bash
   cd /caminho/do/mapas/src/plugins
   git clone https://github.com/yanlfs08/mapas-plugin-observacao-em-etapas.git Observacoes
   ```

2. **Ative o plugin** na configuração. Abra o arquivo de configuração de plugins que a sua instalação usa (por exemplo, `config/plugins.php` ou um arquivo dentro de `config/*.d/`) e acrescente `Observacoes` à lista, **sem remover os plugins que já existem**:

   ```php
   <?php
   return [
       'plugins' => [
           'MultipleLocalAuth' => ['namespace' => 'MultipleLocalAuth'],
           // ... outros plugins já ativos
           'Observacoes' => ['namespace' => 'Observacoes'],
       ],
   ];
   ```

3. **Recompile os assets**, se a sua instalação compila os componentes com `pnpm`:

   ```bash
   cd /caminho/do/mapas/src
   pnpm install --recursive && pnpm run build
   ```

4. **Limpe o cache e reinicie o PHP:**

   ```bash
   redis-cli FLUSHALL                  # se o cache do Mapas estiver no Redis
   sudo systemctl reload php8.3-fpm    # ajuste para a versão do seu PHP-FPM
   ```

5. [Confira se o plugin está ativo](#conferindo-se-o-plugin-está-ativo).

### B) Instalação com Docker (projeto base do Mapas)

No [projeto base do Mapas](https://github.com/mapasculturais/mapasculturais-base-project), a imagem é gerada a partir de `docker/Dockerfile`, que **copia** a pasta `plugins/` e a configuração para dentro da imagem (`COPY plugins /var/www/src/plugins` e `COPY docker/common/config.d /var/www/config/common.d`). Por isso, toda instalação ou atualização do plugin exige **gerar a imagem de novo**.

1. **Baixe o plugin** para a pasta `plugins/` do projeto:

   ```bash
   cd /caminho/do/projeto-base/plugins
   git clone https://github.com/yanlfs08/mapas-plugin-observacao-em-etapas.git Observacoes
   ```

2. **Ative o plugin** em `docker/common/config.d/plugins.php`:

   ```php
   <?php
   return [
       'plugins' => [
           'MultipleLocalAuth' => ['namespace' => 'MultipleLocalAuth'],
           'SamplePlugin' => ['namespace' => 'SamplePlugin'],
           'SpamDetector',
           'Observacoes' => ['namespace' => 'Observacoes'],
       ],
   ];
   ```

3. **Gere a imagem e recrie o contêiner da aplicação:**

   ```bash
   cd /caminho/do/projeto-base
   docker compose build mapasculturais
   docker compose up -d mapasculturais
   ```

4. **Limpe o cache** (o Redis de cache do projeto base se chama `redis`):

   ```bash
   docker compose exec redis redis-cli FLUSHALL
   ```

5. [Confira se o plugin está ativo](#conferindo-se-o-plugin-está-ativo).

> **Alternativa para desenvolvimento:** monte a pasta do plugin como volume no serviço `mapasculturais` do `docker-compose.yml` (`- ./plugins/Observacoes:/var/www/src/plugins/Observacoes`). Mudanças em PHP valem depois de limpar o cache. Mudanças em JavaScript ou CSS exigem recompilar os assets dentro do contêiner (`pnpm run build` em `/var/www/src`).

**Os arquivos enviados** ficam na pasta de arquivos privados do Mapas (no projeto base, `docker-data/private-files`, montada em `/var/www/var/private-files`). Inclua essa pasta no backup.

### Conferindo se o plugin está ativo

1. **Arquivos no lugar** (no Docker, dentro do contêiner):

   ```bash
   docker compose exec mapasculturais ls /var/www/src/plugins
   # deve listar: Observacoes
   ```

2. **Metadado registrado.** A descrição das oportunidades passa a listar o metadado do plugin:

   ```bash
   curl -s http://localhost/api/opportunity/describe | grep -o '"phaseObservations"' | head -1
   ```

3. **Na tela:** abra uma oportunidade, aba **Configuração de fases**, expanda uma fase e confira a seção **Observações para os inscritos desta fase** no fim dela.

4. **Log sem erros.** No projeto base, o log fica em `docker-data/logs/app.log`. Se a versão do Mapas estiver fora da faixa testada, aparece o aviso *"Plugin Observacoes ... foi testado com o Mapas Culturais 7.8.x"*.

### Configurações opcionais

Para mudar os limites, informe `config` na ativação do plugin:

```php
'plugins' => [
    'Observacoes' => [
        'namespace' => 'Observacoes',
        'config' => [
            'maxFileSizeMb' => 10,       // tamanho máximo de cada arquivo, em MB
            'maxAttachments' => 5,       // anexos por observação
            'maxTextLength' => 10000,    // caracteres da mensagem
            'imageMimeTypes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
            // 'attachmentMimeTypes' => [...],   // tipos aceitos nos anexos (veja Plugin::__construct)
        ],
    ],
],
```

| Chave | Padrão | Efeito |
|---|---|---|
| `maxFileSizeMb` | `10` | Tamanho máximo de cada imagem ou anexo. Não pode passar dos limites do PHP e do nginx. |
| `maxAttachments` | `5` | Quantidade máxima de anexos por observação. |
| `maxTextLength` | `10000` | Tamanho máximo da mensagem. |
| `imageMimeTypes` | JPG, PNG, GIF e WEBP | Tipos aceitos como imagem (mostrada na linha do tempo). |
| `attachmentMimeTypes` | PDF, imagens, DOC/DOCX, XLS/XLSX, ODT/ODS, ZIP, TXT e CSV | Tipos aceitos como anexo. Não acrescente tipos executáveis. |

### Atualizando o plugin

```bash
cd /caminho/para/plugins/Observacoes
git pull
```

Depois, na **instalação direta**, recompile os assets e recarregue o PHP. No **Docker**, rode `docker compose build mapasculturais && docker compose up -d mapasculturais`. Nos dois casos, limpe o cache. Leia o `CHANGELOG.md` antes de atualizar.

### Problemas comuns

| Sintoma | Causa provável e solução |
|---|---|
| A seção *Observações* não aparece na Configuração de fases | Plugin não ativado ou imagem antiga. No Docker, o `git pull` sozinho não muda nada: é preciso gerar a imagem de novo. Expanda a fase: a seção fica no fim do conteúdo dela. |
| "O arquivo é maior que o limite do servidor" | O arquivo passou do `upload_max_filesize`/`post_max_size` do PHP. Aumente esses limites ou envie um arquivo menor. |
| "O tipo do arquivo não é aceito" | O conteúdo do arquivo não corresponde a um tipo aceito (o plugin confere o conteúdo, não a extensão). Ajuste `attachmentMimeTypes` se for um tipo legítimo. |
| O candidato não vê a observação | Ele não tem inscrição naquela fase (por exemplo, foi eliminado antes). Confira em qual fase a observação foi publicada. |
| Imagem ou anexo responde 403 | Quem pediu não é gestor nem inscrito na fase. É o comportamento esperado. |
| O candidato é levado para a edição do perfil | Comportamento do Mapas: perfil incompleto redireciona para a edição. Não é do plugin. |

## Como usar

1. Na edição da oportunidade, abra a aba **Configuração de fases** e expanda a fase em que quer publicar.
2. No fim da fase, na seção **Observações para os inscritos desta fase**, clique em **Adicionar observação**.
3. Preencha o título (opcional) e a mensagem. Se quiser, escolha uma imagem e até 5 anexos.
4. Clique em **Publicar observação**. Ela aparece na hora na linha do tempo dos inscritos daquela fase.
5. Para corrigir, clique em **Editar**. Para retirar, clique em **Excluir**.

## Modelo de dados

O plugin não cria tabelas.

| O quê | Onde fica |
|---|---|
| Observações | Metadado **privado** `phaseObservations` (JSON) da **primeira fase** do edital (a oportunidade principal). É uma lista de itens com `id`, `phaseType`, `phaseId`, `title`, `text`, `imageId`, `attachmentIds`, `createTimestamp`, `updateTimestamp` e `authorName`. |
| Fase da observação | `phaseType` + `phaseId`, os mesmos identificadores dos itens da linha do tempo (`item.__objectType` e `item.id`): `opportunity` para fases de coleta de dados e a publicação final, `evaluationmethodconfiguration` para fases de avaliação. |
| Imagem e anexos | Arquivos (`OpportunityFile`) **privados** da primeira fase, nos grupos `phaseObservationImage` e `phaseObservationAttachment`. |
| Quem está na fase | As inscrições (com status ≥ 0) na oportunidade da fase: a própria oportunidade, no caso de coleta de dados, ou a oportunidade da avaliação, no caso de avaliação. |

Exemplo de item:

```json
{
  "id": "obs_5f3a9c1e2b7d4a60",
  "phaseType": "evaluationmethodconfiguration",
  "phaseId": 4,
  "title": "Divulgação do resultado preliminar do Mérito",
  "text": "O resultado preliminar do Mérito foi publicado.\nVeja a ata em anexo.",
  "imageId": 31,
  "attachmentIds": [32],
  "createTimestamp": "2026-11-18 16:34:10",
  "updateTimestamp": null,
  "authorName": "Gestora do Edital"
}
```

## Referência técnica

Toda a lógica está em `Plugin.php`. As telas usam componentes Vue no padrão do tema BaseV2.

### Estrutura de arquivos

```
Observacoes/
├── Plugin.php                                   toda a lógica (metadado, arquivos, endpoints, telas)
├── assets/css/observacoes.css                   estilos dos componentes
├── components/
│   ├── phase-observations-config/               seção do gestor na Configuração de fases
│   │   ├── template.php
│   │   ├── script.js
│   │   └── texts.php
│   └── phase-observations-timeline/             quadros [Observação] na linha do tempo do candidato
│       ├── template.php
│       └── script.js
├── tests/
│   ├── api_test.py                              30 verificações de API e regras
│   └── scenario.example.json                    modelo do cenário de teste
├── docs/capturas/                               imagens deste README
├── CHANGELOG.md
└── CLAUDE.md                                    contexto e armadilhas para manutenção
```

### Metadado e grupos de arquivo

Registrados em `Plugin::register()`.

| Tipo | Nome | Para que serve |
|---|---|---|
| Metadado de Opportunity | `phaseObservations` (json, **privado**) | Lista das observações do edital, guardada na primeira fase. Privado para não aparecer na API pública. |
| Grupo de arquivo de Opportunity | `phaseObservationImage` (privado, várias por oportunidade) | Imagens das observações. Só aceita os tipos de `imageMimeTypes`. |
| Grupo de arquivo de Opportunity | `phaseObservationAttachment` (privado, várias por oportunidade) | Anexos das observações. O tipo é validado pelo endpoint (`attachmentMimeTypes`). |

### Endpoints

Ações no controlador de oportunidade do Mapas. Todos exigem login.

| Endpoint | Quem pode | O que faz |
|---|---|---|
| `POST /opportunity/savePhaseObservation/{id de qualquer fase do edital}` | gestor | Cria ou edita uma observação. Envio **multipart/form-data** com os campos `observationId` (só para editar), `phaseType`, `phaseId`, `title`, `text`, `removeImage` (`1` para remover), `removeAttachmentIds[]` e os arquivos `image` e `attachments[]`. Valida a fase, os tamanhos, os tipos (pelo conteúdo) e o limite de anexos **antes** de gravar qualquer arquivo. Na edição, a fase não muda. Resposta: a observação serializada. |
| `POST /opportunity/deletePhaseObservation/{id de qualquer fase do edital}` | gestor | Corpo JSON `{"observationId": "..."}`. Exclui a observação, a imagem e os anexos. Responde 404 se a observação não existir. |
| `GET /opportunity/phaseObservationFile/{id da primeira fase}/file:{id do arquivo}` | gestor ou inscrito na fase da observação | Entrega a imagem (inline) ou o anexo (download). Com `?download=1`, força o download também para imagens. Qualquer outro caso responde 403. |

O campo é `observationId`, e não `id`, porque o Mapas já coloca em `id` o número da oportunidade que vem na URL.

### Pontos de encaixe nas telas

Registrados em `Plugin::initTemplateHooks()`.

| Hook | O que faz |
|---|---|
| `template(opportunity.edit.tabs):end` | Na edição da oportunidade, para quem tem controle, monta `$MAPAS.config.phaseObservationsConfig` (limites, tipos aceitos e todas as observações do edital) e importa `phase-observations-config`. |
| `component(opportunity-phase-config-data-collection):bottom` | Coloca `<phase-observations-config :phase="phase">` no fim de cada fase de coleta de dados (inclusive a fase de inscrições). |
| `component(opportunity-phase-config-evaluation):bottom` | Idem, no fim de cada fase de avaliação. |
| `component(opportunity-phase-config-results):bottom` | Idem, na publicação final do resultado. |
| `template(registration.<<view\|single>>.single-tab):begin` | Na página da inscrição, monta `$MAPAS.config.phaseObservations` só com as observações das fases em que essa inscrição está. A filtragem é feita no servidor: o navegador não recebe observações de outras fases. |
| `component(opportunity-phases-timeline).item:end` | Coloca `<phase-observations-timeline :phase="item">` no fim de cada item da linha do tempo. O componente mostra as observações daquele item. |
| `app.init:after` | Carrega `assets/css/observacoes.css`. |

Os encaixes do gestor e da linha do tempo são pontos de encaixe oficiais do Mapas (o mesmo usado pelo módulo nativo `OpportunityExecution` na linha do tempo).

### Funções da classe `Plugin`

**Configuração e inicialização**

| Função | O que faz |
|---|---|
| `__construct(array $config)` | Aplica os valores padrão de `maxFileSizeMb`, `maxAttachments`, `maxTextLength`, `imageMimeTypes` e `attachmentMimeTypes`. |
| `_init()` | Verifica a versão do Mapas, carrega o CSS e registra os endpoints e os pontos de encaixe. |
| `register()` | Registra o metadado `phaseObservations` e os grupos de arquivo privados. |
| `checkMapasVersion()` | Lê o `version.txt` do Mapas e grava um aviso no log se a versão não for 7.8.x. |

**Leitura e gravação das observações**

| Função | O que faz |
|---|---|
| `getObservations($opportunity)` | Lista de observações do edital (lida da primeira fase, sem a checagem de metadado privado; quem chama decide o que mostrar). |
| `saveObservations($opportunity, $observations)` | Grava a lista como JSON com `setMetadata()` + `saveMetadata()`, sem salvar a oportunidade inteira (que dispararia validações sem relação com as observações). |
| `sortObservations($observations)` | Ordena da mais recente para a mais antiga. |

**Fases e permissões**

| Função | O que faz |
|---|---|
| `getPhaseTargets($opportunity)` | Todas as fases do edital que podem receber observação, no formato `"tipo:id"` → oportunidade em que estão as inscrições daquela fase. |
| `getPhaseOpportunity($opportunity, $type, $id)` | Oportunidade das inscrições de uma fase, ou `null` se a fase não for do edital (usada para validar a fase enviada). |
| `getPhaseLabel($opportunity, $type, $id)` | Nome da fase como aparece na linha do tempo (*Fase de inscrições*, nome da avaliação etc.). |
| `userHasRegistrationInPhase($phase_opportunity, $user)` | O usuário tem inscrição (status ≥ 0) na fase? Usada no download dos arquivos. |
| `registrationNumberIsInPhase($phase_opportunity, $number)` | O número de inscrição está presente na fase? Usada para montar a linha do tempo de uma inscrição. |
| `getObservationsForRegistration($registration)` | Observações visíveis numa inscrição: as das fases em que o número dela está. |

**Dados para as telas**

| Função | O que faz |
|---|---|
| `serializeObservation($opportunity, $observation)` | Observação para o front-end, com o nome da fase e, para cada arquivo, nome, tipo, se é imagem e a URL do endpoint protegido. |
| `getFileUrl($first_phase, $file)` | URL do endpoint `phaseObservationFile` para um arquivo. |

**Arquivos**

| Função | O que faz |
|---|---|
| `uploadedFiles($field)` (estática) | Normaliza `$_FILES[campo]` (um ou vários arquivos) numa lista, ignorando campos vazios. |
| `validateUpload($upload, $allowed_mime_types)` | Confere erro de envio, tamanho e tipo real do conteúdo (`mime_content_type`). Devolve `null` ou a mensagem de erro. |
| `storeUpload($first_phase, $upload, $group)` | Grava o arquivo como `OpportunityFile` da primeira fase no grupo indicado. Como o grupo é privado, o Mapas grava na pasta privada. |
| `deleteFile($first_phase, $id)` | Apaga um arquivo do plugin (só se for da primeira fase e de um dos grupos do plugin). |

**Organização dos hooks**

| Função | O que registra |
|---|---|
| `initEndpoints()` | Os três endpoints. |
| `initTemplateHooks()` | Os pontos de encaixe nas telas. |

### Componentes Vue

| Componente | Onde aparece | Dados | O que faz |
|---|---|---|---|
| `phase-observations-config` | Fim de cada fase da Configuração de fases (gestor) | `$MAPAS.config.phaseObservationsConfig`; prop `phase` | Lista as observações da fase, abre o formulário de nova observação ou de edição, envia por `FormData` (multipart) para `savePhaseObservation`, confere tamanho e quantidade de anexos antes do envio e exclui com confirmação. |
| `phase-observations-timeline` | Cada item da linha do tempo da inscrição (candidato) | `$MAPAS.config.phaseObservations`; prop `phase` (item da linha do tempo) | Mostra os quadros *[Observação]* do item: título, data, mensagem, imagem e anexos. |

Os textos de interface ficam em `i::__()` no PHP e em `texts.php` com `Utils.getTexts()` no JavaScript, como no restante do Mapas. A mensagem é exibida como texto (com `white-space: pre-wrap`), nunca como HTML.

## Atualizações do Mapas

O plugin fica fora do código do Mapas e usa só pontos de encaixe oficiais (`component(...):bottom`, `component(opportunity-phases-timeline).item:end`, `template(...)`). O risco numa atualização é baixo. Mesmo assim:

1. Use sempre tags de versão do Mapas, nunca o `develop`.
2. Leia o `CHANGELOG.md` do Mapas procurando mudanças em `opportunity-phases-timeline`, `opportunity-phase-config-*` e no tratamento de arquivos privados.
3. Atualize primeiro o servidor de desenvolvimento e rode `tests/api_test.py`.
4. Só depois atualize a produção.

## Testes

O teste roda contra um Mapas de **desenvolvimento** com login local (e-mail e senha). **Nunca rode em produção**: ele publica e apaga observações no edital informado.

1. Copie `tests/scenario.example.json` para `tests/scenario.json` e ajuste:
   - `opportunity`: id do edital (primeira fase);
   - `evaluationPhase`: tipo e id de uma fase de avaliação que **não** seja a primeira;
   - `otherPhaseId`: id de uma fase de **outro** edital;
   - `users`: e-mails do gestor, de alguém inscrito na fase avaliada (`inPhase`), de alguém eliminado antes dela (`eliminated`) e de alguém sem inscrição (`outsider`), mais os ids das inscrições (1ª fase) dos dois primeiros candidatos;
   - `password`: senha comum desses usuários.
2. Rode:

   ```bash
   pip install requests
   MAPAS_URL=http://localhost python3 tests/api_test.py tests/scenario.json   # 30 verificações
   ```

O teste cobre publicação com texto, imagem e anexos, validações (fase de outro edital, observação vazia, imagem falsa, tipo não aceito), quem vê na linha do tempo, quem abre os arquivos, privacidade (API pública e pasta pública), edição (troca de anexo, remoção de imagem, fase fixa) e exclusão (inclusive dos arquivos). Ele apaga as observações que criou.

## Limitações conhecidas

- O plugin **não envia e-mails** nem notificações ao publicar uma observação.
- A mensagem é **texto simples**: sem negrito, listas ou links clicáveis.
- As observações do edital ficam numa única lista. Se dois gestores salvarem **ao mesmo tempo**, a última gravação vale, e a observação do outro pode se perder. Na prática, é raro.
- A imagem é entregue no tamanho original e só reduzida na tela (até a largura do quadro). Imagens muito pesadas deixam a linha do tempo mais lenta.
- As observações aparecem na página da inscrição (linha do tempo), não na página pública do edital.

## Desinstalação

1. Remova `Observacoes` da lista de plugins, gere a imagem de novo (Docker) e limpe o cache. As telas voltam ao padrão.
2. As observações e os arquivos continuam no banco e na pasta privada, e voltam a aparecer se o plugin for reativado.
3. Para apagar tudo antes, exclua as observações pela Configuração de fases (isso apaga também os arquivos).
