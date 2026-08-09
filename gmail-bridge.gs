/*
 * =========================================================
 * Gmail Bridge fuer die mobile.de Verkaufszentrale
 * Google Apps Script
 *
 * Zweck:
 * - nativen Gmail-Entwurf als Antwort erstellen
 * - oder eine gepruefte Antwort direkt versenden
 * - immer als Antwort auf die konkrete mobile.de-Nachricht
 * =========================================================
 */

var BRIDGE_TOKEN = 'HIER_EIN_LANGES_ZUFAELLIGES_TOKEN_EINTRAGEN';


function doGet(e) {
  return jsonResponse({
    ok: true,
    service: 'mobile.de Gmail Bridge',
    version: '1.1'
  });
}


function doPost(e) {
  try {
    var data = {};

    if (e && e.postData && e.postData.contents) {
      data = JSON.parse(e.postData.contents);
    }

    if (!data || data.token !== BRIDGE_TOKEN) {
      return jsonResponse({
        ok: false,
        error: 'Ungueltiges Bridge-Token.'
      });
    }

    if (data.action !== 'createDraftReply' && data.action !== 'sendReply') {
      return jsonResponse({
        ok: false,
        error: 'Unbekannte Aktion.'
      });
    }

    var body = String(data.body || '').trim();

    if (body === '') {
      return jsonResponse({
        ok: false,
        error: 'Der Antworttext ist leer.'
      });
    }

    var target = findTargetMessage(data);

    if (!target) {
      return jsonResponse({
        ok: false,
        error: 'Die passende Gmail-Nachricht konnte nicht gefunden werden.'
      });
    }

    var options = {};

    if (data.seller_name) {
      options.name = String(data.seller_name);
    }

    if (data.action === 'sendReply') {
      /*
       * reply() versendet die Antwort direkt an die Reply-To-Adresse
       * der konkreten Gmail-Nachricht und haelt sie im selben Thread.
       */
      var thread = target.getThread();
      target.reply(body, options);
      thread.refresh();

      var messages = thread.getMessages();
      var latest = messages.length ? messages[messages.length - 1] : null;

      return jsonResponse({
        ok: true,
        sent: true,
        thread_id: thread.getId(),
        message_id: latest ? latest.getId() : '',
        subject: latest ? latest.getSubject() : target.getSubject(),
        recipient: String(data.sender_email || '')
      });
    }

    /*
     * createDraftReply() erzeugt einen NATIVEN Gmail-Entwurf
     * als Antwort auf genau diese Nachricht.
     */
    var draft = target.createDraftReply(body, options);

    /* Native Existenz noch einmal pruefen. */
    var verifiedDraft = GmailApp.getDraft(draft.getId());
    var draftMessage = verifiedDraft.getMessage();

    return jsonResponse({
      ok: true,
      draft_id: verifiedDraft.getId(),
      message_id: verifiedDraft.getMessageId(),
      thread_id: draftMessage.getThread().getId(),
      subject: draftMessage.getSubject(),
      recipient: draftMessage.getTo()
    });

  } catch (err) {
    return jsonResponse({
      ok: false,
      error: String(err && err.message ? err.message : err)
    });
  }
}


function findTargetMessage(data) {
  var wantedMessageId = normalizeMessageId(String(data.message_id || ''));
  var senderEmail = String(data.sender_email || '').trim().toLowerCase();
  var subject = String(data.subject || '').trim();
  var threads = [];
  var i;
  var j;

  /*
   * 1. Beste Methode: die RFC Message-ID der konkreten eingegangenen Mail.
   */
  if (wantedMessageId !== '') {
    try {
      threads = GmailApp.search('rfc822msgid:' + wantedMessageId, 0, 10);
    } catch (ignore1) {
      threads = [];
    }

    for (i = 0; i < threads.length; i++) {
      var messages = threads[i].getMessages();

      for (j = messages.length - 1; j >= 0; j--) {
        var currentId = normalizeMessageId(messages[j].getHeader('Message-ID'));

        if (currentId !== '' && currentId === wantedMessageId) {
          return messages[j];
        }
      }
    }
  }

  /*
   * 2. Fallback: anonyme mobile.de-Absenderadresse. Diese ist pro Kontakt
   * sehr spezifisch und daher fuer die Zuordnung gut geeignet.
   */
  if (senderEmail !== '') {
    try {
      threads = GmailApp.search('from:' + senderEmail, 0, 20);
    } catch (ignore2) {
      threads = [];
    }

    var bestMessage = null;
    var bestTime = 0;

    for (i = 0; i < threads.length; i++) {
      var fallbackMessages = threads[i].getMessages();

      for (j = 0; j < fallbackMessages.length; j++) {
        var from = String(fallbackMessages[j].getFrom() || '').toLowerCase();

        if (from.indexOf(senderEmail) === -1) {
          continue;
        }

        if (subject !== '' && !subjectsMatch(subject, fallbackMessages[j].getSubject())) {
          continue;
        }

        var ts = fallbackMessages[j].getDate().getTime();

        if (ts >= bestTime) {
          bestTime = ts;
          bestMessage = fallbackMessages[j];
        }
      }
    }

    if (bestMessage) {
      return bestMessage;
    }
  }

  return null;
}


function normalizeMessageId(value) {
  value = String(value || '').replace(/[\r\n\s]/g, '');

  if (value === '') {
    return '';
  }

  if (value.charAt(0) !== '<') {
    value = '<' + value;
  }

  if (value.charAt(value.length - 1) !== '>') {
    value = value + '>';
  }

  return value;
}


function normalizeSubject(value) {
  value = String(value || '').trim();
  value = value.replace(/^(re:\s*)+/i, '');
  value = value.replace(/\s+/g, ' ');
  return value.toLowerCase();
}


function subjectsMatch(a, b) {
  return normalizeSubject(a) === normalizeSubject(b);
}


function jsonResponse(obj) {
  return ContentService
    .createTextOutput(JSON.stringify(obj))
    .setMimeType(ContentService.MimeType.JSON);
}
