# ANALYSE COMPARATIVE - MODULE PHARMACIE
## Deux systèmes en parallèle à harmoniser

---

## ?? SYSTÈME 1 : CSK_SERVICES (Existant - Workflow moderne)

### Base de données : `csk_services`
### Pages disponibles :
1. **dashboard.php** - Vue d'ensemble avec statistiques temps réel
2. **preparations.php** - Liste filtrable des préparations
3. **workflow.php** - Gestion transitions d'état (Kanban + Détail)

### Fonctionnalités clés :
- ✅ **Workflow complet** : 9 statuts avec transitions contrôlées
- ✅ **Traçabilité** : Historique complet dans `pharmacie_workflow_history`
- ✅ **Alertes** : Stock bas, préparations urgentes, retards
- ✅ **Tableaux Kanban** : Vue par colonnes de statut
- ✅ **Délais** : Calcul automatique SLA (1h urgente, 4h normale)
- ✅ **Personnel** : Tracking pharmacien par étape

### Tables utilisées :
```sql
pharmacie_preparations (table principale)
├── Champs workflow : statut, urgence, code_preparation
├── Champs timing : created_at, date_verification, date_debut_preparation, etc.
├── Champs personnel : pharmacien_verif, pharmacien_prep, etc.
└── Liens : idpharma_presc, idpatient, idsous_sejour

pharmacie_workflow_history
├── Historique de toutes les transitions
└── idpreparation, ancien_statut, nouveau_statut, action, idutilisateur
```

### Forces :
- Architecture moderne et scalable
- UX optimale (Kanban, filtres, recherche)
- Traçabilité exhaustive
- Gestion fine des délais

### Lacunes :
- ❌ Pas de gestion du stock
- ❌ Pas de réquisitions
- ❌ Pas d'inventaire
- ❌ Pas de catalogue produits
- ❌ Pas d'officines multiples

---

## ?? SYSTÈME 2 : CSK_BASE (Ancien - Gestion stock)

### Base de données : `csk_base`
### Pages disponibles :
1. **index.php** - Menu d'accueil avec cartes
2. **officine.php** - Sélection officine + prescriptions en attente
3. **stock-general.php** - Vue globale stock tous produits
4. **stock-officine.php** - Stock d'une officine spécifique
5. **depot-central.php** - Entrées en stock (fournisseurs)
6. **produits.php** - Catalogue produits (CRUD)
7. **requisition.php** - Demandes officine → dépôt
8. **detail-requisition.php** - Détail d'une réquisition
9. **traiter-prescription.php** - Liste prescriptions par séjour
10. **delivrer.php** - Délivrance + déduction stock
11. **achever-prescription.php** - Alias de delivrer.php (doublon)
12. **sortie-directe.php** - Sorties sans prescription
13. **inventaire.php** - Ajustement stock physique
14. **rapports.php** - Statistiques ventes

### Fonctionnalités clés :
- ✅ **Gestion stock** : Multi-officines avec stockpharma
- ✅ **Réquisitions** : Circuit officine → dépôt central
- ✅ **Inventaire** : Ajustements avec historique
- ✅ **Entrées** : Réception fournisseurs
- ✅ **Sorties directes** : Hors prescriptions
- ✅ **Catalogue** : CRUD complet produits
- ✅ **Rapports** : Top produits, chiffre d'affaire

### Tables utilisées :
```sql
prodpharma (catalogue)
├── libelle, code, type_produit, forme, voie
├── prix_achat, prix_vente_externe
└── seuil_alerte, seuil_reappro

stockpharma (stock par officine)
├── idprodpharma, idofficine
└── quantite

officine (officines du site)
├── nom, idsite
└── actif

pharma_presc (prescriptions)
├── statut_execution : en_attente, acheve
├── quantite, posologie, prix_unitaire, montant_total
└── date_prescription, date_execution, urgent

requisition + lignesrecquisition
├── numero_requisition, statut (en_attente, servi, refuse)
└── quantite_demandee, quantite_servie

pharma_entrees (entrées fournisseurs)
├── idfournisseur, quantite, prix_achat
└── date_entree, idutilisateur

sortie_directe (sorties hors prescription)
├── idofficine, idprodpharma, quantite, motif
└── date_sortie, idutilisateur

inventaire_ajustements (écarts stock)
├── quantite_theorique, quantite_reelle, ecart
└── observation, date_ajustement
```

