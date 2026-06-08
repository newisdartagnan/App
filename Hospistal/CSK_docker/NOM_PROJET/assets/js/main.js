// ============================================
// GPS - main.js
// Fonctions JavaScript communes
// ============================================

// Export CSV côté client (tableau HTML → fichier .csv)
function exportToCSV(tableId, filename) {
    const table = document.getElementById(tableId);
    if (!table) { alert('Tableau introuvable.'); return; }

    const rows  = table.querySelectorAll('tr');
    const lines = [];

    rows.forEach(row => {
        const cells = row.querySelectorAll('th, td');
        const line  = Array.from(cells).map(cell => {
            let text = cell.innerText.replace(/"/g, '""').trim();
            return '"' + text + '"';
        });
        lines.push(line.join(';'));
    });

    // BOM UTF-8 pour Excel
    const blob = new Blob(['\uFEFF' + lines.join('\n')], { type: 'text/csv;charset=utf-8;' });
    const url  = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href  = url;
    link.setAttribute('download', filename);
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    URL.revokeObjectURL(url);
}

// Confirmer suppression
function confirmDelete(message) {
    return confirm(message || 'Confirmer la suppression ?');
}

// Afficher/masquer un élément
function toggleElement(id) {
    const el = document.getElementById(id);
    if (el) {
        el.style.display = el.style.display === 'none' ? 'block' : 'none';
    }
}

// Fermer automatiquement les alertes après 5s
document.addEventListener('DOMContentLoaded', function () {
    const alerts = document.querySelectorAll('.alert[data-autohide]');
    alerts.forEach(function (alert) {
        setTimeout(function () {
            alert.style.transition = 'opacity 0.5s';
            alert.style.opacity = '0';
            setTimeout(() => alert.remove(), 500);
        }, 4500);
    });

    // Activer liens actifs dans sidebar
    const currentPath = window.location.pathname;
    document.querySelectorAll('.nav-link').forEach(link => {
        if (link.getAttribute('href') && currentPath.includes(link.getAttribute('href').split('/').pop())) {
            link.classList.add('active');
        }
    });
});

// Recherche en temps réel dans un tableau
function filterTable(inputId, tableId) {
    const input  = document.getElementById(inputId);
    const table  = document.getElementById(tableId);
    if (!input || !table) return;

    input.addEventListener('keyup', function () {
        const filter = this.value.toLowerCase();
        const rows   = table.querySelectorAll('tbody tr');
        rows.forEach(row => {
            const text = row.innerText.toLowerCase();
            row.style.display = text.includes(filter) ? '' : 'none';
        });
    });
}

// Format montant (JS)
function formatMoney(amount) {
    return parseFloat(amount || 0).toLocaleString('fr-FR', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    }) + ' FC';
}
