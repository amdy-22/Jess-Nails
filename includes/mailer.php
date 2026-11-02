<?php
/**
 * Envoi d'emails transactionnels via l'API Brevo (sans SDK).
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

/** Habillage HTML commun des emails (styles en ligne pour les messageries). */
function gabarit_email(string $titre, string $contenu): string
{
    $nom = e(APP_NAME);
    return <<<HTML
<!DOCTYPE html>
<html lang="fr">
<body style="margin:0;padding:0;background:#f6f1f3;font-family:Arial,Helvetica,sans-serif;color:#2b1d2a;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f6f1f3;padding:24px 12px;">
    <tr><td align="center">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:560px;background:#ffffff;border-radius:12px;overflow:hidden;">
        <tr><td style="background:#8c3a5b;padding:20px 28px;color:#ffffff;font-family:Georgia,serif;font-size:24px;">{$nom}</td></tr>
        <tr><td style="padding:28px;">
          <h1 style="font-family:Georgia,serif;font-weight:normal;font-size:22px;margin:0 0 16px;">{$titre}</h1>
          {$contenu}
        </td></tr>
        <tr><td style="padding:16px 28px;background:#fbf8f9;color:#6e5f6c;font-size:12px;">
          {$nom} · Soins du visage, manucure et pédicure
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
}

/** Tableau "libellé : valeur" pour les récapitulatifs. Les valeurs doivent déjà être échappées. */
function tableau_email(array $lignes): string
{
    $html = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:16px 0;font-size:14px;">';
    foreach ($lignes as $libelle => $valeur) {
        $html .= '<tr>'
            . '<td style="padding:9px 0;border-bottom:1px solid #eee3e8;color:#6e5f6c;">' . e($libelle) . '</td>'
            . '<td style="padding:9px 0;border-bottom:1px solid #eee3e8;text-align:right;font-weight:bold;">' . $valeur . '</td>'
            . '</tr>';
    }
    return $html . '</table>';
}