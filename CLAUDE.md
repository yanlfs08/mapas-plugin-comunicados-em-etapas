# CLAUDE.md — Plugin de Observações em Etapas (Mapas Culturais)

Contexto para o Claude Code trabalhar neste repositório. Responda em português do Brasil.

## O que é

Plugin do Mapas Culturais (v7.8.x, tema BaseV2) para o gestor publicar observações (título, texto, imagem e anexos) em qualquer fase de um edital. Cada observação aparece na linha do tempo da inscrição de **todos os inscritos daquela fase**. Versão testada do Mapas: **7.8.21**.

Instalação: este repositório inteiro vira a pasta `src/plugins/Observacoes` do Mapas. Para ativar, acrescente `'Observacoes' => ['namespace' => 'Observacoes']` em `config['plugins']` e limpe o cache. Não cria tabelas nem precisa de db-update.

## Requisitos funcionais (definidos pelo usuário)

- Quem publica: o gestor do edital.
- Destinatários: **todos os inscritos da fase** (não há observação individual por inscrição).
- Conteúdo: mensagem com texto, imagem e anexo, em qualquer fase.
- Exibição: na linha do tempo da inscrição do candidato, dentro da fase.

## Arquitetura

- `Plugin.php`: toda a lógica.
- **Dados:** metadado privado `phaseObservations` (JSON) na **primeira fase** do edital. Cada item tem `phaseType` + `phaseId`, iguais a `item.__objectType` + `item.id` da linha do tempo (`opportunity` ou `evaluationmethodconfiguration`).
- **Arquivos:** `OpportunityFile` privados da primeira fase, grupos `phaseObservationImage` e `phaseObservationAttachment`. Só saem pelo endpoint `phaseObservationFile`, que confere gestor ou inscrição na fase.
- **"Estar na fase":** ter inscrição com status >= 0 na oportunidade da fase (para avaliação, a oportunidade da configuração de avaliação). Ver `getPhaseTargets()`.
- **Endpoints:** `savePhaseObservation` (multipart), `deletePhaseObservation` (JSON), `phaseObservationFile` (GET).
- **Telas:**
  - `template(opportunity.edit.tabs):end` → `jsObject.config.phaseObservationsConfig` (gestor).
  - `component(opportunity-phase-config-<<data-collection|evaluation|results>>):bottom` → `phase-observations-config` em cada fase. Nome do hook: `applyComponentHook('bottom')` sem segundo parâmetro gera `component(nome):bottom`.
  - `template(registration.<<view|single>>.single-tab):begin` → `jsObject.config.phaseObservations`, já filtrado pelo número da inscrição. A ação de `/inscricao/{id}/` é `view`.
  - `component(opportunity-phases-timeline).item:end` → `phase-observations-timeline`.

## Armadilhas já resolvidas (não reintroduzir)

1. **`$this->data['id']` é o id da URL** (a oportunidade). Por isso o campo da observação é `observationId`, e o endpoint de salvar lê só `$_POST`.
2. **`setMetadata()` não serializa JSON.** Passar um array grava o texto `Array`. `saveObservations()` usa `json_encode`, e `getObservations()` decodifica (aceita string ou objeto).
3. **Não salvar a oportunidade inteira** (`$opportunity->save()`) para gravar as observações: dispara validações sem relação. Use `setMetadata()` + `saveMetadata(true)`.
4. **Não atribuir `$file->private`** (propriedade protegida). O `File::save()` marca como privado porque o grupo é registrado com `private = true`.
5. As regex de tipo em `FileGroup` usam o delimitador `#` (`preg_match("#$validation#i", ...)`).
6. O Vue remove espaço entre texto e `<template>`: use interpolação (`{{ ' — ' + titulo }}`) para separar.
7. Validar todos os arquivos **antes** de gravar qualquer um, para não deixar arquivos órfãos.

## Testes

`tests/api_test.py` com `tests/scenario.json` (modelo em `scenario.example.json`), login local por e-mail e senha. O esperado é 30/30. O teste trata o 503 do limite de requisições do nginx (5 req/s) com novas tentativas.

## Convenções

- Textos em pt-BR, com `i::__()` no PHP e `texts.php` + `Utils.getTexts()` no JS.
- Commits em português. Atualize o `CHANGELOG.md` e a `VERSION` em `Plugin.php` a cada versão.
- Não alterar arquivos do núcleo do Mapas.
