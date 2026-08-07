// mobile.de -> WhatsApp Bridge für ioBroker JavaScript
// Liest JSON aus 0_userdata.0.mobile.whatsapp.outgoing
// und sendet es über open-wa.0.

const STATE_ID = '0_userdata.0.mobile.whatsapp.outgoing';

createState(
    STATE_ID,
    '',
    {
        type: 'string',
        read: true,
        write: true,
        role: 'text',
        name: 'mobile.de WhatsApp outgoing'
    },
    () => {
        // Kein info/warn-Log. Relevante Fehler werden als error geloggt.
    }
);

on({ id: STATE_ID, change: 'ne' }, (obj) => {
    if (!obj || !obj.state || obj.state.ack) {
        return;
    }

    const raw = String(obj.state.val || '').trim();

    if (!raw) {
        return;
    }

    let payload;

    try {
        payload = JSON.parse(raw);
    } catch (e) {
        log('mobile.de WhatsApp Bridge: ungültiges JSON: ' + e.message, 'error');
        setState(STATE_ID, '', true);
        return;
    }

    const adapter = String(payload.adapter || 'open-wa.0').trim();
    const to = String(payload.to || '').trim();
    const text = String(payload.text || '').trim();

    if (!to || !text) {
        log('mobile.de WhatsApp Bridge: Empfänger oder Text fehlt.', 'error');
        setState(STATE_ID, '', true);
        return;
    }

    try {
        sendTo(
            adapter,
            'send',
            {
                to: to,
                text: text
            },
            (result) => {
                if (result && result.error) {
                    log('mobile.de WhatsApp Bridge: open-wa Fehler: ' + JSON.stringify(result), 'error');
                }
            }
        );
    } catch (e) {
        log('mobile.de WhatsApp Bridge: sendTo Fehler: ' + e.message, 'error');
    }

    // Zurücksetzen, damit auch identische spätere Nachrichten erneut triggern können.
    setState(STATE_ID, '', true);
});
