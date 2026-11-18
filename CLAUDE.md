# CLAUDE.md — Plugin Comunicados em Etapas (Mapas Culturais)

Contexto para o Claude Code trabalhar neste repositório. Responda em português do Brasil.

## O que é

Plugin do Mapas Culturais (v7.8.x, tema BaseV2) para o gestor publicar **comunicados** (título, texto, imagem e anexos) em qualquer fase de um edital. Cada comunicado aparece na linha do tempo da inscrição de **todos os inscritos daquela fase**. Versão testada do Mapas: **7.8.21**.

Instalação: este repositório inteiro vira a pasta `src/plugins/Comunicados` do Mapas (repositório `yanlfs08/mapas-plugin-comunicados-em-etapas`; o `git clone` informa a pasta `Comunicados`, que é também o namespace). Para ativar, acrescente `'Comunicados' => ['namespace' => 'Comunicados']` em `config['plugins']` e limpe o cache. Não cria tabelas nem precisa de db-update.

## Requisitos funcionais (definidos pelo usuário)

- Nome na interface: **Comunicado** (substantivo masculino: "o comunicado", "publicado", "excluído"). O nome inicial, "Observações", foi rejeitado.
- Quem publica: o gestor do edital.
- Destinatários: **todos os inscritos da fase** (não há comunicado individual por inscrição).
- Conteúdo: mensagem com texto, imagem e anexo, em qualquer fase.
- Exibição: na linha do tempo da inscrição do candidato, dentro da fase.

## Arquitetura

- `Plugin.php`: toda a lógica. Nomes no código em inglês: `announcement` = comunicado.
- **Dados:** metadado privado `phaseAnnouncements` (JSON) na **primeira fase** do edital. Cada item tem `phaseType` + `phaseId`, iguais a `item.__objectType` + `item.id` da linha do tempo (`opportunity` ou `evaluationmethodconfiguration`). Ids no formato `com_<hex>`.
- **Arquivos:** `OpportunityFile` privados da primeira fase, grupos `phaseAnnouncementImage` e `phaseAnnouncementAttachment`. Só saem pelo endpoint `phaseAnnouncementFile`, que confere gestor ou inscrição na fase. O caminho no disco (`private-files/opportunity/{id}/arquivo`) não depende do grupo.
- **"Estar na fase":** ter inscrição com status >= 0 na oportunidade da fase (para avaliação, a oportunidade da configuração de avaliação). Ver `getPhaseTargets()`.
- **Endpoints:** `savePhaseAnnouncement` (multipart), `deletePhaseAnnouncement` (JSON), `phaseAnnouncementFile` (GET).
- **Telas:**
  - `template(opportunity.edit.tabs):end` → `jsObject.config.phaseAnnouncementsConfig` (gestor).
  - `component(opportunity-phase-config-<<data-collection|evaluation|results>>):bottom` → `phase-announcements-config` em cada fase. Nome do hook: `applyComponentHook('bottom')` sem segundo parâmetro gera `component(nome):bottom`.
  - `template(registration.<<view|single>>.single-tab):begin` → `jsObject.config.phaseAnnouncements`, já filtrado pelo número da inscrição. A ação de `/inscricao/{id}/` é `view`.
  - `component(opportunity-phases-timeline).item:end` → `phase-announcements-timeline`.

## Armadilhas já resolvidas (não reintroduzir)

1. **`$this->data['id']` é o id da URL** (a oportunidade). Por isso o campo do comunicado é `announcementId`, e o endpoint de salvar lê só `$_POST`.
2. **`setMetadata()` não serializa JSON.** Passar um array grava o texto `Array`. `saveAnnouncements()` usa `json_encode`, e `getAnnouncements()` decodifica (aceita string ou objeto).
3. **Não salvar a oportunidade inteira** (`$opportunity->save()`) para gravar os comunicados: dispara validações sem relação. Use `setMetadata()` + `saveMetadata(true)`.
4. **Não atribuir `$file->private`** (propriedade protegida). O `File::save()` marca como privado porque o grupo é registrado com `private = true`.
5. As regex de tipo em `FileGroup` usam o delimitador `#` (`preg_match("#$validation#i", ...)`).
6. O Vue remove espaço entre texto e `<template>`: use interpolação (`{{ ' — ' + titulo }}`) para separar.
7. Validar todos os arquivos **antes** de gravar qualquer um, para não deixar arquivos órfãos.

## Testes

`tests/api_test.py` com `tests/scenario.json` (modelo em `scenario.example.json`), login local por e-mail e senha. O esperado é 30/30. O teste trata o 503 do limite de requisições do nginx (5 req/s) com novas tentativas.

## Convenções

- Textos em pt-BR, com `i::__()` no PHP e `texts.php` + `Utils.getTexts()` no JS. Atenção ao gênero masculino de "comunicado".
- Commits em português. Atualize o `CHANGELOG.md` e a `VERSION` em `Plugin.php` a cada versão.
- Não alterar arquivos do núcleo do Mapas.
