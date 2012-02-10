# Packagist Update Schedule

## How often does Packagist crawl newly added packages?

New packages will be crawled **every five minutes**.


## How often does Packagist crawl existing packages?

Existing packages will be crawled **every hour**.


## How often does the Packagist search index update?

The search index is rebuilt **every hour**. It will index (or reindex)
any package that has been crawled since the last time the search
indexer ran.


## Can Packagist be triggered to recrawl a package (on commit or by other means)?

Not yet. :) Want to help? See
[#81](https://github.com/composer/packagist/issues/81)
and [#67](https://github.com/composer/packagist/issues/67)