# Lisa — Backend API

API Laravel pour l'application iOS Lisa.

## Installation locale

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan storage:link
php artisan serve
```

## Déploiement Hostinger

```bash
# Sur le serveur
composer install --no-dev --optimize-autoloader
cp .env.example .env
# Remplir .env avec les vraies valeurs
php artisan key:generate
php artisan migrate --force
php artisan db:seed --force
php artisan config:cache
php artisan route:cache
php artisan storage:link
```

## Variables d'environnement requises

- `DB_PASSWORD` — Mot de passe base de données
- `OPENAI_API_KEY` — Clé API OpenAI
- `MAIL_PASSWORD` — Mot de passe SMTP Hostinger

## Compte test

- Email: `test@lisa.fr`
- Mot de passe: `Lisa2026!`

## Endpoints API

| Méthode | Route | Description |
|---------|-------|-------------|
| POST | /api/login | Connexion |
| GET | /api/me | Profil utilisateur |
| GET | /api/days | Liste des jours |
| GET | /api/days/{date} | Détail d'un jour |
| POST | /api/days/{date}/rooms | Créer une chambre |
| POST | /api/rooms/{id}/patients | Créer un patient |
| GET | /api/patients/{id} | Fiche patient |
| POST | /api/patients/{id}/vitals | Saisir constantes |
| POST | /api/voice-notes | Enregistrer note vocale |
| POST | /api/voice-notes/structure | Structurer avec IA |
| POST | /api/patients/{id}/generate-checklist | Générer checklist IA |
| GET | /api/patients/{id}/checklist | Voir checklist |
| PUT | /api/checklist/{id} | Cocher/décocher |
