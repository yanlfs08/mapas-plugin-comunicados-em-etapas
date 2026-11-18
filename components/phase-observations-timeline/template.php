<?php
/**
 * Quadros "[Observação]" na linha do tempo da inscrição (candidato), no mesmo formato
 * do quadro "[Recurso]" do Mapas.
 *
 * @var MapasCulturais\App $app
 * @var MapasCulturais\Themes\BaseV2\Theme $this
 */

use MapasCulturais\i;

$this->import('
    mc-icon
');
?>
<template v-if="observations.length">
    <div v-for="obs in observations" :key="obs.id" class="opportunity-phases-timeline__request-appeal__box phase-observations-timeline">
        <div class="item__dot-appeal-phase"> <span class="dot"></span> </div>
        <div class="item__content">
            <div class="item__content--title">
                <?= i::__('[Observação]') ?>{{ obs.title ? ' — ' + obs.title : '' }}
            </div>
            <div class="item__content--description">
                <h5 class="semibold"><?= i::__('publicada em') ?> {{ formatDate(obs.createTimestamp) }}</h5>
            </div>

            <div class="opportunity-phases-timeline__box phase-observations-timeline__box">
                <p v-if="obs.text" class="phase-observations__text">{{ obs.text }}</p>

                <a v-if="obs.image" :href="obs.image.url" target="_blank" class="phase-observations__image-link">
                    <img :src="obs.image.url" :alt="obs.image.name" class="phase-observations__image" loading="lazy">
                </a>

                <ul v-if="obs.attachments.length" class="phase-observations__attachments">
                    <li v-for="file in obs.attachments" :key="file.id">
                        <a :href="file.url + '?download=1'" target="_blank">
                            <mc-icon name="download" size="sm"></mc-icon> {{ file.name }}
                        </a>
                    </li>
                </ul>
            </div>
        </div>
    </div>
</template>
