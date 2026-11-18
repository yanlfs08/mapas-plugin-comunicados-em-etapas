# Changelog

## 1.0.0 - 2026-11-18

Primeira versão, testada no Mapas Culturais 7.8.21.

- Seção **Comunicados aos inscritos desta fase** no fim de cada fase da aba *Configuração de fases* (coleta de dados, avaliação e publicação final), para o gestor publicar, editar e excluir comunicados.
- Comunicado com título (opcional), mensagem em texto simples, uma imagem e até 5 anexos.
- Quadro **[Comunicado]** na linha do tempo da inscrição, dentro da fase a que pertence, para todos os inscritos daquela fase.
- Imagens e anexos guardados como arquivos privados e entregues por endpoint que confere a permissão (gestor ou inscrito na fase).
- Comunicados num metadado privado, fora da API pública.
- Validação de tamanho, quantidade e tipo real dos arquivos antes de gravar.
- Teste de API com 30 verificações.

### Nome

O primeiro commit do repositório chamava o plugin de **Observações** (`Observacoes`). Antes de qualquer instalação em produção, ele foi renomeado para **Comunicados**:

| Antes | Agora |
|---|---|
| pasta e namespace `Observacoes` | `Comunicados` |
| metadado `phaseObservations` | `phaseAnnouncements` |
| grupos de arquivo `phaseObservationImage` / `phaseObservationAttachment` | `phaseAnnouncementImage` / `phaseAnnouncementAttachment` |
| endpoints `savePhaseObservation`, `deletePhaseObservation`, `phaseObservationFile` | `savePhaseAnnouncement`, `deletePhaseAnnouncement`, `phaseAnnouncementFile` |
| campo `observationId` | `announcementId` |
| componentes `phase-observations-*` | `phase-announcements-*` |

Se você instalou a versão com o nome antigo e publicou algo, migre os dados no banco antes de ativar a nova (o caminho dos arquivos no disco não muda):

```sql
BEGIN;
INSERT INTO opportunity_meta (object_id, key, value)
  SELECT object_id, 'phaseAnnouncements', value FROM opportunity_meta WHERE key = 'phaseObservations';
UPDATE file SET grp = replace(grp, 'phaseObservation', 'phaseAnnouncement') WHERE grp LIKE 'phaseObservation%';
DELETE FROM opportunity_meta WHERE key = 'phaseObservations';
COMMIT;
```
