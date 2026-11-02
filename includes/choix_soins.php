<?php
/**
 * Cases « Ajouter un autre soin » (formulaire de réservation, côté cliente et côté admin).
 * Attend : $soins (id, nom, duree_minutes, prix_fcfa, categorie) et $prestation (résultat de combiner_soins, ou null).
 */
$autresChoisis = $prestation ? $prestation['autres'] : [];
$idPrincipal   = $prestation ? (int) $prestation['id'] : 0;
?>
<details class="soins-supp" data-max="<?= MAX_SOINS_PAR_RESA - 1 ?>" <?= $autresChoisis || !empty($_REQUEST['plus']) ? 'open' : '' ?>>
    <summary>Ajouter un autre soin <span class="discret">(facultatif, <?= MAX_SOINS_PAR_RESA - 1 ?> au plus)</span></summary>
    <p class="discret petit-note">Les soins s'enchaînent avec la même praticienne.</p>
    <?php $cat = null; foreach ($soins as $s):
        if ((int) $s['id'] === $idPrincipal) continue;
        if ($s['categorie'] !== $cat): $cat = $s['categorie']; ?>
            <p class="soins-supp-cat"><?= e($cat) ?></p>
        <?php endif; ?>
        <label class="case soin-case">
            <input type="checkbox" name="autres[]" value="<?= (int) $s['id'] ?>" <?= in_array((int) $s['id'], $autresChoisis, true) ? 'checked' : '' ?>>
            <span><?= e($s['nom']) ?></span>
            <span class="discret"><?= format_duree((int) $s['duree_minutes']) ?> · <?= format_fcfa((int) $s['prix_fcfa']) ?></span>
        </label>
    <?php endforeach; ?>
</details>

<?php if ($prestation && count($prestation['soins']) > 1): ?>
    <p class="total-soins">
        <strong><?= count($prestation['soins']) ?> soins</strong> ·
        <?= format_duree((int) $prestation['duree_minutes']) ?> ·
        <?= format_fcfa((int) $prestation['prix_fcfa']) ?>
    </p>
<?php endif; ?>
