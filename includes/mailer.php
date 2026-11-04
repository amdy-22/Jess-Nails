<?php
/**
 * Envoi d'emails transactionnels via l'API Brevo (sans SDK).
 * Le gabarit (gabarit_email) habille tous les emails du site : un seul endroit à modifier.
 * Sans clé Brevo (en local), les emails sont enregistrés dans logs/emails/
 * pour que tu puisses les ouvrir dans le navigateur.
 */

function brevo_base(): string
{
    return (defined('BREVO_API_BASE') && BREVO_API_BASE !== '')
        ? rtrim(BREVO_API_BASE, '/')
        : 'https://api.brevo.com/v3';
}

/**
 * Envoie un email. $destinataires : [['email' => ..., 'name' => ...], ...]
 * Renvoie true si l'envoi (ou l'enregistrement local) a réussi.
 * Ne lève jamais d'exception : un email raté ne doit pas casser une réservation.
 */
function envoyer_email(array $destinataires, string $sujet, string $html, ?array $repondreA = null): bool
{
    $destinataires = array_values(array_filter($destinataires, fn($d) => filter_var($d['email'] ?? '', FILTER_VALIDATE_EMAIL)));
    if (!$destinataires) {
        return false;
    }

    // Pas de clé : on garde une copie locale de l'email (mode développement)
    if (BREVO_API_KEY === '') {
        $dossier = __DIR__ . '/../logs/emails';
        if (!is_dir($dossier)) {
            mkdir($dossier, 0775, true);
            // Interdit l'accès web au dossier logs/
            file_put_contents(__DIR__ . '/../logs/.htaccess', "Require all denied\n");
        }
        $nom = date('Ymd-His') . '-' . substr(md5($sujet . microtime()), 0, 6) . '.html';
        $entete = '<p style="font:13px monospace;background:#eee;padding:8px">À : '
            . e(implode(', ', array_column($destinataires, 'email'))) . '<br>Sujet : ' . e($sujet) . '</p>';
        return file_put_contents($dossier . '/' . $nom, $entete . $html) !== false;
    }

    $corps = [
        'sender'      => ['name' => MAIL_FROM_NAME, 'email' => MAIL_FROM_EMAIL],
        'to'          => $destinataires,
        'subject'     => $sujet,
        'htmlContent' => $html,
        'textContent' => texte_brut($html),
    ];
    if ($repondreA) {
        $corps['replyTo'] = $repondreA;
    }

    $ch = curl_init(brevo_base() . '/smtp/email');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_TIMEOUT        => 15,
        CURLOPT_HTTPHEADER     => [
            'api-key: ' . BREVO_API_KEY,
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_POSTFIELDS     => json_encode($corps),
    ]);
    $reponse = curl_exec($ch);
    $code    = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);

    if ($reponse === false || $code >= 300) {
        error_log('[Brevo] Échec envoi "' . $sujet . '" : HTTP ' . $code . ' ' . ($reponse ?: curl_error($ch)));
        return false;
    }
    return true;
}

// =====================================================
// Habillage des emails
// Styles en ligne : Gmail, Outlook et les applis mobiles ignorent souvent les feuilles de style.
// Volontairement sobre (pas de grande image ni de bandeau) : un email qui ressemble
// à une newsletter part plus facilement dans l'onglet Promotions de Gmail.
// =====================================================
const EMAIL_ACCENT  = '#8c3a5b';
const EMAIL_TEXTE   = '#2b1d2a';
const EMAIL_DISCRET = '#7a6a77';
const EMAIL_FOND    = '#f4eef1';
const EMAIL_LIGNE   = '#eadfe5';
const EMAIL_SERIF   = "Georgia,'Times New Roman',serif";
const EMAIL_SANS    = "-apple-system,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

/**
 * Habillage HTML commun.
 * $apercu : texte affiché après le sujet dans la liste des emails (par défaut, le titre).
 */
