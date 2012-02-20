# Packagist Update Schedule

## How often does Packagist crawl newly added packages?

New packages will be crawled **within ten minutes**.


## How often does Packagist crawl existing packages?

Existing packages will be crawled **every hour**.


## How often does the Packagist search index update?

The search index is updated **every five minutes**. It will index (or reindex)
any package that has been crawled since the last time the search
indexer ran.


## Can Packagist be triggered to recrawl a package (on commit or by other means)?

Not yet. :) See [#84](https://github.com/composer/packagist/issues/84).