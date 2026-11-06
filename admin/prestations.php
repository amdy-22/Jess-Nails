<?php
/**
 * Gestion des prestations (soins) et de leurs catégories.
 */
require_once __DIR__ . '/../includes/auth.php';
require_admin();

const DOSSIER_IMAGES = __DIR__ . '/../public/assets/images/prestations';
const IMAGE_MAX_OCTETS = 2 * 1024 * 1024;   // 2 Mo

$urlPage = ADMIN_URL . '/prestations';

/** "Soins du visage" -> "soins-du-visage" */
function slugifier(string $texte): string
{
    $texte = strtr(mb_strtolower(trim($texte)), [
        'à'=>'a','â'=>'a','ä'=>'a','á'=>'a','ç'=>'c','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e',
        'î'=>'i','ï'=>'i','í'=>'i','ô'=>'o','ö'=>'o','ó'=>'o','ù'=>'u','û'=>'u','ü'=>'u','ú'=>'u','ÿ'=>'y','œ'=>'oe','æ'=>'ae',
    ]);
    return trim(preg_replace('/[^a-z0-9]+/', '-', $texte), '-') ?: 'categorie';
}

/**
 * Enregistre l'image envoyée et renvoie son chemin relatif (ou null si aucune).
 * Lève une exception avec un message lisible si le fichier ne convient pas.
 */
