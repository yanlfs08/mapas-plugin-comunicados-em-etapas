/**
 * Seção "Comunicados aos inscritos desta fase", no fim de cada fase da Configuração de fases.
 * Os dados vêm de $MAPAS.config.phaseAnnouncementsConfig (todas os comunicados do edital).
 * O envio é multipart (FormData), por causa da imagem e dos anexos.
 */
app.component('phase-announcements-config', {
    template: $TEMPLATES['phase-announcements-config'],

    props: {
        // fase da Configuração de fases: oportunidade (coleta/resultado) ou configuração de avaliação
        phase: {
            type: Object,
            required: true,
        },
    },

    setup() {
        const text = Utils.getTexts('phase-announcements-config');
        const messages = useMessages();
        return { text, messages };
    },

    data() {
        const config = $MAPAS.config.phaseAnnouncementsConfig || null;
        return {
            config,
            announcements: config ? config.announcements.filter((obs) => this.belongs(obs)) : [],
            formOpen: false,
            saving: false,
            form: this.emptyForm(),
        };
    },

    computed: {
        phaseType() {
            if (this.phase.__objectType) {
                return this.phase.__objectType;
            }
            return this.phase.evaluationFrom !== undefined ? 'evaluationmethodconfiguration' : 'opportunity';
        },

        attachmentsLeft() {
            const kept = this.form.currentAttachments.filter((file) => !this.form.removeAttachmentIds.includes(file.id)).length;
            return this.config.maxAttachments - kept;
        },
    },

    methods: {
        belongs(obs) {
            const type = this.phase.__objectType || (this.phase.evaluationFrom !== undefined ? 'evaluationmethodconfiguration' : 'opportunity');
            return obs.phaseType === type && String(obs.phaseId) === String(this.phase.id);
        },

        emptyForm() {
            return {
                id: null,
                title: '',
                text: '',
                currentImage: null,
                removeImage: false,
                currentAttachments: [],
                removeAttachmentIds: [],
            };
        },

        newAnnouncement() {
            this.form = this.emptyForm();
            this.formOpen = true;
            this.$nextTick(() => this.resetFileInputs());
        },

        editAnnouncement(obs) {
            this.form = {
                id: obs.id,
                title: obs.title,
                text: obs.text,
                currentImage: obs.image,
                removeImage: false,
                currentAttachments: [...obs.attachments],
                removeAttachmentIds: [],
            };
            this.formOpen = true;
            this.$nextTick(() => this.resetFileInputs());
        },

        cancel() {
            this.formOpen = false;
            this.form = this.emptyForm();
        },

        resetFileInputs() {
            if (this.$refs.image) this.$refs.image.value = '';
            if (this.$refs.attachments) this.$refs.attachments.value = '';
        },

        toggleRemoveAttachment(file) {
            const ids = this.form.removeAttachmentIds;
            const i = ids.indexOf(file.id);
            i >= 0 ? ids.splice(i, 1) : ids.push(file.id);
        },

        async save() {
            const data = new FormData();
            if (this.form.id) {
                data.append('announcementId', this.form.id);
            }
            data.append('phaseType', this.phaseType);
            data.append('phaseId', this.phase.id);
            data.append('title', this.form.title);
            data.append('text', this.form.text);
            if (this.form.removeImage) {
                data.append('removeImage', '1');
            }
            this.form.removeAttachmentIds.forEach((id) => data.append('removeAttachmentIds[]', id));

            const image = this.$refs.image?.files?.[0];
            if (image) {
                data.append('image', image);
            }
            const attachments = Array.from(this.$refs.attachments?.files || []);
            if (attachments.length > this.attachmentsLeft) {
                this.messages.error(this.text('Anexos demais').replace('%d', this.config.maxAttachments));
                return;
            }
            attachments.forEach((file) => data.append('attachments[]', file));

            const tooBig = [image, ...attachments].filter(Boolean).find((file) => file.size > this.config.maxFileSizeMb * 1024 * 1024);
            if (tooBig) {
                this.messages.error(this.text('Arquivo grande demais').replace('%s', tooBig.name).replace('%d', this.config.maxFileSizeMb));
                return;
            }

            this.saving = true;
            try {
                const url = Utils.createUrl('opportunity', 'savePhaseAnnouncement', [this.config.opportunityId]);
                const response = await fetch(url, { method: 'POST', body: data, headers: { Accept: 'application/json' } });
                const body = await response.json().catch(() => null);

                if (!response.ok || !body || body.error) {
                    throw body?.data ?? this.text('Erro ao salvar');
                }

                const i = this.announcements.findIndex((obs) => obs.id === body.id);
                i >= 0 ? this.announcements.splice(i, 1, body) : this.announcements.unshift(body);
                this.messages.success(this.form.id ? this.text('Comunicado atualizado') : this.text('Comunicado publicado'));
                this.cancel();
            } catch (error) {
                this.messages.error(this.errorText(error));
            }
            this.saving = false;
        },

        async remove(obs) {
            try {
                const url = Utils.createUrl('opportunity', 'deletePhaseAnnouncement', [this.config.opportunityId]);
                const response = await fetch(url, {
                    method: 'POST',
                    body: JSON.stringify({ announcementId: obs.id }),
                    headers: { Accept: 'application/json', 'Content-Type': 'application/json' },
                });
                const body = await response.json().catch(() => null);
                if (!response.ok || (body && body.error)) {
                    throw body?.data ?? this.text('Erro ao excluir');
                }
                this.announcements = this.announcements.filter((item) => item.id !== obs.id);
                this.messages.success(this.text('Comunicado excluído'));
            } catch (error) {
                this.messages.error(this.errorText(error));
            }
        },

        errorText(error) {
            if (error && typeof error === 'object') {
                return Object.values(error).flat().join(' ');
            }
            return String(error);
        },

        formatDate(value) {
            if (!value) {
                return '';
            }
            const date = new McDate(value);
            return `${date.date('2-digit year')} ${date.time('2-digit')}`;
        },
    },
});
