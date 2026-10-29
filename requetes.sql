-- Requêtes d'impact pour l'export du 2026-11-20 à 18:00 (heure du Bénin).
-- Syntaxe SQLite pour le jour de semaine (strftime('%w') : 0 = dimanche, 6 = samedi).

-- TMA-101 : rendez-vous confirmés à venir qui ont été pris un samedi ou un dimanche.
SELECT id, npi, service, date_heure, statut
FROM rendez_vous
WHERE statut = 'confirme'
  AND datetime(date_heure) > datetime('2026-11-20 18:00:00')
  AND CAST(strftime('%w', date_heure) AS INTEGER) IN (0, 6)
ORDER BY date_heure, id;

-- TMA-102 : créneaux ayant plus de trois rendez-vous non annulés.
SELECT date_heure, COUNT(*) AS nombre_rendez_vous
FROM rendez_vous
WHERE statut <> 'annule'
GROUP BY date_heure
HAVING COUNT(*) > 3
ORDER BY date_heure;

-- TMA-105 : transitions historiques ayant fait sortir un rendez-vous du statut annulé.
SELECT h.id AS historique_id, r.id AS rendez_vous_id, h.ancien_statut,
       h.nouveau_statut, h.modifie_le, h.modifie_par
FROM historique_statuts AS h
JOIN rendez_vous AS r ON r.id = h.rendez_vous_id
WHERE h.ancien_statut = 'annule'
  AND h.nouveau_statut <> 'annule'
  AND datetime(h.modifie_le) <= datetime('2026-11-20 18:00:00')
ORDER BY h.modifie_le, h.id;

-- Bonus : rendez-vous réservés alors qu'un rendez-vous antérieur non annulé
-- avait déjà une date future à l'instant de cette réservation.
-- Les statuts sont ceux de l'export, pas leur état reconstitué à la réservation.
SELECT nouveau.id AS nouveau_rendez_vous_id,
       ancien.id AS rendez_vous_existant_id,
       nouveau.npi,
       ancien.service AS service_existant,
       nouveau.service AS nouveau_service,
       nouveau.cree_le AS nouvelle_reservation_le,
       ancien.date_heure AS rendez_vous_existant_le
FROM rendez_vous AS nouveau
JOIN rendez_vous AS ancien
  ON ancien.npi = nouveau.npi
 AND ancien.id <> nouveau.id
 AND datetime(ancien.cree_le) < datetime(nouveau.cree_le)
 AND datetime(ancien.date_heure) > datetime(nouveau.cree_le)
WHERE ancien.statut <> 'annule'
  AND nouveau.statut <> 'annule'
ORDER BY nouveau.cree_le, nouveau.id;

-- Bonus : conflits du même usager au même créneau, tous services confondus.
SELECT a.id AS rendez_vous_id_1,
       b.id AS rendez_vous_id_2,
       a.npi,
       a.date_heure,
       a.service AS service_1,
       b.service AS service_2
FROM rendez_vous AS a
JOIN rendez_vous AS b
  ON a.npi = b.npi
 AND a.date_heure = b.date_heure
 AND a.id < b.id
WHERE a.statut <> 'annule'
  AND b.statut <> 'annule'
ORDER BY a.date_heure, a.id, b.id;
