-- Étape 24 : rappels WhatsApp (envoyés depuis le tableau de bord)
-- À exécuter UNE fois sur chaque base (locale ET en ligne).

-- Date à laquelle l'admin a ouvert WhatsApp pour rappeler le rendez-vous (NULL = pas encore fait)
ALTER TABLE reservations
  ADD COLUMN rappel_whatsapp_le DATETIME NULL AFTER rappel_envoye_le;
