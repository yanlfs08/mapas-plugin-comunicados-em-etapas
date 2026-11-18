<?php

namespace Comunicados;

use DateTime;
use MapasCulturais\App;
use MapasCulturais\Definitions\FileGroup;
use MapasCulturais\Entities\EvaluationMethodConfiguration;
use MapasCulturais\Entities\Opportunity;
use MapasCulturais\Entities\OpportunityFile;
use MapasCulturais\Entities\Registration;
use MapasCulturais\i;

/**
 * Plugin de comunicados por fase.
 *
 * O gestor publica comunicados (título, texto, imagem e anexos) em qualquer fase do edital.
 * Cada comunicado aparece na linha do tempo da inscrição de todos os inscritos daquela fase.
 *
 * Modelo de dados (sem tabelas novas):
 *
 * - Os comunicados do edital ficam no metadado privado `phaseAnnouncements` (JSON) da primeira
 *   fase (a oportunidade principal). Cada item guarda a fase a que pertence:
 *   `phaseType` ("opportunity" ou "evaluationmethodconfiguration") e `phaseId`, os mesmos
 *   identificadores dos itens da linha do tempo (`item.__objectType` e `item.id`).
 * - Imagens e anexos são arquivos PRIVADOS da primeira fase (grupos `phaseAnnouncementImage` e
 *   `phaseAnnouncementAttachment`), baixados pelo endpoint `phaseAnnouncementFile`, que confere se o
 *   usuário é gestor ou tem inscrição na fase do comunicado.
 */
class Plugin extends \MapasCulturais\Plugin
{
    const VERSION = '1.0.0';

    /** Faixa de versões do Mapas Culturais em que o plugin foi testado */
    const TESTED_MAPAS_VERSIONS = '7.8';

    const META_KEY = 'phaseAnnouncements';
    const IMAGE_GROUP = 'phaseAnnouncementImage';
    const ATTACHMENT_GROUP = 'phaseAnnouncementAttachment';

    const PHASE_OPPORTUNITY = 'opportunity';
    const PHASE_EVALUATION = 'evaluationmethodconfiguration';

    function __construct(array $config = [])
    {
        $config += [
            // tamanho máximo de cada arquivo, em MB (o PHP/nginx também limitam o envio)
            'maxFileSizeMb' => 10,

            // quantidade máxima de anexos por comunicado
            'maxAttachments' => 5,

            // tamanho máximo do texto
            'maxTextLength' => 10000,

            // tipos aceitos para a imagem
            'imageMimeTypes' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],

            // tipos aceitos para os anexos
            'attachmentMimeTypes' => [
                'application/pdf',
                'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'application/vnd.oasis.opendocument.text',
                'application/vnd.oasis.opendocument.spreadsheet',
                'application/zip', 'application/x-zip-compressed',
                'text/plain', 'text/csv',
            ],
        ];

