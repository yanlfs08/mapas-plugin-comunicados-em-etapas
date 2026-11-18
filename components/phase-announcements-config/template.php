<?php
/**
 * Seção "Comunicados aos inscritos desta fase" (gestor), no fim de cada fase
 * da aba Configuração de fases.
 *
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;

$this->import('
    mc-alert
    mc-confirm-button
    mc-icon
    mc-loading
');
?>
<div v-if="config" class="phase-announcements-config col-12">
    <div class="phase-announcements-config__header">
        <h4 class="bold"><?= i::__('Comunicados aos inscritos desta fase') ?></h4>
        <p class="phase-announcements__muted">
            <?= i::__('Cada comunicado aparece na linha do tempo da inscrição de todos os inscritos desta fase.') ?>
        </p>
    </div>

    <p v-if="!announcements.length && !formOpen" class="phase-announcements__muted"><?= i::__('Nenhum comunicado publicado nesta fase.') ?></p>

    <article v-for="obs in announcements" :key="obs.id" class="phase-announcements-config__item">
        <header class="phase-announcements-config__item-header">
            <div>
                <strong v-if="obs.title">{{ obs.title }}</strong>
                <span class="phase-announcements__muted">
                    <?= i::__('publicado em') ?> {{ formatDate(obs.createTimestamp) }}
                    <template v-if="obs.authorName"> <?= i::__('por') ?> {{ obs.authorName }}</template>
                    <template v-if="obs.updateTimestamp"> · <?= i::__('editado em') ?> {{ formatDate(obs.updateTimestamp) }}</template>
                </span>
            </div>
            <div class="phase-announcements-config__item-actions">
                <button class="button button--text button--sm" @click="editAnnouncement(obs)">
                    <mc-icon name="edit"></mc-icon> <?= i::__('Editar') ?>
                </button>
                <mc-confirm-button @confirm="remove(obs)">
                    <template #message>
                        <p><?= i::__('Excluir este comunicado? Ele some da linha do tempo dos inscritos, e a imagem e os anexos são apagados.') ?></p>
                    </template>
                    <template #button="modal">
                        <button class="button button--text button--sm phase-announcements-config__delete" @click="modal.open()">
                            <mc-icon name="trash"></mc-icon> <?= i::__('Excluir') ?>
                        </button>
                    </template>
                </mc-confirm-button>
            </div>
        </header>
        <p v-if="obs.text" class="phase-announcements__text">{{ obs.text }}</p>
        <a v-if="obs.image" :href="obs.image.url" target="_blank" class="phase-announcements__image-link">
            <img :src="obs.image.url" :alt="obs.image.name" class="phase-announcements__image phase-announcements__image--small">
        </a>
        <ul v-if="obs.attachments.length" class="phase-announcements__attachments">
            <li v-for="file in obs.attachments" :key="file.id">
                <a :href="file.url + '?download=1'" target="_blank"><mc-icon name="download" size="sm"></mc-icon> {{ file.name }}</a>
            </li>
        </ul>
    </article>

    <!-- formulário de novo comunicado / edição -->
    <div v-if="formOpen" class="phase-announcements-config__form">
        <h5 class="bold">
            <template v-if="form.id"><?= i::__('Editar comunicado') ?></template>
            <template v-else><?= i::__('Novo comunicado') ?></template>
        </h5>

        <div class="field">
            <label><?= i::__('Título (opcional)') ?></label>
            <input type="text" v-model="form.title" maxlength="255">
        </div>

        <div class="field">
            <label><?= i::__('Mensagem') ?></label>
            <textarea v-model="form.text" rows="5" :maxlength="config.maxTextLength"></textarea>
        </div>

        <div class="field">
            <label><?= i::__('Imagem (opcional)') ?></label>
            <div v-if="form.currentImage && !form.removeImage" class="phase-announcements-config__current">
                <img :src="form.currentImage.url" :alt="form.currentImage.name" class="phase-announcements__image phase-announcements__image--small">
                <button type="button" class="button button--text button--sm" @click="form.removeImage = true"><?= i::__('Remover imagem') ?></button>
            </div>
            <input type="file" ref="image" :accept="config.imageAccept">
            <small class="phase-announcements__muted">
                <?= i::__('JPG, PNG, GIF ou WEBP, até') ?> {{ config.maxFileSizeMb }} MB.
                <template v-if="form.currentImage && !form.removeImage"><?= i::__('Enviar outra imagem substitui a atual.') ?></template>
            </small>
        </div>

        <div class="field">
            <label><?= i::__('Anexos (opcional)') ?></label>
            <ul v-if="form.currentAttachments.length" class="phase-announcements-config__current-files">
                <li v-for="file in form.currentAttachments" :key="file.id" :class="{ 'is-removed': form.removeAttachmentIds.includes(file.id) }">
                    {{ file.name }}
                    <button type="button" class="button button--text button--sm" @click="toggleRemoveAttachment(file)">
                        <template v-if="form.removeAttachmentIds.includes(file.id)"><?= i::__('Manter') ?></template>
                        <template v-else><?= i::__('Remover') ?></template>
                    </button>
                </li>
            </ul>
            <input type="file" ref="attachments" multiple :accept="config.attachmentAccept">
            <small class="phase-announcements__muted">
                <?= i::__('PDF, imagens, documentos de texto, planilhas, CSV ou ZIP, até') ?> {{ config.maxFileSizeMb }} MB
                <?= i::__('cada. Você ainda pode enviar') ?> {{ attachmentsLeft }} <?= i::__('anexo(s).') ?>
            </small>
        </div>

        <div class="phase-announcements-config__actions">
            <button class="button button--primary" :disabled="saving" @click="save()">
                <template v-if="form.id"><?= i::__('Salvar alterações') ?></template>
                <template v-else><?= i::__('Publicar comunicado') ?></template>
            </button>
            <button class="button button--text" :disabled="saving" @click="cancel()"><?= i::__('Cancelar') ?></button>
            <mc-loading :condition="saving"><?= i::__('enviando') ?></mc-loading>
        </div>
    </div>

    <button v-if="!formOpen" class="button button--primary-outline button--icon" @click="newAnnouncement()">
        <mc-icon name="add"></mc-icon> <?= i::__('Adicionar comunicado') ?>
    </button>
</div>
