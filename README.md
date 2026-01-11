# cookbook

## Description


## Installation
### Requirements
- PHP 8.1 or higher
- Composer
- Docker and Docker Compose (for database and other services)


### Steps to Set Up the Project Locally

1. Clone the repository:
   ```bash
   git clone https://github.com/korhy/cookbook.git
    cd cookbook
    ```

2. Install dependencies using Composer:
   ```bash
   composer install
   ```
3. Configure your database connection in the `.env` file.

4. Start the Symfony local server:
   ```bash
   symfony server:start
   ```

5. Start Docker services (Database, mailer, etc.):
   ```bash
   docker-compose up -d
   ```

6. Run database migrations to set up the schema:
   ```bash
   php bin/console doctrine:migrations:migrate
   ```

7. (Optional) Load initial data(Check data section for more info about the file format):
    ```bash
    php -d memory_limit=1024M bin/console app:import-csv path/to/your/csv/file.csv --batch-size=25
    ```

8. Create an admin user to access the admin panel:
   ```bash
   php bin/console app:create-admin-user
   ```

9. Access the application at `http://localhost:8000`.

## Data Import
You can import recipes in bulk using the CSV import command. The CSV file should have the following columns:

- recipe_title
- category
- subcategory
- description
- ingredients
- directions
- num_ingredients
- num_steps

For my testing, I used a CSV file from [Kaggle - Recipe Ingredients and Reviews](https://www.kaggle.com/datasets/prashantsingh001/recipes-dataset-64k-dishes).