function enregistrer_image(array $fichier): ?string
{
    if (($fichier['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($fichier['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('L\'envoi de l\'image a échoué (fichier trop lourd ?).');
    }
    if ($fichier['size'] > IMAGE_MAX_OCTETS) {
        throw new RuntimeException('L\'image dépasse 2 Mo.');
    }
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];
    $infos = @getimagesize($fichier['tmp_name']);
    if (!$infos || !isset($types[$infos[2]])) {
        throw new RuntimeException('Format accepté : JPG, PNG ou WebP.');
    }
    if (!is_dir(DOSSIER_IMAGES)) {
        mkdir(DOSSIER_IMAGES, 0775, true);
    }
    $nom = bin2hex(random_bytes(8)) . '.' . $types[$infos[2]];
    if (!move_uploaded_file($fichier['tmp_name'], DOSSIER_IMAGES . '/' . $nom)) {
        throw new RuntimeException('Impossible d\'enregistrer l\'image sur le serveur.');
    }
    return 'prestations/' . $nom;
}

function supprimer_image(?string $chemin): void
{
    if ($chemin && str_starts_with($chemin, 'prestations/')) {
        @unlink(__DIR__ . '/../public/assets/images/' . basename(dirname($chemin)) . '/' . basename($chemin));
    }
}

// =====================================================
// Traitements
// =====================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_verify();
    $action = $_POST['action'] ?? '';

    // ---------- Interrupteur de l'acompte en ligne ----------
    if ($action === 'acompte_en_ligne') {
        $actif = ($_POST['valeur'] ?? '') === '1';
        if (definir_parametre('acompte_en_ligne', $actif ? '1' : '0')) {
            flash('succes', $actif
                ? 'Acompte en ligne réactivé : les clientes peuvent de nouveau payer un acompte.'
                : 'Acompte en ligne désactivé : les réservations sont confirmées directement, réglées sur place.');
        } else {
            flash('erreur', 'Réglage impossible : lance d\'abord la migration sql/etape26_parametres.sql.');
        }
        redirect($urlPage);
    }

    // ---------- Ajouter / modifier une prestation ----------
    if ($action === 'enregistrer') {
        $id          = (int) ($_POST['id'] ?? 0);
        $categorieId = (int) ($_POST['categorie_id'] ?? 0);
        $nom         = trim($_POST['nom'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $duree       = (int) ($_POST['duree_minutes'] ?? 0);
        $prix        = (int) ($_POST['prix_fcfa'] ?? 0);
        $acompte     = (int) ($_POST['acompte_fcfa'] ?? 0);
        $actif       = isset($_POST['actif']) ? 1 : 0;

        $erreurs = [];
        $cat = db()->prepare('SELECT id FROM categories WHERE id = ?');
        $cat->execute([$categorieId]);
        if (!$cat->fetch())                      { $erreurs[] = 'Choisis une catégorie.'; }
        if ($nom === '')                         { $erreurs[] = 'Le nom du soin est obligatoire.'; }
        if ($duree < 15 || $duree > 480)         { $erreurs[] = 'La durée doit être comprise entre 15 min et 8 h.'; }
        if ($prix <= 0)                          { $erreurs[] = 'Le prix doit être supérieur à 0.'; }
        if ($acompte < 0 || $acompte > $prix)    { $erreurs[] = 'L\'acompte doit être compris entre 0 et le prix.'; }

        $ancienne = null;
        if ($id) {
            $st = db()->prepare('SELECT * FROM prestations WHERE id = ?');
            $st->execute([$id]);
            $ancienne = $st->fetch();
            if (!$ancienne) {
                $erreurs[] = 'Prestation introuvable.';
            }
        }

        $image = $ancienne['image'] ?? null;
        if (!$erreurs) {
            try {
                $nouvelle = enregistrer_image($_FILES['image'] ?? []);
                if ($nouvelle) {
                    supprimer_image($image);
                    $image = $nouvelle;
                } elseif (isset($_POST['retirer_image'])) {
                    supprimer_image($image);
                    $image = null;
                }
            } catch (RuntimeException $e) {
                $erreurs[] = $e->getMessage();
            }
        }

        if ($erreurs) {
            set_old($_POST);
            $_SESSION['erreurs'] = $erreurs;
            redirect($urlPage . ($id ? '?modifier=' . $id : '?nouveau=1') . '#formulaire');
        }

        $valeurs = [$categorieId, $nom, $description !== '' ? $description : null, $duree, $prix, $acompte, $image, $actif];
        if ($id) {
            db()->prepare(
                'UPDATE prestations SET categorie_id = ?, nom = ?, description = ?, duree_minutes = ?,
                        prix_fcfa = ?, acompte_fcfa = ?, image = ?, actif = ? WHERE id = ?'
            )->execute([...$valeurs, $id]);
            flash('succes', '« ' . $nom . ' » est mis à jour. Les rendez-vous déjà pris gardent leur ancien prix.');
        } else {
            db()->prepare(
                'INSERT INTO prestations (categorie_id, nom, description, duree_minutes, prix_fcfa, acompte_fcfa, image, actif)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
            )->execute($valeurs);
            flash('succes', '« ' . $nom . ' » est ajouté.');
        }
        clear_old();
        redirect($urlPage);
    }

    // ---------- Activer / désactiver ----------
    if ($action === 'basculer') {
        db()->prepare('UPDATE prestations SET actif = 1 - actif WHERE id = ?')->execute([(int) $_POST['id']]);
        flash('succes', 'Visibilité du soin modifiée.');
        redirect($urlPage);
    }

    // ---------- Supprimer (seulement si jamais réservé) ----------
    if ($action === 'supprimer') {
        $id = (int) $_POST['id'];
        $nb = db()->prepare('SELECT COUNT(*) FROM reservation_prestations WHERE prestation_id = ?');
        $nb->execute([$id]);
        if ((int) $nb->fetchColumn() > 0) {
            flash('erreur', 'Ce soin a déjà été réservé : il ne peut pas être supprimé. Désactive-le pour le masquer du site.');
        } else {
            $st = db()->prepare('SELECT image FROM prestations WHERE id = ?');
            $st->execute([$id]);
            supprimer_image($st->fetchColumn() ?: null);
            db()->prepare('DELETE FROM prestations WHERE id = ?')->execute([$id]);
            flash('succes', 'Soin supprimé.');
        }
        redirect($urlPage);
    }

    // ---------- Catégories ----------
    if ($action === 'ajouter_categorie') {
        $nomCat = trim($_POST['nom_categorie'] ?? '');
        if ($nomCat === '') {
            flash('erreur', 'Indique le nom de la catégorie.');
        } else {
            $slug = slugifier($nomCat);
            $existe = db()->prepare('SELECT COUNT(*) FROM categories WHERE slug LIKE ?');
            $existe->execute([$slug . '%']);
            if ((int) $existe->fetchColumn() > 0) {
                $slug .= '-' . substr(bin2hex(random_bytes(2)), 0, 4);
            }
            $ordre = (int) db()->query('SELECT COALESCE(MAX(ordre), 0) + 1 FROM categories')->fetchColumn();
            db()->prepare('INSERT INTO categories (nom, slug, ordre) VALUES (?, ?, ?)')->execute([$nomCat, $slug, $ordre]);
            flash('succes', 'Catégorie « ' . $nomCat . ' » ajoutée.');
        }
        redirect($urlPage . '#categories');
    }

    if ($action === 'renommer_categorie') {
        $nomCat = trim($_POST['nom_categorie'] ?? '');
        if ($nomCat !== '') {
            db()->prepare('UPDATE categories SET nom = ? WHERE id = ?')->execute([$nomCat, (int) $_POST['id']]);
            flash('succes', 'Catégorie renommée.');
        }
        redirect($urlPage . '#categories');
    }

    if ($action === 'deplacer_categorie') {
        $id   = (int) $_POST['id'];
        $sens = $_POST['sens'] === 'haut' ? -1 : 1;
        $cats = db()->query('SELECT id FROM categories ORDER BY ordre, id')->fetchAll(PDO::FETCH_COLUMN);
        $pos  = array_search($id, array_map('intval', $cats), true);
        if ($pos !== false && isset($cats[$pos + $sens])) {
            [$cats[$pos], $cats[$pos + $sens]] = [$cats[$pos + $sens], $cats[$pos]];
            $maj = db()->prepare('UPDATE categories SET ordre = ? WHERE id = ?');
            foreach ($cats as $i => $cid) {
                $maj->execute([$i + 1, $cid]);
            }
        }
        redirect($urlPage . '#categories');
    }

    if ($action === 'supprimer_categorie') {
        $id = (int) $_POST['id'];
        $nb = db()->prepare('SELECT COUNT(*) FROM prestations WHERE categorie_id = ?');
        $nb->execute([$id]);
        if ((int) $nb->fetchColumn() > 0) {
            flash('erreur', 'Cette catégorie contient des soins : déplace-les ou supprime-les d\'abord.');
        } else {
            db()->prepare('DELETE FROM categories WHERE id = ?')->execute([$id]);
            flash('succes', 'Catégorie supprimée.');
        }
        redirect($urlPage . '#categories');
    }

    redirect($urlPage);
}

// =====================================================
// Affichage
// =====================================================
$categories = db()->query(
    'SELECT c.*, COUNT(p.id) AS nb FROM categories c LEFT JOIN prestations p ON p.categorie_id = c.id
     GROUP BY c.id ORDER BY c.ordre, c.id'
)->fetchAll();

$prestations = db()->query(
    "SELECT p.*, c.nom AS categorie,
            (SELECT COUNT(*) FROM reservation_prestations rp JOIN reservations r ON r.id = rp.reservation_id
              WHERE rp.prestation_id = p.id AND r.statut = 'confirmee' AND r.debut > NOW()) AS a_venir,
            (SELECT COUNT(*) FROM reservation_prestations rp WHERE rp.prestation_id = p.id) AS total_resas
     FROM prestations p JOIN categories c ON c.id = p.categorie_id
     ORDER BY c.ordre, c.id, p.prix_fcfa"
)->fetchAll();

// Formulaire : nouveau ou modification
$enEdition = null;
if (!empty($_GET['modifier'])) {
    $st = db()->prepare('SELECT * FROM prestations WHERE id = ?');
    $st->execute([(int) $_GET['modifier']]);
    $enEdition = $st->fetch() ?: null;
}
$afficherForm = $enEdition || !empty($_GET['nouveau']);
$f = $_SESSION['old'] ?? ($enEdition ?: ['actif' => 1, 'duree_minutes' => 60, 'acompte_fcfa' => 0]);
$erreurs = $_SESSION['erreurs'] ?? [];
unset($_SESSION['erreurs']);
clear_old();

$pageTitle   = 'Prestations';
$activeAdmin = 'prestations';
require __DIR__ . '/../includes/admin_header.php';
?>

<section class="entete-page entete-compte">
    <div>
        <h1>Prestations</h1>
        <p class="discret"><?= count($prestations) ?> soins · les soins désactivés n'apparaissent plus sur le site</p>
    </div>
    <?php if (!$afficherForm): ?>
        <a href="?nouveau=1#formulaire" class="btn btn-plein">Ajouter un soin</a>
    <?php endif; ?>
</section>

<?php $acompteActif = acompte_en_ligne_actif(); ?>
<section class="resa-bloc bloc-reglage">
    <div>
        <h2>Acompte en ligne : <?= $acompteActif ? 'activé' : 'désactivé' ?></h2>
        <p class="discret">
            <?= $acompteActif
                ? 'Les clientes peuvent payer un acompte par Mobile Money ou carte (FedaPay), ou tout régler sur place.'
                : 'Aucun paiement en ligne n\'est proposé : chaque réservation est confirmée tout de suite et réglée à l\'institut. Les acomptes déjà payés restent valables et remboursables.' ?>
        </p>
    </div>
    <form method="post" data-confirmer="<?= $acompteActif ? 'Désactiver l\'acompte en ligne ? Les clientes ne pourront plus payer en ligne.' : 'Réactiver l\'acompte en ligne ?' ?>">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="acompte_en_ligne">
        <input type="hidden" name="valeur" value="<?= $acompteActif ? '0' : '1' ?>">
        <button type="submit" class="btn<?= $acompteActif ? '' : ' btn-plein' ?>"><?= $acompteActif ? 'Désactiver' : 'Réactiver' ?></button>
    </form>
</section>

<?php if ($afficherForm): ?>
<section class="resa-bloc form-admin" id="formulaire">
    <h2><?= $enEdition ? 'Modifier « ' . e($enEdition['nom']) . ' »' : 'Nouveau soin' ?></h2>

    <?php if ($erreurs): ?>
        <div class="alerte alerte-erreur" role="alert"><ul><?php foreach ($erreurs as $err): ?><li><?= e($err) ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="enregistrer">
        <input type="hidden" name="id" value="<?= (int) ($enEdition['id'] ?? 0) ?>">

        <div class="ligne-2">
            <div class="champ">
                <label for="nom">Nom du soin</label>
                <input type="text" id="nom" name="nom" maxlength="120" value="<?= e($f['nom'] ?? '') ?>" required>
            </div>
            <div class="champ">
                <label for="categorie_id">Catégorie</label>
                <select id="categorie_id" name="categorie_id" required>
                    <?php foreach ($categories as $c): ?>
                        <option value="<?= (int) $c['id'] ?>" <?= (int) ($f['categorie_id'] ?? 0) === (int) $c['id'] ? 'selected' : '' ?>><?= e($c['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="champ">
            <label for="description">Description (affichée sur le site)</label>
            <textarea id="description" name="description" rows="2" maxlength="500"><?= e($f['description'] ?? '') ?></textarea>
        </div>

        <div class="ligne-3">
            <div class="champ">
                <label for="duree_minutes">Durée</label>
                <select id="duree_minutes" name="duree_minutes">
                    <?php for ($m = 15; $m <= 240; $m += 15): ?>
                        <option value="<?= $m ?>" <?= (int) ($f['duree_minutes'] ?? 60) === $m ? 'selected' : '' ?>><?= format_duree($m) ?></option>
                    <?php endfor; ?>
                </select>
            </div>
            <div class="champ">
                <label for="prix_fcfa">Prix (FCFA)</label>
                <input type="number" id="prix_fcfa" name="prix_fcfa" min="0" step="500" value="<?= e((string) ($f['prix_fcfa'] ?? '')) ?>" required>
            </div>
            <div class="champ">
                <label for="acompte_fcfa">Acompte en ligne (FCFA)</label>
                <input type="number" id="acompte_fcfa" name="acompte_fcfa" min="0" step="500" value="<?= e((string) ($f['acompte_fcfa'] ?? 0)) ?>">
                <span class="aide">0 = la cliente paie le prix complet si elle choisit le paiement en ligne</span>
            </div>
        </div>

        <div class="champ">
            <label for="image">Photo (facultatif, JPG/PNG/WebP, 2 Mo max)</label>
            <?php if (!empty($enEdition['image'])): ?>
                <div class="apercu-image">
                    <img src="<?= BASE_URL ?>/assets/images/<?= e($enEdition['image']) ?>" alt="">
                    <label class="case"><input type="checkbox" name="retirer_image" value="1"> Retirer la photo</label>
                </div>
            <?php endif; ?>
            <input type="file" id="image" name="image" accept="image/jpeg,image/png,image/webp">
        </div>

        <label class="case"><input type="checkbox" name="actif" value="1" <?= !empty($f['actif']) ? 'checked' : '' ?>> Visible sur le site (réservable)</label>

        <div class="filtres-boutons">
            <button type="submit" class="btn btn-plein"><?= $enEdition ? 'Enregistrer' : 'Ajouter le soin' ?></button>
            <a href="<?= $urlPage ?>" class="btn">Annuler</a>
        </div>
    </form>
</section>
<?php endif; ?>

<div class="tableau-conteneur">
    <table class="tableau">
        <thead>
            <tr>
                <th>Soin</th>
                <th>Catégorie</th>
                <th>Durée</th>
                <th class="col-num">Prix</th>
                <th class="col-num">Acompte</th>
                <th>Réservations</th>
                <th>Site</th>
                <th><span class="sr-only">Actions</span></th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($prestations as $p): ?>
            <tr class="<?= $p['actif'] ? '' : 'ligne-inactive' ?>">
                <td>
                    <strong><?= e($p['nom']) ?></strong>
                    <?php if ($p['description']): ?><br><span class="discret petit-note"><?= e($p['description']) ?></span><?php endif; ?>
                </td>
                <td><?= e($p['categorie']) ?></td>
                <td class="nowrap"><?= format_duree((int) $p['duree_minutes']) ?></td>
                <td class="col-num"><?= format_fcfa((int) $p['prix_fcfa']) ?></td>
                <td class="col-num"><?= $p['acompte_fcfa'] > 0 ? format_fcfa((int) $p['acompte_fcfa']) : '<span class="discret">prix complet</span>' ?></td>
                <td class="nowrap"><?= (int) $p['a_venir'] ?> à venir</td>
                <td><span class="badge badge-<?= $p['actif'] ? 'ok' : 'neutre' ?>"><?= $p['actif'] ? 'Visible' : 'Masqué' ?></span></td>
                <td>
                    <div class="actions-ligne">
                        <a class="btn-action" href="?modifier=<?= (int) $p['id'] ?>#formulaire">Modifier</a>
                        <form method="post">
                            <?= csrf_field() ?>
                            <input type="hidden" name="action" value="basculer">
                            <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                            <button type="submit" class="btn-action"><?= $p['actif'] ? 'Masquer' : 'Afficher' ?></button>
                        </form>
                        <?php if ((int) $p['total_resas'] === 0): ?>
                            <form method="post" data-confirmer="Supprimer définitivement « <?= e($p['nom']) ?> » ?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="supprimer">
                                <input type="hidden" name="id" value="<?= (int) $p['id'] ?>">
                                <button type="submit" class="btn-action btn-action-danger">Supprimer</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>

<section class="bloc-compte" id="categories">
    <h2>Catégories</h2>
    <div class="grille-profil">
        <div class="liste-simple">
            <?php foreach ($categories as $i => $c): ?>
                <div class="ligne-simple">
                    <form method="post" class="form-renommer">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="renommer_categorie">
                        <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                        <label class="sr-only" for="cat<?= (int) $c['id'] ?>">Nom de la catégorie</label>
                        <input type="text" id="cat<?= (int) $c['id'] ?>" name="nom_categorie" value="<?= e($c['nom']) ?>">
                        <button type="submit" class="btn-action">Renommer</button>
                    </form>
                    <span class="discret petit-note"><?= (int) $c['nb'] ?> soin<?= $c['nb'] > 1 ? 's' : '' ?></span>
                    <div class="actions-ligne">
                        <?php foreach (['haut' => '↑', 'bas' => '↓'] as $sens => $fleche):
                            if (($sens === 'haut' && $i === 0) || ($sens === 'bas' && $i === count($categories) - 1)) continue; ?>
                            <form method="post">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="deplacer_categorie">
                                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                <input type="hidden" name="sens" value="<?= $sens ?>">
                                <button type="submit" class="btn-action" aria-label="Monter ou descendre"><?= $fleche ?></button>
                            </form>
                        <?php endforeach; ?>
                        <?php if ((int) $c['nb'] === 0): ?>
                            <form method="post" data-confirmer="Supprimer la catégorie « <?= e($c['nom']) ?> » ?">
                                <?= csrf_field() ?>
                                <input type="hidden" name="action" value="supprimer_categorie">
                                <input type="hidden" name="id" value="<?= (int) $c['id'] ?>">
                                <button type="submit" class="btn-action btn-action-danger">Supprimer</button>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <form method="post" class="resa-bloc">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="ajouter_categorie">
            <div class="champ">
                <label for="nom_categorie">Nouvelle catégorie</label>
                <input type="text" id="nom_categorie" name="nom_categorie" placeholder="Ex. Épilation, Massage…" required>
            </div>
            <button type="submit" class="btn">Ajouter la catégorie</button>
        </form>
    </div>
</section>

<?php require __DIR__ . '/../includes/admin_footer.php';
