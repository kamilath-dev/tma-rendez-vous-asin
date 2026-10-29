# Étude de cas TMA — rendez-vous au guichet

## Choix technique

Le module est écrit en PHP pur et ne dépend ni d'un framework ni d'une base de données. Le fuseau métier est `Africa/Porto-Novo` (heure du Bénin). Les tests utilisent uniquement PHP CLI, sans dépendance externe.
Il requiert **PHP 8.1 ou ultérieur** (types `mixed`, propriétés promues et `readonly`).

## Lancer les tests

Depuis la racine du projet :

```powershell
php tests/run.php
```

Le runner affiche chaque cas et retourne un code de sortie non nul en cas d'échec.
Résultat vérifié : **18 tests réussis, 0 échec**.

Pour charger l'export et exécuter toutes les requêtes avec le client SQLite, depuis la racine du projet :

```powershell
sqlite3 rendez-vous.db ".read base_rendez_vous.sql"
sqlite3 rendez-vous.db ".read requetes.sql"
```

Le second fichier contient les trois requêtes d'impact demandées et deux requêtes complémentaires pour le bonus.

## Qualification, diagnostic et correction des tickets

| Ticket | Qualification | Cause probable | Correction et test |
|---|---|---|---|
| TMA-101 | Anomalie. Le samedi 14 novembre a été accepté alors que le centre est fermé le week-end. | Cause probable : contrôle du jour mal borné ou mauvais index de jour, laissant passer le samedi. L'indice est l'acceptation du samedi dans la trace et les 8 rendez-vous du samedi dans l'export, contre aucun le dimanche. Cela oriente vers un contrôle qui exclut le dimanche mais pas le samedi ; le code original n'étant pas fourni, la condition précise reste une hypothèse. | Vérification explicite des deux jours du week-end avec le numéro ISO (`N > 5`) ; test du samedi du ticket, du dimanche refusé et du vendredi accepté. |
| TMA-102 | Anomalie. Le créneau du 10 novembre à 09:00 contient quatre rendez-vous, alors que le maximum est trois. | Cause probable : borne de capacité décalée, par exemple un test `nombre > 3` avant insertion au lieu de refuser dès `nombre >= 3`. L'indice est que les trois créneaux surchargés dans l'export contiennent chacun exactement quatre rendez-vous. Les quatre réservations (#632, #644, #681, #697) ont été créées à des dates différentes (28 octobre, 30 octobre, 2 novembre et 4 novembre) : une course simultanée n'explique donc pas ce cas. | Refus dès que trois rendez-vous non annulés existent déjà ; tests d'un troisième rendez-vous accepté et d'un quatrième refusé. Une transaction reste une précaution nécessaire contre une course future en production, mais n'explique pas cette trace. |
| TMA-103 | Anomalie. Un NPI contenant une lettre doit renvoyer HTTP 400, pas 500. | Cause probable : le NPI est converti en nombre ou traité par une validation qui déclenche une exception, puis cette erreur n'est pas transformée en réponse métier HTTP 400. La trace montre que le NPI alphanumérique atteint un chemin qui répond 500 au lieu du message de validation. | Contrôle du type chaîne et de ses 10 chiffres avant tout autre traitement ; test TMA-103 vérifiant le code et le message, plus saisies JSON mal typées. |
| TMA-104 | Anomalie. Du 17 novembre à 18 h au 19 novembre à 10 h, il reste 40 heures : l'annulation est permise. | Cause probable : calcul du délai sur une composante (par exemple les heures de l'horloge ou un reste modulo 24) plutôt que sur la durée totale. L'indice est que les 40 heures restantes ont été interprétées comme moins de 24 heures, alors qu'elles dépassent la limite autorisée. | Comparaison des instants avec la borne `maintenant + 24 heures` ; test TMA-104 et tests à moins de 24 h et à la borne exacte. |
| TMA-105 | Anomalie. `annule` est définitif ; seuls les rendez-vous `confirme` peuvent passer à `honore` ou `absent`. | La trace montre qu'une transition est acceptée depuis `annule`, ce qui indique que le statut courant n'est pas correctement vérifié (ou que l'historique est écrasé). | Seuls les statuts confirmés sont modifiables ; un statut final produit HTTP 409. Test TMA-105 et test des statuts inconnus. |
| TMA-106 | Anomalie. Le rendez-vous du 2 décembre devrait être en tête, mais n'apparaît pas sur la première page. | Cause probable : tri alphabétique des dates formatées en `JJ/MM/AAAA HH:MM`, qui ne respecte pas l'ordre chronologique. En triant les 13 dates de la trace sous ce format, le 2 décembre arrive en 12e position, ce qui explique son absence de la première page. | Comparaison des dates comme objets date/heure, rendez-vous confirmés à venir d'abord, puis autres rendez-vous par date décroissante ; tri avant pagination ; test TMA-106 avec les 13 dates et un rendez-vous futur annulé. |
| TMA-107 | Comportement conforme. Un usager ne peut avoir qu'un rendez-vous à venir, tous services confondus. | Pas d'anomalie : la règle s'applique également lorsqu'il s'agit de deux services différents. | Aucun correctif ; test TMA-107 vérifiant le refus du deuxième rendez-vous à venir. |

Les refus métier renvoient les codes HTTP et les messages prévus par le sujet. Le module reçoit l'heure courante en paramètre pour rendre les règles temporelles testables.

## Résultats des requêtes d'impact

Les requêtes sont dans [`requetes.sql`](./requetes.sql). Elles utilisent la syntaxe SQLite pour détecter le jour de semaine (`strftime`), et fixent l'instant d'observation à celui de l'export, le 20 novembre 2026 à 18 h (Bénin).

1. **Rendez-vous confirmés à venir le week-end : 5**
   - #680 — 2026-11-21 08:30
   - #730 — 2026-11-28 09:00
   - #834 — 2026-12-05 10:00
   - #857 — 2026-12-05 10:00
   - #874 — 2026-12-12 11:00
2. **Créneaux avec plus de trois rendez-vous non annulés : 3**
   - 2026-10-22 10:30 — 4 rendez-vous (#502, #511, #517, #519)
   - 2026-11-10 09:00 — 4 rendez-vous (#632, #644, #681, #697)
   - 2026-11-24 14:00 — 4 rendez-vous (#728, #750, #763, #783)
3. **Transitions depuis `annule` : 4**
   - Historique #346, rendez-vous #208 : `annule` → `honore`
   - Historique #430, rendez-vous #231 : `annule` → `honore`
   - Historique #547, rendez-vous #262 : `annule` → `absent`
   - Historique #676, rendez-vous #292 : `annule` → `honore`

## Anomalies supplémentaires relevées dans l'export

- Deux NPI ne font pas 10 chiffres : rendez-vous #459 (`01234567890`) et #642 (`061425364711`). Ils doivent être signalés/corrigés selon les informations source, et rejetés lors de toute nouvelle saisie.
- Trois rendez-vous dépassent le dernier créneau autorisé de 15:30 : #490 (2026-10-27 16:00), #698 (2026-11-13 16:30) et #842 (2026-12-08 16:00). Les nouvelles réservations à ces heures sont refusées par le contrôle de grille.
- L'export contient 8 rendez-vous le samedi, dont les 5 rendez-vous confirmés à venir listés ci-dessus. Il faut prévenir les usagers concernés et empêcher de nouvelles réservations le week-end.
- Les transitions historiques montrent quatre changements depuis `annule` (dont le cas #292 du ticket TMA-105) ; ces transitions doivent être auditées et bloquées à l'avenir.
- Trois rendez-vous sont encore `confirme` alors que leur date est passée à l'instant de l'export (#475, #715 et #742). Les faire vérifier par le centre pour enregistrer leur issue ; le module ne change pas automatiquement leur statut.
- En utilisant les statuts présents dans l'export et en comparant chaque réservation avec les rendez-vous antérieurs du même NPI dont la date était encore à venir lors de cette réservation, on trouve **44 réservations concernées et 46 paires** de rendez-vous non annulés dans l'export ; **27 paires** concernent des services différents. C'est un signal à auditer, pas la preuve que la règle a été violée à l'instant de chaque réservation : l'export ne conserve pas à lui seul l'état complet de chaque rendez-vous à ces instants.
- Les rendez-vous #43 et #99 ont le même NPI et le même créneau (2026-09-01 09:30), avec deux services différents (`PERMIS` et `PASSEPORT`). C'est un conflit exact d'usager/créneau à examiner ; les deux lignes sont aujourd'hui `honore`, ce qui justifie une vérification métier.
- Les rendez-vous #475, #715 et #742, encore `confirme` alors qu'ils sont passés, sont tous un samedi. Le #742 est celui du signalement TMA-101. Ce rapprochement renforce le besoin de vérifier les dossiers, mais ne prouve pas à lui seul ce qui s'est passé au guichet.
- Le NPI du rendez-vous #642 (`061425364711`) commence par le NPI du rendez-vous #742 (`0614253647`) suivi de `11`. Cela peut être une erreur de saisie, mais il faut confirmer auprès de la source avant toute correction.

Le module constitue la couche de règles, pas une API ni un accès aux données. Dans une intégration réelle, il faut exécuter les contrôles et les écritures correspondantes dans une transaction afin d'éviter les dépassements de capacité sous concurrence.