        parent::__construct($config);
    }

    /* ---------------------------------------------------------------------
     * Inicialização
     * ------------------------------------------------------------------- */

    function _init()
    {
        $app = App::i();

        $this->checkMapasVersion();

        $app->hook('app.init:after', function () use ($app) {
            $app->view->enqueueStyle('app-v2', 'comunicados', 'css/comunicados.css');
        });

        $this->initEndpoints();
        $this->initTemplateHooks();
    }

    function register()
    {
        $app = App::i();

        // lista de comunicados do edital (privado: não aparece na API pública)
        $this->registerOpportunityMetadata(self::META_KEY, [
            'label' => i::__('Comunicados das fases'),
            'type' => 'json',
            'private' => true,
        ]);

        // arquivos privados: só saem pelo endpoint phaseAnnouncementFile, que confere a permissão
        $app->registerFileGroup('opportunity', new FileGroup(
            self::IMAGE_GROUP,
            array_map(fn ($m) => '^' . preg_quote($m, '#') . '$', $this->config['imageMimeTypes']),
            i::__('O arquivo enviado não é uma imagem válida.'),
            false,
            null,
            true
        ));

        $app->registerFileGroup('opportunity', new FileGroup(
            self::ATTACHMENT_GROUP,
            [],
            '',
            false,
            null,
            true
        ));
    }

    /**
     * Registra no log quando o Mapas está fora da faixa de versões testada.
     */
    function checkMapasVersion(): void
    {
        $version_file = PROTECTED_PATH . 'version.txt';
        if (!file_exists($version_file)) {
            return;
        }

        $version = trim((string) file_get_contents($version_file));
        if ($version && !str_starts_with($version, self::TESTED_MAPAS_VERSIONS . '.')) {
            App::i()->log->warning(sprintf(
                'Plugin Comunicados %s foi testado com o Mapas Culturais %s.x; versão instalada: %s',
                self::VERSION,
                self::TESTED_MAPAS_VERSIONS,
                $version
            ));
        }
    }

    /* ---------------------------------------------------------------------
     * Leitura e gravação dos comunicados
     * ------------------------------------------------------------------- */

    /**
     * Comunicados do edital (lidas sem passar pela checagem de metadado privado;
     * quem chama decide o que pode ser mostrado).
     */
    function getAnnouncements(Opportunity $opportunity): array
    {
        $first_phase = $opportunity->firstPhase;
        $value = $first_phase->getMetadata(self::META_KEY);

        if (is_string($value)) {
            $value = json_decode($value, true);
        } else {
            $value = json_decode(json_encode($value), true);
        }

        return is_array($value) ? array_values(array_filter($value, 'is_array')) : [];
    }

    /**
     * Grava a lista de comunicados sem salvar a oportunidade inteira
     * (salvar a oportunidade dispararia validações que não têm relação com os comunicados).
     */
    function saveAnnouncements(Opportunity $opportunity, array $announcements): void
    {
        $first_phase = $opportunity->firstPhase;
        // setMetadata() não serializa: grava o JSON já pronto (getAnnouncements() decodifica)
        $first_phase->setMetadata(self::META_KEY, json_encode(array_values($announcements), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        $first_phase->saveMetadata(true);
    }

    /**
     * Fases do edital às quais um comunicado pode pertencer, no formato dos itens da linha do tempo
     *
     * @return array<string, Opportunity> chave "tipo:id" => oportunidade da fase
     */
    function getPhaseTargets(Opportunity $opportunity): array
    {
        $first_phase = $opportunity->firstPhase;
        $targets = [];

        foreach ((array) $first_phase->allPhases as $phase) {
            if (!$phase instanceof Opportunity) {
                continue;
            }

            $targets[self::PHASE_OPPORTUNITY . ':' . $phase->id] = $phase;

            if ($emc = $phase->evaluationMethodConfiguration) {
                $targets[self::PHASE_EVALUATION . ':' . $emc->id] = $phase;
            }
        }

        return $targets;
    }

    /**
     * Oportunidade cujas inscrições "estão" na fase indicada (a da avaliação, no caso de avaliação)
     */
    function getPhaseOpportunity(Opportunity $opportunity, string $type, int $id): ?Opportunity
    {
        return $this->getPhaseTargets($opportunity)["{$type}:{$id}"] ?? null;
    }

    /**
     * Nome da fase, como aparece na linha do tempo
     */
    function getPhaseLabel(Opportunity $opportunity, string $type, int $id): string
    {
        $app = App::i();

        if ($type === self::PHASE_EVALUATION) {
            $emc = $app->repo('EvaluationMethodConfiguration')->find($id);
            return (string) ($emc?->name ?: $emc?->opportunity?->name);
        }

        $phase = $app->repo('Opportunity')->find($id);
        if ($phase?->isFirstPhase) {
            return i::__('Fase de inscrições');
        }

        return (string) $phase?->name;
    }

    /**
     * O usuário tem inscrição (não excluída) na fase indicada?
     */
    function userHasRegistrationInPhase(Opportunity $phase_opportunity, $user = null): bool
    {
        $app = App::i();
        $user = $user ?: $app->user;

        if (!$user || $user->is('guest')) {
            return false;
        }

        $query = $app->em->createQuery("
            SELECT COUNT(r.id)
            FROM MapasCulturais\Entities\Registration r
            JOIN r.owner a
            WHERE r.opportunity = :phase AND r.status >= 0 AND a.user = :user");
        $query->setParameters(['phase' => $phase_opportunity, 'user' => $user]);

        return (int) $query->getSingleScalarResult() > 0;
    }

    /**
     * Número de inscrição presente na fase indicada?
     */
    function registrationNumberIsInPhase(Opportunity $phase_opportunity, string $number): bool
    {
        $app = App::i();
        $query = $app->em->createQuery("
            SELECT COUNT(r.id)
            FROM MapasCulturais\Entities\Registration r
            WHERE r.opportunity = :phase AND r.number = :number AND r.status >= 0");
        $query->setParameters(['phase' => $phase_opportunity, 'number' => $number]);

        return (int) $query->getSingleScalarResult() > 0;
    }

    /**
     * Comunicado no formato do front-end, com as URLs dos arquivos
     */
    function serializeAnnouncement(Opportunity $opportunity, array $announcement): array
    {
        $app = App::i();
        $first_phase = $opportunity->firstPhase;

        $file = function ($id) use ($app, $first_phase) {
            $file = $id ? $app->repo('OpportunityFile')->find((int) $id) : null;
            if (!$file || !$file->owner?->equals($first_phase)) {
                return null;
            }
            return [
                'id' => $file->id,
                'name' => $file->name,
                'mimeType' => $file->mimeType,
                'isImage' => str_starts_with((string) $file->mimeType, 'image/'),
                'url' => $this->getFileUrl($first_phase, $file),
            ];
        };

        return [
            'id' => $announcement['id'],
            'phaseType' => $announcement['phaseType'],
            'phaseId' => (int) $announcement['phaseId'],
            'phaseLabel' => $this->getPhaseLabel($first_phase, $announcement['phaseType'], (int) $announcement['phaseId']),
            'title' => (string) ($announcement['title'] ?? ''),
            'text' => (string) ($announcement['text'] ?? ''),
            'image' => $file($announcement['imageId'] ?? null),
            'attachments' => array_values(array_filter(array_map($file, (array) ($announcement['attachmentIds'] ?? [])))),
            'authorName' => (string) ($announcement['authorName'] ?? ''),
            'createTimestamp' => $announcement['createTimestamp'] ?? null,
            'updateTimestamp' => $announcement['updateTimestamp'] ?? null,
        ];
    }

    /**
     * Comunicados visíveis numa inscrição: as das fases em que o número da inscrição está presente
     */
    function getAnnouncementsForRegistration(Registration $registration): array
    {
        $opportunity = $registration->opportunity->firstPhase;
        $result = [];

        foreach ($this->getAnnouncements($opportunity) as $announcement) {
            $phase = $this->getPhaseOpportunity($opportunity, (string) $announcement['phaseType'], (int) $announcement['phaseId']);
            if ($phase && $this->registrationNumberIsInPhase($phase, (string) $registration->number)) {
                $result[] = $this->serializeAnnouncement($opportunity, $announcement);
            }
        }

        return $this->sortAnnouncements($result);
    }

    /**
     * Mais recentes primeiro
     */
    function sortAnnouncements(array $announcements): array
    {
        usort($announcements, fn ($a, $b) => strcmp((string) $b['createTimestamp'], (string) $a['createTimestamp']));
        return $announcements;
    }

    function getFileUrl(Opportunity $first_phase, $file): string
    {
        return App::i()->createUrl('opportunity', 'phaseAnnouncementFile', [$first_phase->id, 'file' => $file->id]);
    }

    /* ---------------------------------------------------------------------
     * Arquivos
     * ------------------------------------------------------------------- */

    /**
     * Normaliza $_FILES[campo] (um ou vários arquivos) numa lista de arquivos enviados
     */
    static function uploadedFiles(string $field): array
    {
        $raw = $_FILES[$field] ?? null;
        if (!$raw) {
            return [];
        }

        if (!is_array($raw['name'])) {
            return $raw['error'] === UPLOAD_ERR_NO_FILE ? [] : [$raw];
        }

        $files = [];
        foreach (array_keys($raw['name']) as $i) {
            if ($raw['error'][$i] === UPLOAD_ERR_NO_FILE) {
                continue;
            }
            $files[] = [
                'name' => $raw['name'][$i],
                'type' => $raw['type'][$i],
                'tmp_name' => $raw['tmp_name'][$i],
                'error' => $raw['error'][$i],
                'size' => $raw['size'][$i],
            ];
        }

        return $files;
    }

    /**
     * Valida um arquivo enviado. Devolve null quando está tudo certo, ou a mensagem de erro.
     */
    function validateUpload(array $upload, array $allowed_mime_types): ?string
    {
        if ($upload['error'] === UPLOAD_ERR_INI_SIZE || $upload['error'] === UPLOAD_ERR_FORM_SIZE) {
            return sprintf(i::__('O arquivo "%s" é maior que o limite do servidor.'), $upload['name']);
        }

        if ($upload['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($upload['tmp_name'])) {
            return sprintf(i::__('Não foi possível receber o arquivo "%s".'), $upload['name']);
        }

        if ($upload['size'] > $this->config['maxFileSizeMb'] * 1024 * 1024) {
            return sprintf(i::__('O arquivo "%s" passa de %d MB.'), $upload['name'], $this->config['maxFileSizeMb']);
        }

        $mime = mime_content_type($upload['tmp_name']) ?: '';
        if (!in_array($mime, $allowed_mime_types, true)) {
            return sprintf(i::__('O tipo do arquivo "%s" não é aceito.'), $upload['name']);
        }

        return null;
    }

    /**
     * Grava um arquivo enviado como arquivo privado da primeira fase
     */
    function storeUpload(Opportunity $first_phase, array $upload, string $group): OpportunityFile
    {
        $file = new OpportunityFile($upload);
        $file->owner = $first_phase;
        $file->group = $group;
        // o grupo é registrado como privado: o File::save() grava o arquivo na pasta privada
        $file->save(true);

        return $file;
    }

    function deleteFile(Opportunity $first_phase, $id): void
    {
        $file = $id ? App::i()->repo('OpportunityFile')->find((int) $id) : null;
        if ($file && $file->owner?->equals($first_phase) && in_array($file->group, [self::IMAGE_GROUP, self::ATTACHMENT_GROUP], true)) {
            $file->delete(true);
        }
    }

    /* ---------------------------------------------------------------------
     * Endpoints
     * ------------------------------------------------------------------- */

    function initEndpoints(): void
    {
        $app = App::i();
        $self = $this;

        /**
         * Cria ou atualiza um comunicado (multipart/form-data)
         * POST /opportunity/savePhaseAnnouncement/{id de qualquer fase do edital}
         * campos: announcementId (para editar), phaseType, phaseId, title, text, removeImage (1),
         *         removeAttachmentIds[] ; arquivos: image, attachments[]
         */
        $app->hook('POST(opportunity.savePhaseAnnouncement)', function () use ($app, $self) {
            /** @var \MapasCulturais\Controllers\Opportunity $this */
            $this->requireAuthentication();

            $opportunity = $this->requestedEntity;
            if (!$opportunity instanceof Opportunity) {
                $app->pass();
            }

            $first_phase = $opportunity->firstPhase;
            $first_phase->checkPermission('@control');

            // só o corpo do formulário: $this->data também traz o "id" da URL (a oportunidade)
            $data = $_POST;
            $phase_type = (string) ($data['phaseType'] ?? '');
            $phase_id = (int) ($data['phaseId'] ?? 0);
            $title = trim((string) ($data['title'] ?? ''));
            $text = trim(str_replace("\r\n", "\n", (string) ($data['text'] ?? '')));

            $announcements = $self->getAnnouncements($first_phase);
            $editing_id = (string) ($data['announcementId'] ?? '');
            $index = null;
            if ($editing_id !== '') {
                foreach ($announcements as $i => $obs) {
                    if ($obs['id'] === $editing_id) {
                        $index = $i;
                    }
                }
                if ($index === null) {
                    $this->errorJson(i::__('Comunicado não encontrado.'), 404);
                }
                // ao editar, a fase não muda
                $phase_type = $announcements[$index]['phaseType'];
                $phase_id = (int) $announcements[$index]['phaseId'];
            }

            if (!$self->getPhaseOpportunity($first_phase, $phase_type, $phase_id)) {
                $this->errorJson(i::__('A fase informada não pertence a esta oportunidade.'), 400);
            }

            if (mb_strlen($title) > 255) {
                $this->errorJson(['title' => [i::__('O título pode ter no máximo 255 caracteres.')]], 400);
            }

            if (mb_strlen($text) > $self->config['maxTextLength']) {
                $this->errorJson(['text' => [sprintf(i::__('O texto pode ter no máximo %d caracteres.'), $self->config['maxTextLength'])]], 400);
            }

            // valida os arquivos antes de gravar qualquer coisa
            $image_uploads = self::uploadedFiles('image');
            $attachment_uploads = self::uploadedFiles('attachments');

            if (count($image_uploads) > 1) {
                $this->errorJson(['image' => [i::__('Envie só uma imagem.')]], 400);
            }
            foreach ($image_uploads as $upload) {
                if ($error = $self->validateUpload($upload, $self->config['imageMimeTypes'])) {
                    $this->errorJson(['image' => [$error]], 400);
                }
            }
            foreach ($attachment_uploads as $upload) {
                if ($error = $self->validateUpload($upload, $self->config['attachmentMimeTypes'])) {
                    $this->errorJson(['attachments' => [$error]], 400);
                }
            }

            $current = $index !== null ? $announcements[$index] : [
                'id' => 'com_' . bin2hex(random_bytes(8)),
                'phaseType' => $phase_type,
                'phaseId' => $phase_id,
                'imageId' => null,
                'attachmentIds' => [],
                'createTimestamp' => (new DateTime())->format('Y-m-d H:i:s'),
                'authorName' => $app->user->profile?->name,
            ];

            $remove_ids = array_map('intval', (array) ($data['removeAttachmentIds'] ?? []));
            $kept_attachments = array_values(array_filter(
                array_map('intval', (array) ($current['attachmentIds'] ?? [])),
                fn ($id) => !in_array($id, $remove_ids, true)
            ));

            if (count($kept_attachments) + count($attachment_uploads) > $self->config['maxAttachments']) {
                $this->errorJson(['attachments' => [sprintf(i::__('Cada comunicado pode ter no máximo %d anexos.'), $self->config['maxAttachments'])]], 400);
            }

            $will_have_image = $image_uploads || (!empty($current['imageId']) && empty($data['removeImage']));
            if ($title === '' && $text === '' && !$will_have_image && !$kept_attachments && !$attachment_uploads) {
                $this->errorJson(['text' => [i::__('Escreva um texto ou envie uma imagem ou um anexo.')]], 400);
            }

            // grava os arquivos novos e remove os retirados
            if ($image_uploads || !empty($data['removeImage'])) {
                $self->deleteFile($first_phase, $current['imageId'] ?? null);
                $current['imageId'] = null;
            }
            foreach ($image_uploads as $upload) {
                $current['imageId'] = $self->storeUpload($first_phase, $upload, self::IMAGE_GROUP)->id;
            }

            foreach ($remove_ids as $id) {
                if (in_array($id, (array) ($current['attachmentIds'] ?? []), false)) {
                    $self->deleteFile($first_phase, $id);
                }
            }
            foreach ($attachment_uploads as $upload) {
                $kept_attachments[] = $self->storeUpload($first_phase, $upload, self::ATTACHMENT_GROUP)->id;
            }

            $current['title'] = $title;
            $current['text'] = $text;
            $current['attachmentIds'] = $kept_attachments;
            if ($index !== null) {
                $current['updateTimestamp'] = (new DateTime())->format('Y-m-d H:i:s');
                $announcements[$index] = $current;
            } else {
                $announcements[] = $current;
            }

            $self->saveAnnouncements($first_phase, $announcements);

            $this->json($self->serializeAnnouncement($first_phase, $current));
        });

        /**
         * Exclui um comunicado (e os seus arquivos)
         * POST /opportunity/deletePhaseAnnouncement/{id de qualquer fase do edital}   dados: announcementId
         */
        $app->hook('POST(opportunity.deletePhaseAnnouncement)', function () use ($app, $self) {
            /** @var \MapasCulturais\Controllers\Opportunity $this */
            $this->requireAuthentication();

            $opportunity = $this->requestedEntity;
            if (!$opportunity instanceof Opportunity) {
                $app->pass();
            }

            $first_phase = $opportunity->firstPhase;
            $first_phase->checkPermission('@control');

            // "announcementId" vem no corpo (JSON ou formulário); "id" é o da URL (a oportunidade)
            $id = (string) ($this->postData['announcementId'] ?? $_POST['announcementId'] ?? '');
            $announcements = $self->getAnnouncements($first_phase);
            $kept = [];
            $found = null;
            foreach ($announcements as $obs) {
                if ($obs['id'] === $id) {
                    $found = $obs;
                } else {
                    $kept[] = $obs;
                }
            }

            if (!$found) {
                $this->errorJson(i::__('Comunicado não encontrado.'), 404);
            }

            $self->deleteFile($first_phase, $found['imageId'] ?? null);
            foreach ((array) ($found['attachmentIds'] ?? []) as $file_id) {
                $self->deleteFile($first_phase, $file_id);
            }

            $self->saveAnnouncements($first_phase, $kept);
            $this->json(true);
        });

        /**
         * Baixa (ou mostra, se for imagem) um arquivo de comunicado
         * GET /opportunity/phaseAnnouncementFile/{id da primeira fase}/file:{id do arquivo}
         * Pode: gestor do edital, ou quem tem inscrição na fase do comunicado.
         */
        $app->hook('GET(opportunity.phaseAnnouncementFile)', function () use ($app, $self) {
            /** @var \MapasCulturais\Controllers\Opportunity $this */
            $this->requireAuthentication();

            $opportunity = $this->requestedEntity;
            if (!$opportunity instanceof Opportunity) {
                $app->pass();
            }

            $first_phase = $opportunity->firstPhase;
            $file_id = (int) ($this->data['file'] ?? 0);
            $file = $app->repo('OpportunityFile')->find($file_id);

            $announcement = null;
            foreach ($self->getAnnouncements($first_phase) as $obs) {
                $ids = array_map('intval', array_merge([(int) ($obs['imageId'] ?? 0)], (array) ($obs['attachmentIds'] ?? [])));
                if (in_array($file_id, $ids, true)) {
                    $announcement = $obs;
                }
            }

            $allowed = $file && $announcement && $file->owner?->equals($first_phase);
            if ($allowed && !$first_phase->canUser('@control')) {
                $phase = $self->getPhaseOpportunity($first_phase, (string) $announcement['phaseType'], (int) $announcement['phaseId']);
                $allowed = $phase && $self->userHasRegistrationInPhase($phase);
            }

            if (!$allowed) {
                $this->errorJson(i::__('Você não tem permissão para acessar este arquivo.'), 403);
            }

            $path = $file->getPath();
            if (!file_exists($path)) {
                $app->pass();
            }

            $mime = mime_content_type($path) ?: 'application/octet-stream';
            $inline = str_starts_with($mime, 'image/') && empty($this->data['download']);

            header('Content-Type: ' . $mime);
            header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . str_replace('"', '', $file->name) . '"');
            header('X-Content-Type-Options: nosniff');
            header('Cache-Control: private, max-age=300');
            header('Content-Length: ' . filesize($path));
            readfile($path);
            exit;
        });
    }

    /* ---------------------------------------------------------------------
     * Telas
     * ------------------------------------------------------------------- */

    function initTemplateHooks(): void
    {
        $app = App::i();
        $self = $this;

        // gestor: dados de todas os comunicados do edital, para a Configuração de fases
        $app->hook('template(opportunity.edit.tabs):end', function () use ($self) {
            /** @var \MapasCulturais\Themes\BaseV2\Theme $this */
            $opportunity = $this->controller->requestedEntity;
            if (!$opportunity instanceof Opportunity || !$opportunity->canUser('@control')) {
                return;
            }

            $first_phase = $opportunity->firstPhase;
            $this->jsObject['config']['phaseAnnouncementsConfig'] = [
                'opportunityId' => $first_phase->id,
                'maxFileSizeMb' => $self->config['maxFileSizeMb'],
                'maxAttachments' => $self->config['maxAttachments'],
                'maxTextLength' => $self->config['maxTextLength'],
                'imageAccept' => implode(',', $self->config['imageMimeTypes']),
                'attachmentAccept' => implode(',', $self->config['attachmentMimeTypes']),
                'announcements' => $self->sortAnnouncements(array_map(
                    fn ($obs) => $self->serializeAnnouncement($first_phase, $obs),
                    $self->getAnnouncements($first_phase)
                )),
            ];
            $this->import('phase-announcements-config');
        });

        // gestor: seção "Comunicados" no fim de cada fase da Configuração de fases
        foreach (['opportunity-phase-config-data-collection', 'opportunity-phase-config-evaluation', 'opportunity-phase-config-results'] as $component) {
            $app->hook("component({$component}):bottom", function () {
                /** @var \MapasCulturais\Themes\BaseV2\Theme $this */
                $this->import('phase-announcements-config');
                echo '<phase-announcements-config :phase="phase"></phase-announcements-config>';
            });
        }

        // candidato: comunicados das fases em que a inscrição da página está
        $app->hook('template(registration.<<view|single>>.single-tab):begin', function () use ($self) {
            /** @var \MapasCulturais\Themes\BaseV2\Theme $this */
            $registration = $this->controller->requestedEntity;
            if (!$registration instanceof Registration || !$registration->canUser('view')) {
                return;
            }

            $this->jsObject['config']['phaseAnnouncements'] = $self->getAnnouncementsForRegistration($registration);
        });

        // candidato: quadros [Comunicado] no fim de cada item da linha do tempo
        $app->hook('component(opportunity-phases-timeline).item:end', function () {
            /** @var \MapasCulturais\Themes\BaseV2\Theme $this */
            $this->import('phase-announcements-timeline');
            echo '<phase-announcements-timeline :phase="item"></phase-announcements-timeline>';
        });
    }
}