### Forces :
- Gestion stock complète et mature
- Multi-officines
- Circuit réquisition rodé
- Traçabilité entrées/sorties

### Lacunes :
- ❌ Workflow prescription basique (2 statuts)
- ❌ Pas de suivi étapes préparation
- ❌ Pas de Kanban
- ❌ UX datée (tableaux simples)
- ❌ Pas de calcul délais SLA

---

## ?? ANALYSE DES DOUBLONS

### 1. **delivrer.php** vs **achever-prescription.php**
**DOUBLON COMPLET** - Les deux font exactement la même chose :
- Afficher détails prescription
- Vérifier stock
- Déduire quantité du stock
- Marquer prescription comme `acheve`
- Notifier prescripteur

**DÉCISION** : Garder uniquement `delivrer.php` (nom plus clair)

### 2. **traiter-prescription.php** vs **workflow.php (mode détail)**
**FONCTIONS SIMILAIRES** mais contextes différents :
- `traiter-prescription.php` : Liste prescriptions d'un séjour (vue officine)
- `workflow.php` : Gestion d'une préparation (vue workflow)

**DÉCISION** : Garder les deux mais harmoniser l'UX

### 3. **rapports.php** vs **rapport.php** (à créer)
**NOM SIMILAIRE** :
- `rapports.php` existant : Statistiques basiques (CA, top produits)
- `rapport.php` à créer : Statistiques avancées (graphiques Chart.js)

**DÉCISION** : Fusionner en un seul `rapport.php` enrichi

---

## ??️ ARCHITECTURE CIBLE HARMONISÉE

### Principe directeur :
**"Le meilleur des deux mondes"**
1. **Workflow moderne** (Système 1) pour le suivi préparations
2. **Gestion stock complète** (Système 2) pour la logistique
3. **Pont entre les deux** : pharma_presc est la source unique

### Structure finale proposée :

```
modules/pharmacie/
├── dashboard.php          [Système 1] - Vue d'ensemble
├── preparations.php       [Système 1] - Liste avec filtres
├── workflow.php           [Système 1] - Kanban + Détail
│
├── stock-general.php      [Système 2] - Stock global
├── stock-officine.php     [Système 2] - Stock par officine
├── produits.php           [Système 2] - Catalogue CRUD
│
├── officine.php           [Système 2 amélioré] - Hub officine
├── traiter-sejour.php     [Nouveau] - Fusion traiter-prescription + workflow
├── delivrer.php           [Système 2 amélioré] - Délivrance
│
├── depot-central.php      [Système 2] - Entrées stock
├── requisition.php        [Système 2] - Créer réquisition
├── detail-requisition.php [Système 2] - Voir réquisition
│
├── sortie-directe.php     [Système 2] - Sorties hors presc
├── inventaire.php         [Système 2] - Ajustements
├── rapport.php            [Nouveau] - Fusion rapports + stats avancées
```

### Sidebar organisation :

```
?? PHARMACIE
├── ?? Dashboard
├── ?? Préparations
├── ?? Workflow
│
└── ?? GESTION STOCK
    ├── ?? Stock général
    ├── ?? Officines
    ├── ?? Inventaire
    └── ?? Dépôt central
│
└── ?? CATALOGUE
    └── ?? Produits
│
└── ?? STATISTIQUES
    └── ?? Rapport
```

---

## ?? PONT ENTRE LES SYSTÈMES

### Comment lier `pharmacie_preparations` et `pharma_presc` ?

**Option 1 : Lien direct (RECOMMANDÉ)**
```sql
ALTER TABLE pharmacie_preparations
ADD COLUMN idpharma_presc INT,
ADD FOREIGN KEY (idpharma_presc) REFERENCES csk_base.pharma_presc(idpharma_presc);
```

**Flux** :
1. Prescription créée dans `pharma_presc` (statut: `en_attente`)
2. Trigger crée automatiquement ligne dans `pharmacie_preparations` (statut: `attente`)
3. Workflow avance dans `pharmacie_preparations`
4. À la délivrance finale, MAJ `pharma_presc.statut_execution = 'acheve'`

