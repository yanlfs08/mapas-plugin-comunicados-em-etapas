#!/usr/bin/env python3
"""
Testes de API do plugin de observações por fase.

Rodam contra um Mapas de DESENVOLVIMENTO com login local (e-mail e senha). NUNCA rode em produção:
o teste publica e apaga observações no edital informado.

    MAPAS_URL=http://localhost python3 tests/api_test.py tests/scenario.json

Cenário (veja tests/scenario.example.json):
  - opportunity: id do edital (primeira fase)
  - evaluationPhase: {"type": "evaluationmethodconfiguration", "id": <id>} de uma fase avaliada que NÃO é a primeira
  - users: e-mails de gestor, inPhase (inscrito na fase avaliada), eliminated (inscrito só na 1ª fase),
           outsider (sem inscrição) e as respectivas inscrições da 1ª fase
  - password: senha comum dos usuários de teste
  - otherPhaseId: id de uma fase de OUTRO edital (para testar a validação)
"""
import io
import json
import os
import struct
import sys
import time
import zlib

import requests

BASE_URL = os.environ.get('MAPAS_URL', 'http://localhost').rstrip('/')
results = []


def check(name, condition, detail=''):
    results.append((name, bool(condition)))
    print(('PASS' if condition else 'FAIL') + f'  {name}' + (f'  -> {detail}' if not condition and detail != '' else ''))


class Mapas:
    def __init__(self, email=None, password=None):
        self.s = requests.Session()
        self.s.trust_env = False
        if email:
            r = self.request('post', '/autenticacao/login', data={'email': email, 'password': password, 'g-recaptcha-response': 'teste'},
                             headers={'Accept': 'application/json'})
            if r.json().get('error'):
                raise RuntimeError(f'login falhou para {email}: {r.text[:200]}')

    def request(self, method, path, **kwargs):
        # respeita o limite de requisições do nginx (503)
        for attempt in range(8):
            r = self.s.request(method, f'{BASE_URL}{path}', **kwargs)
            if r.status_code != 503:
                return r
            time.sleep(0.5 * (attempt + 1))
        return r

    def save(self, opp, data, files=None):
        r = self.request('post', f'/opportunity/savePhaseObservation/{opp}', data=data, files=files or [],
                         headers={'Accept': 'application/json'})
        try:
            return r.status_code, r.json()
        except ValueError:
            return r.status_code, r.text

    def delete(self, opp, obs_id):
        r = self.request('post', f'/opportunity/deletePhaseObservation/{opp}', json={'observationId': obs_id}, headers={'Accept': 'application/json'})
        return r.status_code

    def page_config(self, registration_id):
        html = self.request('get', f'/inscricao/{registration_id}/').text
        key = '"phaseObservations":['
        i = html.find(key)
        return json.JSONDecoder().raw_decode(html[i + len(key) - 1:])[0] if i >= 0 else None


def png_bytes():
    """PNG 1x1 válido, gerado na hora."""
    def chunk(kind, data):
        return struct.pack('>I', len(data)) + kind + data + struct.pack('>I', zlib.crc32(kind + data) & 0xffffffff)
    raw = b'\x00\xff\x00\x00'
    return (b'\x89PNG\r\n\x1a\n' + chunk(b'IHDR', struct.pack('>IIBBBBB', 1, 1, 8, 2, 0, 0, 0))
            + chunk(b'IDAT', zlib.compress(raw)) + chunk(b'IEND', b''))


def pdf_bytes(label):
    return f'%PDF-1.4\n% anexo de teste {label}\n'.encode()


