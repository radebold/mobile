function confirmAppUpdate() {
    return window.confirm(
        'Update aus GitHub installieren?\n\n' +
        'Die aktuellen Programmdateien werden vorher automatisch gesichert.\n' +
        'Konfiguration und Gesprächsdaten bleiben unverändert.'
    );
}

function statusChanged(select) {
    if (!select || !select.form) return false;

    if (select.value === 'beantwortet') {
        var answeredOk = window.confirm(
            'Als beantwortet / gesendet markieren?\n\n' +
            'Das Gespräch wird ausgeblendet und die zugehörigen Gmail-Nachrichten werden archiviert.\n\n' +
            'Wenn der Käufer später erneut schreibt, erscheint das Gespräch automatisch wieder als Neu.'
        );
        if (!answeredOk) {
            var answeredOldIndex = parseInt(select.getAttribute('data-old-index'), 10);
            if (!isNaN(answeredOldIndex)) select.selectedIndex = answeredOldIndex;
            return false;
        }
    }

    if (select.value === 'erledigt') {
        var ok = window.confirm(
            'Kein Interesse setzen?\n\n' +
            'Das Gespräch wird ausgeblendet und die zugehörigen Gmail-Nachrichten werden in den Papierkorb verschoben.\n\n' +
            'Die Nachrichten auf mobile.de selbst bleiben erhalten.'
        );
        if (!ok) {
            var oldIndex = parseInt(select.getAttribute('data-old-index'), 10);
            if (!isNaN(oldIndex)) select.selectedIndex = oldIndex;
            return false;
        }
    }

    select.form.submit();
    return true;
}

function rememberStatusIndex(select) {
    if (select) select.setAttribute('data-old-index', select.selectedIndex);
}

function confirmGmailDraft() {
    return window.confirm('Den aktuellen Text als Gmail-Entwurf im passenden Antwort-Thread speichern?\n\nEs wird nichts automatisch gesendet.');
}

function copyDraft(id, button) {
    var field = document.getElementById(id);
    if (!field) return;
    field.focus();
    field.select();
    var ok = false;
    try { ok = document.execCommand('copy'); } catch (e) { ok = false; }
    if (ok) {
        var oldText = button.innerHTML;
        button.innerHTML = 'Kopiert';
        window.setTimeout(function () { button.innerHTML = oldText; }, 1400);
    } else {
        alert('Kopieren war nicht automatisch möglich. Bitte Text markieren und manuell kopieren.');
    }
}