**Option 2 : Sync bidirectionnelle (COMPLEXE)**
Maintenir les deux tables indépendantes avec sync par triggers

**DÉCISION** : **Option 1** - Lien direct via `idpharma_presc`

---

## ?? PLAN D'IMPLÉMENTATION

### Phase 1 : Migration SQL (30 min)
```sql
-- 1. Ajouter colonnes pont
ALTER TABLE pharmacie_preparations 
ADD COLUMN idpharma_presc INT AFTER idpreparation,
ADD INDEX idx_pharma_presc (idpharma_presc);

-- 2. Créer trigger auto-création préparation
DELIMITER $$
CREATE TRIGGER after_pharma_presc_insert
AFTER INSERT ON csk_base.pharma_presc
FOR EACH ROW
BEGIN
    IF NEW.source_prescription = 'csk_services' THEN
        INSERT INTO csk_services.pharmacie_preparations (
            idpharma_presc, idpatient, idsous_sejour, 
            code_preparation, urgence, statut, created_at
        )
        VALUES (
            NEW.idpharma_presc,
            (SELECT s.idpatient FROM csk_base.sous_sejour ss 
             JOIN csk_base.sejour s ON ss.idsejour = s.idsejour 
             WHERE ss.idsous_sejour = NEW.idsous_sejour),
            NEW.idsous_sejour,
            CONCAT('PREP-', DATE_FORMAT(NOW(), '%Y%m%d'), '-', LPAD(LAST_INSERT_ID(), 6, '0')),
            NEW.urgent,
            'attente',
            NOW()
        );
    END IF;
END$$
DELIMITER ;

-- 3. Créer trigger MAJ statut prescription à la délivrance
DELIMITER $$
CREATE TRIGGER after_preparation_delivree
AFTER UPDATE ON csk_services.pharmacie_preparations
FOR EACH ROW
BEGIN
    IF NEW.statut = 'delivree' AND OLD.statut != 'delivree' THEN
        UPDATE csk_base.pharma_presc
        SET statut_execution = 'acheve',
            date_execution = NOW(),
            executeur = NEW.pharmacien_delivrance
        WHERE idpharma_presc = NEW.idpharma_presc;
    END IF;
END$$
DELIMITER ;
```

### Phase 2 : Harmonisation pages (1h)
1. ✅ Garder `dashboard.php`, `preparations.php`, `workflow.php` (Système 1)
2. ✅ Garder `stock-*.php`, `produits.php`, `depot-central.php` (Système 2)
3. ✅ Améliorer `officine.php` avec style moderne
4. ✅ Supprimer `achever-prescription.php` (doublon)
5. ✅ Créer `traiter-sejour.php` (fusion intelligente)
6. ✅ Créer `rapport.php` enrichi (fusion + graphiques)

### Phase 3 : Sidebar + routeur (30 min)
Mettre à jour `layout.php` et `index.php` avec nouvelle structure

### Phase 4 : Tests (30 min)
- Créer prescription → Vérifier création auto dans pharmacie_preparations
- Avancer workflow → Vérifier cohérence
- Délivrer → Vérifier MAJ statut prescription + déduction stock

---

## ✅ DÉCISIONS FINALES

### À GARDER du Système 1 (Workflow) :
- dashboard.php
- preparations.php
- workflow.php
- Toute la logique de transitions
- Tableaux Kanban

### À GARDER du Système 2 (Stock) :
- stock-general.php
- stock-officine.php
- produits.php
- depot-central.php
- requisition.php + detail-requisition.php
- sortie-directe.php
- inventaire.php
- officine.php (amélioré)

### À SUPPRIMER :
- ❌ achever-prescription.php (doublon de delivrer.php)

### À CRÉER :
- ✅ rapport.php (fusion rapports.php + stats avancées)
- ✅ traiter-sejour.php (vue hybride officine/workflow)

### À AMÉLIORER :
- delivrer.php : Intégrer dans workflow (transition finale)
- traiter-prescription.php : Moderniser UX, ajouter filtres

---

## ?? COHÉRENCE VISUELLE

Tous les modules doivent suivre le même design :
- **Couleur primaire pharmacie** : `#198754` (vert)
- **Icons** : Font Awesome / Bootstrap Icons
- **Cards** : border-0 shadow-sm
- **Badges** : bg-success, bg-warning, bg-danger selon statut
- **Tableaux** : table-hover avec pagination

