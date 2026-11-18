/**
 * Quadros "[Comunicado]" dentro de um item da linha do tempo da inscrição.
 * Os dados vêm de $MAPAS.config.phaseAnnouncements: só os comunicados das fases em que a
 * inscrição da página está (o servidor já filtrou).
 */
app.component('phase-announcements-timeline', {
    template: $TEMPLATES['phase-announcements-timeline'],

    props: {
        // item da linha do tempo: oportunidade (coleta/resultado) ou configuração de avaliação
        phase: {
            type: Object,
            required: true,
        },
    },

    computed: {
        announcements() {
            const all = $MAPAS.config.phaseAnnouncements || [];
            return all.filter((obs) => obs.phaseType === this.phase.__objectType && String(obs.phaseId) === String(this.phase.id));
        },
    },

    methods: {
        formatDate(value) {
            if (!value) {
                return '';
            }
            const date = new McDate(value);
            return `${date.date('2-digit year')} ${date.time('2-digit')}`;
        },
    },
});