def main(path):
    sc = json.load(open(path))
    u = sc['users']
    pw = sc['password']
    opp = sc['opportunity']
    ev = sc['evaluationPhase']

    gestor = Mapas(u['gestor'], pw)
    in_phase = Mapas(u['inPhase'], pw)
    eliminated = Mapas(u['eliminated'], pw)
    outsider = Mapas(u['outsider'], pw)
    guest = Mapas()

    # --- criação ------------------------------------------------------------------------------
    code, obs_eval = gestor.save(opp, {
        'phaseType': ev['type'], 'phaseId': ev['id'],
        'title': 'Resultado preliminar do Mérito', 'text': 'Linha 1 da mensagem.\nLinha 2 da mensagem.',
    }, files=[
        ('image', ('cartaz.png', png_bytes(), 'image/png')),
        ('attachments[]', ('ata.pdf', pdf_bytes('ata'), 'application/pdf')),
        ('attachments[]', ('lista.pdf', pdf_bytes('lista'), 'application/pdf')),
    ])
    check('gestor publica observação com texto, imagem e 2 anexos na fase avaliada',
          code == 200 and obs_eval.get('image') and len(obs_eval.get('attachments', [])) == 2, (code, str(obs_eval)[:300]))

    code, obs_first = gestor.save(opp, {'phaseType': 'opportunity', 'phaseId': opp, 'text': 'Aviso para todos os inscritos.'})
    check('gestor publica observação só com texto na fase de inscrições', code == 200 and obs_first.get('text'), (code, obs_first))

    code, body = in_phase.save(opp, {'phaseType': 'opportunity', 'phaseId': opp, 'text': 'tentativa do candidato'})
    check('candidato não publica observação', code == 403, (code, body))

    code, body = gestor.save(opp, {'phaseType': 'opportunity', 'phaseId': sc['otherPhaseId'], 'text': 'fase de outro edital'})
    check('fase de outro edital é rejeitada', code == 400, (code, body))

    code, body = gestor.save(opp, {'phaseType': 'opportunity', 'phaseId': opp, 'title': '', 'text': '   '})
    check('observação vazia é rejeitada', code == 400, (code, body))

    code, body = gestor.save(opp, {'phaseType': 'opportunity', 'phaseId': opp, 'text': 'imagem falsa'},
                             files=[('image', ('falsa.png', b'isto nao e uma imagem', 'image/png'))])
    check('arquivo que não é imagem é rejeitado como imagem', code == 400, (code, body))

    code, body = gestor.save(opp, {'phaseType': 'opportunity', 'phaseId': opp, 'text': 'script'},
                             files=[('attachments[]', ('x.php', b'<?php echo 1;', 'application/x-php'))])
    check('anexo de tipo não aceito é rejeitado', code == 400, (code, body))

    # --- quem vê na linha do tempo ------------------------------------------------------------------
    cfg = in_phase.page_config(u['inPhaseRegistration']) or []
    ids = {o['id'] for o in cfg}
    check('inscrito na fase avaliada vê a observação da fase avaliada', obs_eval['id'] in ids, ids)
    check('inscrito na fase avaliada vê a observação da fase de inscrições', obs_first['id'] in ids, ids)
    seen = next((o for o in cfg if o['id'] == obs_eval['id']), {})
    check('observação chega com a fase certa (tipo e id do item da linha do tempo)',
          seen.get('phaseType') == ev['type'] and seen.get('phaseId') == ev['id'], seen)

    cfg = eliminated.page_config(u['eliminatedRegistration']) or []
    ids = {o['id'] for o in cfg}
    check('eliminado antes da fase NÃO vê a observação da fase avaliada', obs_eval['id'] not in ids, ids)
    check('eliminado vê a observação da fase de inscrições', obs_first['id'] in ids, ids)

    r = in_phase.request('get', '/api/opportunity/find', params={'id': f'EQ({opp})', '@select': 'id,phaseObservations'})
    check('as observações não vazam pela API pública (metadado privado)', 'Resultado preliminar' not in r.text, r.text[:200])

    # --- arquivos -----------------------------------------------------------------------------------
    image_url = obs_eval['image']['url'].replace(BASE_URL, '')
    attachment_url = obs_eval['attachments'][0]['url'].replace(BASE_URL, '')

    r = in_phase.request('get', image_url)
    check('inscrito na fase abre a imagem (inline)', r.status_code == 200 and r.content.startswith(b'\x89PNG')
          and r.headers.get('Content-Disposition', '').startswith('inline'), (r.status_code, r.headers.get('Content-Disposition')))
    r = in_phase.request('get', attachment_url + '?download=1')
    check('inscrito na fase baixa o anexo', r.status_code == 200 and r.content.startswith(b'%PDF')
          and r.headers.get('Content-Disposition', '').startswith('attachment'), r.status_code)
    r = gestor.request('get', image_url)
    check('gestor abre a imagem', r.status_code == 200, r.status_code)
    r = eliminated.request('get', image_url)
    check('eliminado antes da fase NÃO abre a imagem', r.status_code == 403, r.status_code)
    r = outsider.request('get', attachment_url)
    check('pessoa sem inscrição NÃO baixa o anexo', r.status_code == 403, r.status_code)
    r = guest.request('get', attachment_url, allow_redirects=False)
    check('visitante NÃO baixa o anexo', r.status_code in (302, 401, 403), r.status_code)
    r = guest.request('get', '/files/opportunity/' + str(opp) + '/' + obs_eval['attachments'][0]['name'], allow_redirects=False)
    check('o anexo não fica na pasta pública de arquivos', r.status_code in (403, 404), r.status_code)

    # --- edição -------------------------------------------------------------------------------------
    removed = obs_eval['attachments'][1]
    code, edited = gestor.save(opp, {
        'observationId': obs_eval['id'], 'title': 'Resultado preliminar do Mérito (corrigido)', 'text': 'Texto corrigido.',
        'removeAttachmentIds[]': [removed['id']],
        # tentar trocar a fase na edição não deve ter efeito
        'phaseType': 'opportunity', 'phaseId': opp,
    }, files=[('attachments[]', ('nova.pdf', pdf_bytes('nova'), 'application/pdf'))])
    names = sorted(f['name'] for f in edited.get('attachments', [])) if isinstance(edited, dict) else []
    check('gestor edita texto e troca um anexo', code == 200 and edited.get('text') == 'Texto corrigido.' and names == ['ata.pdf', 'nova.pdf'], (code, names))
    check('edição não muda a fase da observação', isinstance(edited, dict) and edited.get('phaseType') == ev['type'], edited.get('phaseType') if isinstance(edited, dict) else edited)
    r = gestor.request('get', removed['url'].replace(BASE_URL, ''))
    check('anexo removido deixa de existir', r.status_code in (403, 404), r.status_code)

    code, edited = gestor.save(opp, {'observationId': obs_eval['id'], 'title': edited['title'], 'text': edited['text'], 'removeImage': '1'})
    check('gestor remove a imagem', code == 200 and edited.get('image') is None, (code, edited))

    # --- exclusão ------------------------------------------------------------------------------------
    check('candidato não exclui observação', in_phase.delete(opp, obs_eval['id']) == 403)
    check('gestor exclui observação', gestor.delete(opp, obs_eval['id']) == 200)
    check('gestor exclui a outra observação', gestor.delete(opp, obs_first['id']) == 200)
    r = gestor.request('get', attachment_url)
    check('arquivos da observação excluída deixam de existir', r.status_code in (403, 404), r.status_code)
    cfg = in_phase.page_config(u['inPhaseRegistration']) or []
    check('observações excluídas somem da linha do tempo', not {o['id'] for o in cfg} & {obs_eval['id'], obs_first['id']}, cfg)
    check('excluir de novo responde 404', gestor.delete(opp, obs_eval['id']) == 404)

    failed = [name for name, ok in results if not ok]
    print(f'\n{len(results) - len(failed)}/{len(results)} testes passaram')
    return 1 if failed else 0


if __name__ == '__main__':
    sys.exit(main(sys.argv[1] if len(sys.argv) > 1 else 'tests/scenario.json'))
