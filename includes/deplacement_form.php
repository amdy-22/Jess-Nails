<?php
/**
 * Choix du nouvel horaire d'un rendez-vous (partagé par public/deplacer.php et admin/deplacer.php).
 * Variables attendues :
 *   $resa (avec soin, duree_minutes, praticienne, debut), $jour, $pratId, $praticiennesActives,
 *   $creneaux (null si pas encore de date), $fermeture, $dateMin, $dateMax, $estAdmin
 */
$ancienDebut = new DateTimeImmutable($resa['debut']);
?>
<div class="etapes-admin">
    <section class="resa-bloc">
        <h2>Rendez-vous actuel</h2>
        <div class="cliente-choisie">
            <div>
                <strong><?= e($resa['soin']) ?></strong> (<?= format_duree((int) $resa['duree_minutes']) ?>)<br>
                <span class="discret"><?= e(ucfirst(format_date_fr($ancienDebut, true))) ?> · avec <?= e($resa['praticienne']) ?></span>
                <?php if ($estAdmin): ?><br><span class="discret">Cliente : <?= e($resa['prenom'] . ' ' . $resa['nom_client']) ?> · <?= e($resa['telephone']) ?></span><?php endif; ?>
            </div>
        </div>
    </section>

    <form method="get" class="resa-bloc" data-envoi-auto>
        <h2><span class="num">1</span> Nouvelle date</h2>
        <input type="hidden" name="reservation" value="<?= (int) $resa['id'] ?>">
        <div class="ligne-2">
            <div class="champ">
                <label for="date">Date</label>
                <input type="date" id="date" name="date" value="<?= e($jour ? $jour->format('Y-m-d') : '') ?>"
                       min="<?= $dateMin->format('Y-m-d') ?>" max="<?= $dateMax->format('Y-m-d') ?>" required>
            </div>
            <div class="champ">
                <label for="praticienne">Praticienne</label>
                <select id="praticienne" name="praticienne">
                    <option value="0">Sans préférence</option>
                    <?php foreach ($praticiennesActives as $p): ?>
                        <option value="<?= (int) $p['id'] ?>" <?= $pratId === (int) $p['id'] ? 'selected' : '' ?>><?= e($p['nom']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <button type="submit" class="btn">Voir les horaires libres</button>
    </form>

    <section class="resa-bloc">
        <h2><span class="num">2</span> Nouvel horaire</h2>

        <?php if ($creneaux === null): ?>
            <p class="discret">Choisis d'abord une date.</p>
        <?php elseif ($fermeture !== null): ?>
            <p class="alerte alerte-info">L'institut est fermé ce jour-là (<?= e($fermeture) ?>).</p>
        <?php elseif (!$creneaux): ?>
            <p class="alerte alerte-info">Aucun horaire libre ce jour-là<?= $pratId ? ' avec cette praticienne. Essaie « Sans préférence » ou' : '. Essaie' ?> une autre date.</p>
        <?php else: ?>
            <form method="post">
                <?= csrf_field() ?>
                <input type="hidden" name="reservation" value="<?= (int) $resa['id'] ?>">
                <input type="hidden" name="date" value="<?= e($jour->format('Y-m-d')) ?>">
                <input type="hidden" name="praticienne" value="<?= $pratId ?>">

                <p class="discret"><?= e(ucfirst(format_date_fr($jour))) ?></p>
                <?php foreach (grouper_creneaux($creneaux) as $moment => $heures): ?>
                    <fieldset class="groupe-creneaux">
                        <legend><?= e($moment) ?></legend>
                        <div class="creneaux">
                            <?php foreach ($heures as $heure):
                                $idc = 'h' . str_replace(':', '', $heure);
                                $actuel = $jour->format('Y-m-d') === $ancienDebut->format('Y-m-d') && $heure === $ancienDebut->format('H:i'); ?>
                                <input type="radio" name="heure" id="<?= $idc ?>" value="<?= e($heure) ?>" required <?= $actuel ? 'disabled' : '' ?>>
                                <label for="<?= $idc ?>" <?= $actuel ? 'class="creneau-actuel" title="Horaire actuel"' : '' ?>><?= e(str_replace(':', 'h', $heure)) ?></label>
                            <?php endforeach; ?>
                        </div>
                    </fieldset>
                <?php endforeach; ?>

                <?php if ($estAdmin): ?>
                    <?php if ($resa['email']): ?>
                        <label class="case"><input type="checkbox" name="prevenir" value="1" checked> Prévenir la cliente par email (<?= e($resa['email']) ?>)</label>
                    <?php else: ?>
                        <p class="discret petit-note">Cette cliente n'a pas d'email : préviens-la par téléphone ou WhatsApp.</p>
                    <?php endif; ?>
                <?php endif; ?>

                <button type="submit" class="btn btn-plein btn-large">Déplacer le rendez-vous</button>
                <p class="discret petit-note">Le soin, le prix et l'acompte éventuel restent les mêmes.</p>
            </form>
        <?php endif; ?>
    </section>
</div>
