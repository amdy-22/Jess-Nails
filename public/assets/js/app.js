// Menu mobile : ouvre / ferme la navigation
document.addEventListener('DOMContentLoaded', function () {
    var bouton = document.querySelector('.menu-toggle');
    var menu = document.getElementById('menu-principal');
    if (!bouton || !menu) return;

    bouton.addEventListener('click', function () {
        var ouvert = menu.classList.toggle('ouvert');
        bouton.setAttribute('aria-expanded', ouvert ? 'true' : 'false');
    });
});

// Réservation : affiche les horaires dès que le soin ET la date sont choisis
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('form-choix');
    if (!form) return;
    var soin = form.querySelector('#prestation');
    var date = form.querySelector('#date');

    function envoyerSiComplet() {
        if (soin.value && date.value) form.submit();
    }
    soin.addEventListener('change', envoyerSiComplet);
    date.addEventListener('change', envoyerSiComplet);
    var praticienne = form.querySelector('#praticienne');
    if (praticienne) praticienne.addEventListener('change', envoyerSiComplet);
});

// Demande une confirmation avant certaines actions (ex. annuler un rendez-vous)
document.addEventListener('submit', function (e) {
    var message = e.target.getAttribute('data-confirmer');
    if (message && !window.confirm(message)) {
        e.preventDefault();
    }
});