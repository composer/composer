# This workflow uses actions that are not certified by GitHub.
# They are provided by a third-party and are governed by
# separate terms of service, privacy policy, and support
# documentation.

name: Symfony

on:
  push:
    branches: [ "main" ]
  pull_request:
    branches: [ "main" ]

permissions:
  contents: read

jobs:
  symfony-tests:
    runs-on: ubuntu-latest
    steps:
    #  To automatically get bug fixes and new Php versions for shivammathur/setup-php,
    # change this to (see https://github.com/shivammathur/setup-php#bookmark-versioning):
    # uses: shivammathur/setup-php@v2
    - uses: shivammathur/setup-php@2cb9b829437ee246e9b3cac53555a39208ca6d28
      with:
        php-version: '8.0'
    - uses: actions/checkout@v4
    - name: Copy .env.test.local
      run: php -r "file_exists('.env.test.local') || copy('.env.test', '.env.test.local');"
    - name: Cache Composer packages
      id: composer-cache
      uses: actions/cache@v3
      with:
        path: vendor
        key: ${{ runner.os }}-php-${{ hashFiles('**/composer.lock') }}
        restore-keys: |
          ${{ runner.os }}-php-
    - name: Install Dependencies
      run: composer install -q --no-ansi --no-interaction --no-scripts --no-progress --prefer-dist
    - name: Create Database
      run: |
        mkdir -p data
        touch data/database.sqlite
    - name: Execute tests (Unit and Feature tests) via PHPUnit
      env:
        DATABASE_URL: sqlite:///%kernel.project_dir%/data/database.sqlite
      run: vendor/bin/phpunit