---

## ?? LIVRABLES FINAUX

1. **migration_pharmacie_complete.sql** - Tous les ALTER + triggers
2. **modules/pharmacie/** - 14 fichiers PHP harmonisés
3. **layout.php** - Sidebar mise à jour
4. **index.php** - Routeur pharmacie complet
5. **README_PHARMACIE.md** - Documentation utilisateur

---

## ?? PRÊT À IMPLÉMENTER ?

Cette analyse permet de :
1. ✅ Comprendre les forces de chaque système
2. ✅ Identifier les doublons à supprimer
3. ✅ Définir l'architecture cible
4. ✅ Planifier l'implémentation par phases
5. ✅ Garantir la cohérence fonctionnelle et visuelle

**Prochaine étape** : Générer les fichiers SQL + PHP selon ce plan ?
"
    },
    "message": "Analyse comparative des deux systèmes pharmacie",
    "integration_name": null,
    "integration_icon_url": null,
    "icon_name": "file",
    "context": null,
    "display_content": {
      "type": "json_block",
      "json_block": "{"language": "markdown", "code": "# ANALYSE COMPARATIVE - MODULE PHARMACIE\
## Deux systèmes en parallèle à harmoniser\
\
---\
\
## ?? SYSTÈME 1 : CSK_SERVICES (Existant - Workflow moderne)\
\
### Base de données : `csk_services`\
### Pages disponibles :\
1. **dashboard.php** - Vue d'ensemble avec statistiques temps réel\
2. **preparations.php** - Liste filtrable des préparations\
3. **workflow.php** - Gestion transitions d'état (Kanban + Détail)\
\
### Fonctionnalités clés :\
- ✅ **Workflow complet** : 9 statuts avec transitions contrôlées\
- ✅ **Traçabilité** : Historique complet dans `pharmacie_workflow_history`\
- ✅ **Alertes** : Stock bas, préparations urgentes, retards\
- ✅ **Tableaux Kanban** : Vue par colonnes de statut\
- ✅ **Délais** : Calcul automatique SLA (1h urgente, 4h normale)\
- ✅ **Personnel** : Tracking pharmacien par étape\
\
### Tables utilisées :\
```sql\
pharmacie_preparations (table principale)\
├── Champs workflow : statut, urgence, code_preparation\
├── Champs timing : created_at, date_verification, date_debut_preparation, etc.\
├── Champs personnel : pharmacien_verif, pharmacien_prep, etc.\
└── Liens : idpharma_presc, idpatient, idsous_sejour\
\
pharmacie_workflow_history\
├── Historique de toutes les transitions\
└── idpreparation, ancien_statut, nouveau_statut, action, idutilisateur\
```\
\
### Forces :\
- Architecture moderne et scalable\
- UX optimale (Kanban, filtres, recherche)\
- Traçabilité exhaustive\
- Gestion fine des délais\
\
### Lacunes :\
- ❌ Pas de gestion du stock\
- ❌ Pas de réquisitions\
- ❌ Pas d'inventaire\
- ❌ Pas de catalogue produits\
- ❌ Pas d'officines multiples\
\
---\
\
## ?? SYSTÈME 2 : CSK_BASE (Ancien - Gestion stock)\
\
### Base de données : `csk_base`\
### Pages disponibles :\
1. **index.php** - Menu d'accueil avec cartes\
2. **officine.php** - Sélection officine + prescriptions en attente\
3. **stock-general.php** - Vue globale stock tous produits\
4. **stock-officine.php** - Stock d'une officine spécifique\
5. **depot-central.php** - Entrées en stock (fournisseurs)\
6. **produits.php** - Catalogue produits (CRUD)\
7. **requisition.php** - Demandes officine → dépôt\
8. **detail-requisition.php** - Détail d'une réquisition\
9. **traiter-prescription.php** - Liste prescriptions par séjour\
10. **delivrer.php** - Délivrance + déduction stock\
11. **achever-prescription.php** - Alias de delivrer.php (doublon)\
12. **sortie-directe.php** - Sorties sans prescription\
13. **inventaire.php** - Ajustement stock physique\
14. **rapports.php** - Statistiques ventes\
\
### Fonctionnalités clés :\
- ✅ **Gestion stock** : Multi-officines avec stockpharma\
- ✅ **Réquisitions** : Circuit officine → dépôt central\
- ✅ **Inventaire** : Ajustements avec historique\
- ✅ **Entrées** : Réception fournisseurs\
- ✅ **Sorties directes** : Hors prescriptions\
- ✅ **Catalogue** : CRUD complet produits\
- ✅ **Rapports** : Top produits, chiffre d'affaire\
\
### Tables utilisées :\
```sql\
prodpharma (catalogue)\
├── libelle, code, type_produit, forme, voie\
├── prix_achat, prix_vente_externe\
└── seuil_alerte, seuil_reappro\
\
stockpharma (stock par officine)\
├── idprodpharma, idofficine\
└── quantite\
\
officine (officines du site)\
├── nom, idsite\
└── actif\
\
pharma_presc (prescriptions)\
├── statut_execution : en_attente, acheve\
├── quantite, posologie, prix_unitaire, montant_total\
└── date_prescription, date_execution, urgent\
\
requisition + lignesrecquisition\
├── numero_requisition, statut (en_attente, servi, refuse)\
└── quantite_demandee, quantite_servie\
\
pharma_entrees (entrées fournisseurs)\
├── idfournisseur, quantite, prix_achat\
└── date_entree, idutilisateur\
\
sortie_directe (sorties hors prescription)\
├── idofficine, idprodpharma, quantite, motif\
└── date_sortie, idutilisateur\
\
inventaire_ajustements (écarts stock)\
├── quantite_theorique, quantite_reelle, ecart\
└── observation, date_ajustement\
```\
\
### Forces :\
- Gestion stock complète et mature\
- Multi-officines\
- Circuit réquisition rodé\
- Traçabilité entrées/sorties\
\
### Lacunes :\
- ❌ Workflow prescription basique (2 statuts)\
- ❌ Pas de suivi étapes préparation\
- ❌ Pas de Kanban\
- ❌ UX datée (tableaux simples)\
- ❌ Pas de calcul délais SLA\
\
---\
\
## ?? ANALYSE DES DOUBLONS\
\
### 1. **delivrer.php** vs **achever-prescription.php**\
**DOUBLON COMPLET** - Les deux font exactement la même chose :\
- Afficher détails prescription\
- Vérifier stock\
- Déduire quantité du stock\
- Marquer prescription comme `acheve`\
- Notifier prescripteur\
\
**DÉCISION** : Garder uniquement `delivrer.php` (nom plus clair)\
\
### 2. **traiter-prescription.php** vs **workflow.php (mode détail)**\
**FONCTIONS SIMILAIRES** mais contextes différents :\
- `traiter-prescription.php` : Liste prescriptions d'un séjour (vue officine)\
- `workflow.php` : Gestion d'une préparation (vue workflow)\
\
**DÉCISION** : Garder les deux mais harmoniser l'UX\
\
### 3. **rapports.php** vs **rapport.php** (à créer)\
**NOM SIMILAIRE** :\
- `rapports.php` existant : Statistiques basiques (CA, top produits)\
- `rapport.php` à créer : Statistiques avancées (graphiques Chart.js)\
\
**DÉCISION** : Fusionner en un seul `rapport.php` enrichi\
\
---\
\
## ??️ ARCHITECTURE CIBLE HARMONISÉE\
\
### Principe directeur :\
**\"Le meilleur des deux mondes\"**\
1. **Workflow moderne** (Système 1) pour le suivi préparations\
2. **Gestion stock complète** (Système 2) pour la logistique\
3. **Pont entre les deux** : pharma_presc est la source unique\
\
### Structure finale proposée :\
\
```\
modules/pharmacie/\
├── dashboard.php          [Système 1] - Vue d'ensemble\
├── preparations.php       [Système 1] - Liste avec filtres\
├── workflow.php           [Système 1] - Kanban + Détail\
│\
├── stock-general.php      [Système 2] - Stock global\
├── stock-officine.php     [Système 2] - Stock par officine\
├── produits.php           [Système 2] - Catalogue CRUD\
│\
├── officine.php           [Système 2 amélioré] - Hub officine\
├── traiter-sejour.php     [Nouveau] - Fusion traiter-prescription + workflow\
├── delivrer.php           [Système 2 amélioré] - Délivrance\
│\
├── depot-central.php      [Système 2] - Entrées stock\
├── requisition.php        [Système 2] - Créer réquisition\
├── detail-requisition.php [Système 2] - Voir réquisition\
│\
├── sortie-directe.php     [Système 2] - Sorties hors presc\
├── inventaire.php         [Système 2] - Ajustements\
├── rapport.php            [Nouveau] - Fusion rapports + stats avancées\
```\
\
### Sidebar organisation :\
\
```\
?? PHARMACIE\
├── ?? Dashboard\
├── ?? Préparations\
├── ?? Workflow\
│\
└── ?? GESTION STOCK\
    ├── ?? Stock général\
    ├── ?? Officines\
    ├── ?? Inventaire\
    └── ?? Dépôt central\
│\
└── ?? CATALOGUE\
    └── ?? Produits\
│\
└── ?? STATISTIQUES\
    └── ?? Rapport\
```\
\
---\
\
## ?? PONT ENTRE LES SYSTÈMES\
\
### Comment lier `pharmacie_preparations` et `pharma_presc` ?\
\
**Option 1 : Lien direct (RECOMMANDÉ)**\
```sql\
ALTER TABLE pharmacie_preparations\
ADD COLUMN idpharma_presc INT,\
ADD FOREIGN KEY (idpharma_presc) REFERENCES csk_base.pharma_presc(idpharma_presc);\
```\
\
**Flux** :\
1. Prescription créée dans `pharma_presc` (statut: `en_attente`)\
2. Trigger crée automatiquement ligne dans `pharmacie_preparations` (statut: `attente`)\
3. Workflow avance dans `pharmacie_preparations`\
4. À la délivrance finale, MAJ `pharma_presc.statut_execution = 'acheve'`\
\
**Option 2 : Sync bidirectionnelle (COMPLEXE)**\
Maintenir les deux tables indépendantes avec sync par triggers\
\
**DÉCISION** : **Option 1** - Lien direct via `idpharma_presc`\
\
---\
\
## ?? PLAN D'IMPLÉMENTATION\
\
### Phase 1 : Migration SQL (30 min)\
```sql\
-- 1. Ajouter colonnes pont\
ALTER TABLE pharmacie_preparations \
ADD COLUMN idpharma_presc INT AFTER idpreparation,\
ADD INDEX idx_pharma_presc (idpharma_presc);\
\
-- 2. Créer trigger auto-création préparation\
DELIMITER $$\
CREATE TRIGGER after_pharma_presc_insert\
AFTER INSERT ON csk_base.pharma_presc\
FOR EACH ROW\
BEGIN\
    IF NEW.source_prescription = 'csk_services' THEN\
        INSERT INTO csk_services.pharmacie_preparations (\
            idpharma_presc, idpatient, idsous_sejour, \
            code_preparation, urgence, statut, created_at\
        )\
        VALUES (\
            NEW.idpharma_presc,\
            (SELECT s.idpatient FROM csk_base.sous_sejour ss \
             JOIN csk_base.sejour s ON ss.idsejour = s.idsejour \
             WHERE ss.idsous_sejour = NEW.idsous_sejour),\
            NEW.idsous_sejour,\
            CONCAT('PREP-', DATE_FORMAT(NOW(), '%Y%m%d'), '-', LPAD(LAST_INSERT_ID(), 6, '0')),\
            NEW.urgent,\
            'attente',\
            NOW()\
        );\
    END IF;\
END$$\
DELIMITER ;\
\
-- 3. Créer trigger MAJ statut prescription à la délivrance\
DELIMITER $$\
CREATE TRIGGER after_preparation_delivree\
AFTER UPDATE ON csk_services.pharmacie_preparations\
FOR EACH ROW\
BEGIN\
    IF NEW.statut = 'delivree' AND OLD.statut != 'delivree' THEN\
        UPDATE csk_base.pharma_presc\
        SET statut_execution = 'acheve',\
            date_execution = NOW(),\
            executeur = NEW.pharmacien_delivrance\
        WHERE idpharma_presc = NEW.idpharma_presc;\
    END IF;\
END$$\
DELIMITER ;\
```\
\
### Phase 2 : Harmonisation pages (1h)\
1. ✅ Garder `dashboard.php`, `preparations.php`, `workflow.php` (Système 1)\
2. ✅ Garder `stock-*.php`, `produits.php`, `depot-central.php` (Système 2)\
3. ✅ Améliorer `officine.php` avec style moderne\
4. ✅ Supprimer `achever-prescription.php` (doublon)\
5. ✅ Créer `traiter-sejour.php` (fusion intelligente)\
6. ✅ Créer `rapport.php` enrichi (fusion + graphiques)\
\
### Phase 3 : Sidebar + routeur (30 min)\
Mettre à jour `layout.php` et `index.php` avec nouvelle structure\
\
### Phase 4 : Tests (30 min)\
- Créer prescription → Vérifier création auto dans pharmacie_preparations\
- Avancer workflow → Vérifier cohérence\
- Délivrer → Vérifier MAJ statut prescription + déduction stock\
\
---\
\
## ✅ DÉCISIONS FINALES\
\
### À GARDER du Système 1 (Workflow) :\
- dashboard.php\
- preparations.php\
- workflow.php\
- Toute la logique de transitions\
- Tableaux Kanban\
\
### À GARDER du Système 2 (Stock) :\
- stock-general.php\
- stock-officine.php\
- produits.php\
- depot-central.php\
- requisition.php + detail-requisition.php\
- sortie-directe.php\
- inventaire.php\
- officine.php (amélioré)\
\
### À SUPPRIMER :\
- ❌ achever-prescription.php (doublon de delivrer.php)\
\
### À CRÉER :\
- ✅ rapport.php (fusion rapports.php + stats avancées)\
- ✅ traiter-sejour.php (vue hybride officine/workflow)\
\
### À AMÉLIORER :\
- delivrer.php : Intégrer dans workflow (transition finale)\
- traiter-prescription.php : Moderniser UX, ajouter filtres\
\
---\
\
## ?? COHÉRENCE VISUELLE\
\
Tous les modules doivent suivre le même design :\
- **Couleur primaire pharmacie** : `#198754` (vert)\
- **Icons** : Font Awesome / Bootstrap Icons\
- **Cards** : border-0 shadow-sm\
- **Badges** : bg-success, bg-warning, bg-danger selon statut\
- **Tableaux** : table-hover avec pagination\
\
---\
\
## ?? LIVRABLES FINAUX\
\
1. **migration_pharmacie_complete.sql** - Tous les ALTER + triggers\
2. **modules/pharmacie/** - 14 fichiers PHP harmonisés\
3. **layout.php** - Sidebar mise à jour\
4. **index.php** - Routeur pharmacie complet\
5. **README_PHARMACIE.md** - Documentation utilisateur\
\
---\
\
## ?? PRÊT À IMPLÉMENTER ?\
\
Cette analyse permet de :\
1. ✅ Comprendre les forces de chaque système\
2. ✅ Identifier les doublons à supprimer\
3. ✅ Définir l'architecture cible\
4. ✅ Planifier l'implémentation par phases\
5. ✅ Garantir la cohérence fonctionnelle et visuelle\
\
**Prochaine étape** : Générer les fichiers SQL + PHP selon ce plan ?\
", "filename": "/home/claude/ANALYSE_PHARMACIE_COMPARATIVE.md"}"
    },
    "approval_options": null,
    "approval_key": null,
    "is_mcp_app": null
  },
  {
    "start_timestamp": null,
    "stop_timestamp": null,
    "flags": null,
    "type": "tool_result",
    "tool_use_id": "toolu_012VLfYEWojYUZnGHFUwa5VC",
    "name": "create_file",
    "content": [
      {
        "type": "text",
        "text": "File created successfully: /home/claude/ANALYSE_PHARMACIE_COMPARATIVE.md",
        "uuid": "9f2975ad-89c7-466c-8f69-1520cf2f65d0"
      }
    ],
    "is_error": false,
    "structured_content": null,
    "meta": null,
    "message": null,
    "integration_name": null,
    "integration_icon_url": null,
    "icon_name": "file",
    "display_content": {
      "type": "text",
      "text": "File created successfully: /home/claude/ANALYSE_PHARMACIE_COMPARATIVE.md"
    }
  },
  {
    "start_timestamp": null,
    "stop_timestamp": null,
    "flags": null,
    "type": "token_budget