# cookbook

## Description

A Symfony-based recipe management application that allows you to organize, browse, and manage your cookbook. Features include recipe categorization, ingredient tracking, step-by-step instructions, and an admin panel for easy content management. Exposes a REST API built with API Platform, secured with JWT authentication. Supports bulk importing recipes from CSV files.

## Requirements
- PHP 8.1 or higher
- Composer
- Docker and Docker Compose (for database and other services)

## Installation

1. Clone the repository:
   ```bash
   git clone https://github.com/korhy/cookbook.git
   cd cookbook
   ```

2. Install dependencies:
   ```bash
   composer install
   ```

3. Configure your database connection in the `.env` file.

4. Start Docker services (database, mailer, etc.):
   ```bash
   docker-compose up -d
   ```

5. Run database migrations:
   ```bash
   php bin/console doctrine:migrations:migrate
   ```

6. (Optional) Import initial data:
   ```bash
   php -d memory_limit=1024M bin/console app:import-csv --skip-header --batch-size=50
   ```

7. Create an admin user:
   ```bash
   symfony console security:hash-password
   ```
   Enter your desired password and copy the hashed output. Then run:
   ```bash
   symfony run psql -c "INSERT INTO admin (id, username, roles, password) VALUES (nextval('admin_id_seq'), 'admin', '[\"ROLE_ADMIN\"]', 'your_hashed_password_here');"
   ```

8. Access the application at `http://localhost:8000`.

## API

The application exposes a REST API built with [API Platform](https://api-platform.com/).

- Documentation: `http://localhost:8000/api`
- Authentication: JWT (Bearer token)

### Get a token:
```bash
curl -X POST http://localhost:8000/api/login_check \
  -H "Content-Type: application/json" \
  -d '{"username": "admin", "password": "your_password"}'
```

### Use the token:
```bash
curl -H "Authorization: Bearer <token>" http://localhost:8000/api/recipes
```

## Data Import

CSV files must be placed in `public/data/` with the following formats:

| File | Format |
|------|--------|
| `recipe_categories.csv` | `name,id` |
| `ingredients.csv` | `name,id` |
| `recipes_final.csv` | `id,recipe_title,description,id_category` |
| `recipe_ingredients.csv` | `id_recipe,quantity,id_unit,id_ingredient` |
| `recipe_instructions.csv` | `id_recipe,content,position` |

```bash
php -d memory_limit=1024M bin/console app:import-csv [options]

Options:
  --skip-header       Skip the first row (header)
  --batch-size=50     Number of records per batch (default: 50)
  --dry-run           Preview import without saving
  --delimiter=";"     CSV delimiter (default: ,)
```

## Testing

### Setting Up the Test Environment

1. Create the test database:
   ```bash
   php bin/console doctrine:database:create --env=test
   ```

2. Run migrations on the test database:
   ```bash
   php bin/console doctrine:migrations:migrate --env=test --no-interaction
   ```

### Running Tests

Run all tests:
```bash
php bin/phpunit
```

Run a specific test:
```bash
php bin/phpunit tests/Entity/RecipeTest.php
```

Run with coverage (requires Xdebug):
```bash
php bin/phpunit --coverage-html var/coverage
```

### Test Structure

The project includes:
- **Unit tests**: Testing entities and services (e.g., `RecipeTest`, `SluggerServiceTest`)
- **Functional tests**: Testing controllers and HTTP responses (e.g., `SecurityControllerTest`)
- **Repository tests**: Testing database queries and data retrieval
