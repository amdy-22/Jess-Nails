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

// Galerie : visionneuse plein écran (flèches, Échap, glisser du doigt)
document.addEventListener('DOMContentLoaded', function () {
    var galerie = document.querySelector('[data-galerie]');
    var boite = document.getElementById('visionneuse');
    if (!galerie || !boite) return;

    var liens = Array.prototype.slice.call(galerie.querySelectorAll('.galerie-item'));
    var img = boite.querySelector('img');
    var legende = boite.querySelector('figcaption');
    var courant = 0;
    var dernierFocus = null;

    function afficher(i) {
        courant = (i + liens.length) % liens.length;
        var lien = liens[courant];
        img.src = lien.getAttribute('href');
        img.alt = lien.querySelector('img').alt;
        legende.textContent = lien.getAttribute('data-legende') || '';
        legende.hidden = !legende.textContent;
    }
    function ouvrir(i) {
        dernierFocus = document.activeElement;
        afficher(i);
        boite.hidden = false;
        document.body.classList.add('sans-defilement');
        boite.querySelector('.visionneuse-fermer').focus();
    }
    function fermer() {
        boite.hidden = true;
        img.src = '';
        document.body.classList.remove('sans-defilement');
        if (dernierFocus) dernierFocus.focus();
    }

    liens.forEach(function (lien, i) {
        lien.addEventListener('click', function (e) { e.preventDefault(); ouvrir(i); });
    });
    boite.querySelector('.visionneuse-fermer').addEventListener('click', fermer);
    boite.querySelector('.visionneuse-prec').addEventListener('click', function () { afficher(courant - 1); });
    boite.querySelector('.visionneuse-suiv').addEventListener('click', function () { afficher(courant + 1); });
    boite.addEventListener('click', function (e) { if (e.target === boite) fermer(); });
    document.addEventListener('keydown', function (e) {
        if (boite.hidden) return;
        if (e.key === 'Escape') fermer();
        if (e.key === 'ArrowLeft') afficher(courant - 1);
        if (e.key === 'ArrowRight') afficher(courant + 1);
    });

    var departX = null;
    boite.addEventListener('touchstart', function (e) { departX = e.touches[0].clientX; }, { passive: true });
    boite.addEventListener('touchend', function (e) {
        if (departX === null) return;
        var ecart = e.changedTouches[0].clientX - departX;
        if (Math.abs(ecart) > 50) afficher(courant + (ecart < 0 ? 1 : -1));
        departX = null;
    });
});

// Carrousel de l'accueil : défilement automatique, flèches, points, glisser du doigt (défilement natif)
document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('[data-carrousel]').forEach(function (carrousel) {
        var piste = carrousel.querySelector('.carrousel-piste');
        var slides = Array.prototype.slice.call(piste.children);
        var zonePoints = carrousel.querySelector('.carrousel-points');
        if (slides.length < 2 || !zonePoints) return;

        var reduit = window.matchMedia('(prefers-reduced-motion: reduce)').matches;
        var DELAI = 4500;
        var minuteur = null;
        var enPause = false;

        // Un point par position possible (avec 3 photos visibles sur 5, il n'y a que 3 positions)
        var points = [];
        function nbPositions() {
            var parVue = Math.max(1, Math.round(piste.clientWidth / slides[0].getBoundingClientRect().width));
            return Math.max(1, slides.length - parVue + 1);
        }
        function creerPoints() {
            zonePoints.innerHTML = '';
            points = [];
            var n = nbPositions();
            zonePoints.hidden = n < 2;
            for (var i = 0; i < n; i++) {
                var p = document.createElement('button');
                p.type = 'button';
                p.className = 'carrousel-point';
                p.setAttribute('aria-label', 'Position ' + (i + 1) + ' sur ' + n);
                p.addEventListener('click', (function (j) { return function () { aller(j); relancer(); }; })(i));
                zonePoints.appendChild(p);
                points.push(p);
            }
            majPoints();
        }

        function indexCourant() {
            var gauche = piste.scrollLeft;
            var meilleur = 0, ecart = Infinity;
            slides.forEach(function (s, i) {
                var d = Math.abs(s.offsetLeft - piste.offsetLeft - gauche);
                if (d < ecart) { ecart = d; meilleur = i; }
            });
            return meilleur;
        }
        function finAtteinte() {
            return piste.scrollLeft + piste.clientWidth >= piste.scrollWidth - 4;
        }
        function aller(i) {
            i = (i + slides.length) % slides.length;
            piste.scrollTo({ left: slides[i].offsetLeft - piste.offsetLeft, behavior: reduit ? 'auto' : 'smooth' });
        }
        function suivant() { finAtteinte() ? aller(0) : aller(indexCourant() + 1); }
        function precedent() { indexCourant() === 0 ? aller(slides.length - 1) : aller(indexCourant() - 1); }
        function majPoints() {
            var c = finAtteinte() ? points.length - 1 : Math.min(indexCourant(), points.length - 1);
            points.forEach(function (p, i) { p.setAttribute('aria-current', i === c ? 'true' : 'false'); });
        }

        carrousel.querySelector('.carrousel-suiv').addEventListener('click', function () { suivant(); relancer(); });
        carrousel.querySelector('.carrousel-prec').addEventListener('click', function () { precedent(); relancer(); });
        piste.addEventListener('keydown', function (e) {
            if (e.key === 'ArrowRight') { e.preventDefault(); suivant(); relancer(); }
            if (e.key === 'ArrowLeft') { e.preventDefault(); precedent(); relancer(); }
        });

        var attente;
        piste.addEventListener('scroll', function () { clearTimeout(attente); attente = setTimeout(majPoints, 80); }, { passive: true });

        // Défilement automatique, en pause au survol, au toucher, au clavier ou onglet caché
        function demarrer() {
            if (reduit || enPause || minuteur) return;
            minuteur = setInterval(suivant, DELAI);
        }
        function arreter() { clearInterval(minuteur); minuteur = null; }
        function relancer() { arreter(); demarrer(); }
        function pause(etat) { enPause = etat; etat ? arreter() : demarrer(); }

        carrousel.addEventListener('mouseenter', function () { pause(true); });
        carrousel.addEventListener('mouseleave', function () { pause(false); });
        carrousel.addEventListener('focusin', function () { pause(true); });
        carrousel.addEventListener('focusout', function () { pause(false); });
        piste.addEventListener('touchstart', function () { pause(true); }, { passive: true });
        piste.addEventListener('touchend', function () { setTimeout(function () { pause(false); }, 3000); });
        document.addEventListener('visibilitychange', function () { document.hidden ? arreter() : demarrer(); });

        var redim;
        window.addEventListener('resize', function () { clearTimeout(redim); redim = setTimeout(creerPoints, 150); });

        creerPoints();
        demarrer();
    });
});