function gabarit_email(string $titre, string $contenu, ?string $apercu = null): string
{
    if (is_file(__DIR__ . '/contact.php')) {
        require_once __DIR__ . '/contact.php';   // lien WhatsApp (étape 15)
    }

    $nom     = e(APP_NAME);
    $titreH  = e($titre);
    $apercuH = e($apercu ?? $titre);
    $accent  = EMAIL_ACCENT;
    $texte   = EMAIL_TEXTE;
    $discret = EMAIL_DISCRET;
    $fond    = EMAIL_FOND;
    $ligne   = EMAIL_LIGNE;
    $serif   = EMAIL_SERIF;
    $sans    = EMAIL_SANS;
    $annee   = date('Y');

    // Coordonnées en pied de page (seulement celles qui sont renseignées)
    $coord = [];
    if (SALON_ADRESSE !== '') {
        $coord[] = e(SALON_ADRESSE);
    }
    if (SALON_TELEPHONE !== '') {
        $coord[] = '<a href="tel:' . e(SALON_TELEPHONE) . '" style="color:' . $discret . ';text-decoration:none;">' . e(SALON_TELEPHONE) . '</a>';
    }
    if (function_exists('lien_whatsapp') && ($wa = lien_whatsapp())) {
        $coord[] = '<a href="' . e($wa) . '" style="color:' . $accent . ';text-decoration:none;">WhatsApp</a>';
    }
    $coordonnees = $coord ? '<p style="margin:0 0 8px;">' . implode(' &nbsp;·&nbsp; ', $coord) . '</p>' : '';
    $site = e(BASE_URL);

    // Paragraphes : marges et couleurs homogènes, même sans <style>
    $contenu = preg_replace('#<p>#', '<p style="margin:0 0 14px;">', $contenu);

    return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="color-scheme" content="light">
<meta name="supported-color-schemes" content="light">
<title>{$titreH}</title>
<style>
  @media (max-width: 600px) {
    .em-carte { padding: 26px 20px !important; }
    .em-titre { font-size: 22px !important; }
  }
  a { color: {$accent}; }
</style>
</head>
<body style="margin:0;padding:0;background:{$fond};-webkit-text-size-adjust:100%;">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0;color:{$fond};">{$apercuH}&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;&nbsp;&zwnj;</div>
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:{$fond};">
    <tr><td align="center" style="padding:32px 12px;">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;">

        <tr><td align="center" style="padding:0 0 18px;font-family:{$serif};font-size:26px;letter-spacing:.5px;color:{$accent};">
          <a href="{$site}" style="color:{$accent};text-decoration:none;">{$nom}</a>
        </td></tr>

        <tr><td class="em-carte" style="background:#ffffff;border:1px solid {$ligne};border-top:4px solid {$accent};border-radius:16px;padding:34px 36px;font-family:{$sans};font-size:15px;line-height:1.6;color:{$texte};">
          <h1 class="em-titre" style="margin:0 0 20px;font-family:{$serif};font-weight:normal;font-size:26px;line-height:1.25;color:{$texte};">{$titreH}</h1>
          {$contenu}
        </td></tr>

        <tr><td align="center" style="padding:22px 16px 0;font-family:{$sans};font-size:12px;line-height:1.6;color:{$discret};">
          <p style="margin:0 0 8px;font-family:{$serif};font-size:15px;color:{$texte};">{$nom}</p>
          {$coordonnees}
          <p style="margin:0 0 8px;">
            <a href="{$site}/profile" style="color:{$discret};">Mon compte</a> &nbsp;·&nbsp;
            <a href="{$site}/conditions" style="color:{$discret};">Conditions</a> &nbsp;·&nbsp;
            <a href="{$site}/confidentialite" style="color:{$discret};">Confidentialité</a>
          </p>
          <p style="margin:0;color:#a3949f;">Message automatique lié à ton rendez-vous. Tu peux y répondre directement.<br>&copy; {$annee} {$nom}</p>
        </td></tr>

      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

/** Récapitulatif "libellé : valeur" dans un encadré. Les valeurs doivent déjà être échappées. */
function tableau_email(array $lignes): string
{
    $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" '
        . 'style="border-collapse:separate;margin:20px 0 22px;background:#faf6f8;border:1px solid ' . EMAIL_LIGNE . ';border-radius:12px;">';
    $n = count($lignes);
    $i = 0;
    foreach ($lignes as $libelle => $valeur) {
        $bordure = ++$i < $n ? 'border-bottom:1px solid ' . EMAIL_LIGNE . ';' : '';
        $html .= '<tr>'
            . '<td style="padding:12px 18px;' . $bordure . 'font-size:13px;color:' . EMAIL_DISCRET . ';vertical-align:top;white-space:nowrap;">' . e($libelle) . '</td>'
            . '<td style="padding:12px 18px;' . $bordure . 'font-size:14px;font-weight:bold;color:' . EMAIL_TEXTE . ';text-align:right;vertical-align:top;">' . $valeur . '</td>'
            . '</tr>';
    }
    return $html . '</table>';
}

/** Bouton compatible avec toutes les messageries (y compris Outlook). */
function bouton_email(string $texte, string $url, bool $secondaire = false): string
{
    $fond   = $secondaire ? '#ffffff' : EMAIL_ACCENT;
    $couleur = $secondaire ? EMAIL_ACCENT : '#ffffff';
    return '<table role="presentation" cellpadding="0" cellspacing="0" style="margin:24px 0 8px;"><tr>'
        . '<td style="border-radius:999px;background:' . $fond . ';border:1px solid ' . EMAIL_ACCENT . ';">'
        . '<a href="' . e($url) . '" style="display:inline-block;padding:13px 26px;font-family:' . EMAIL_SANS . ';font-size:15px;'
        . 'font-weight:bold;color:' . $couleur . ';text-decoration:none;border-radius:999px;">' . e($texte) . '</a>'
        . '</td></tr></table>';
}

/** Version texte d'un email HTML (Gmail la préfère pour les messages transactionnels). */
function texte_brut(string $html): string
{
    $html = preg_replace('#<(head|style)\b.*?</\1>#is', '', $html);
    $html = preg_replace('#<div style="display:none.*?</div>#is', '', $html);   // texte d'aperçu
    $html = preg_replace('#<(br|/p|/tr|/h1|/div)\b[^>]*>#i', "\n", $html);
    $html = preg_replace('#</td>\s*<td[^>]*>#i', ' : ', $html);
    $html = preg_replace('#<a\s[^>]*href="([^"]+)"[^>]*>(.*?)</a>#is', '$2 ($1)', $html);
    $texte = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $texte = preg_replace("/[ \t\x{00A0}\x{200C}]+/u", ' ', $texte);
    $texte = preg_replace("/^ +| +$/m", '', $texte);
    return trim(preg_replace("/\n{3,}/", "\n\n", $texte));
}
