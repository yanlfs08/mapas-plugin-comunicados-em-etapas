/**
 * Quadros "[Observação]" dentro de um item da linha do tempo da inscrição.
 * Os dados vêm de $MAPAS.config.phaseObservations: só as observações das fases em que a
 * inscrição da página está (o servidor já filtrou).
 */
app.component('phase-observations-timeline', {
    template: $TEMPLATES['phase-observations-timeline'],

    props: {
        // item da linha do tempo: oportunidade (coleta/resultado) ou configuração de avaliação
        phase: {
            type: Object,
            required: true,
        },
    },

    computed: {
        observations() {
            const all = $MAPAS.config.phaseObservations || [];
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
