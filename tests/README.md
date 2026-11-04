# Tests (development only — never deploy this folder)

## Unit tests — no database, no network
    php tests/run.php            # all
    php tests/run.php phone      # filter by name

## Integration tests — disposable sandbox install
Copies the git-tracked source into a temp dir with a **synthetic** `config.php`, creates the schema
like `install.php` does, seeds synthetic users and serves it with `php -S 127.0.0.1:8099`.

    EB_TEST_DB_HOST=127.0.0.1 EB_TEST_DB_PORT=3306 EB_TEST_DB_NAME=ebtest EB_TEST_DB_USER=… EB_TEST_DB_PASS=… \
      php tests/integration/run.php

⚠️ The database named in `EB_TEST_DB_NAME` is **dropped and recreated**; the name must start with `ebtest`.
Never point these variables at a real database. Without them the suite is skipped.

## API policy map
After adding an API action or changing page JavaScript:

    php tools/gen_api_policy.php          # regenerate core/ApiPolicy.map.php
    php tools/gen_api_policy.php --check  # what the unit tests run
