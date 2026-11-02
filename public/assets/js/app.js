// Menu mobile : ouvre / ferme la navigation
document.addEventListener('DOMContentLoaded', function () {
    var bouton = document.querySelector('.menu-toggle');
    var menu = document.getElementById('menu-principal');
    if (!bouton || !menu) return;

    function basculer(ouvrir) {
        menu.classList.toggle('ouvert', ouvrir);
        bouton.setAttribute('aria-expanded', ouvrir ? 'true' : 'false');
        bouton.setAttribute('aria-label', ouvrir ? 'Fermer le menu' : 'Ouvrir le menu');
    }

    bouton.addEventListener('click', function (e) {
        e.stopPropagation();
        basculer(!menu.classList.contains('ouvert'));
    });

    // Fermer en touchant en dehors du menu, avec la touche Échap, ou après un clic sur un lien
    document.addEventListener('click', function (e) {
        if (menu.classList.contains('ouvert') && !menu.contains(e.target)) basculer(false);
    });
    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && menu.classList.contains('ouvert')) { basculer(false); bouton.focus(); }
    });
    menu.querySelectorAll('a').forEach(function (lien) {
        lien.addEventListener('click', function () { basculer(false); });
    });
});

// Réservation : affiche les horaires dès que le soin ET la date sont choisis
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('form-choix');
    if (!form) return;
    var soin = form.querySelector('#prestation');
    var date = form.querySelector('#date');

    // Garde la liste « Ajouter un autre soin » ouverte après le rechargement
    function noterOuverture() {
        var supp = form.querySelector('.soins-supp');
        var champ = form.querySelector('input[name="plus"]');
        if (supp && supp.open && !champ) {
            champ = document.createElement('input');
            champ.type = 'hidden'; champ.name = 'plus'; champ.value = '1';
            form.appendChild(champ);
        }
    }
    function envoyerSiComplet() {
        if (soin.value && date.value) { noterOuverture(); form.submit(); }
    }
    form.addEventListener('submit', noterOuverture);
    soin.addEventListener('change', envoyerSiComplet);
    date.addEventListener('change', envoyerSiComplet);
    var praticienne = form.querySelector('#praticienne');
    if (praticienne) praticienne.addEventListener('change', envoyerSiComplet);

    // Soins ajoutés : on bloque les cases au-delà du maximum, puis on recharge les horaires
    var supp = form.querySelector('.soins-supp');
    if (supp) {
        var max = parseInt(supp.getAttribute('data-max'), 10) || 3;
        var cases = supp.querySelectorAll('input[name="autres[]"]');
        function limiter() {
            var cochees = supp.querySelectorAll('input[name="autres[]"]:checked').length;
            cases.forEach(function (c) { c.disabled = !c.checked && cochees >= max; });
        }
        cases.forEach(function (c) {
            c.addEventListener('change', function () { limiter(); envoyerSiComplet(); });
        });
        limiter();
    }
});

// Demande une confirmation avant certaines actions (ex. annuler un rendez-vous)
document.addEventListener('submit', function (e) {
    var message = e.target.getAttribute('data-confirmer');
    if (message && !window.confirm(message)) {
        e.preventDefault();
    }
});

// Formulaires qui se renvoient seuls quand on change un champ (ex. date du déplacement)
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('form[data-envoi-auto]').forEach(function (form) {
        form.addEventListener('change', function () {
            if (form.checkValidity()) form.submit();
        });
    });
});
