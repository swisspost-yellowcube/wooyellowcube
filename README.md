# WooYellowCube
WooYellowCube allow the synchronization between WooCommerce and YellowCube (Swiss Post).

Swiss Post offers an all-in logistics solution for distance selling with YellowCube. The range of services covers goods receipt, storage, picking, packaging, fast shipping, and returns management.

Requirements:
* WordPress 4.9.8+, tested up to 5.3
* WooCommerce 3.4.7+, tested up to 3.8.0
* PHP 7+, tested up to 7.2

CAUTION:
* Before updating to WordPress 5.x, update WooCommerce first!
* NOT tested with Gutenberg

## Documentation

[Official documentation](https://swisspost-yellowcube.github.io/wooyellowcube-docs/). Documentation is publicly maintained and improved. We encourage you [to submit changes](https://github.com/swisspost-yellowcube/wooyellowcube-docs/pull/new/master) or [report issues](https://github.com/swisspost-yellowcube/wooyellowcube-docs/issues/new) if you notice and errors or inconsistencies.

## Crons
### WordPress based
There is 3 crons that are executed by used timestamp difference. Theses crons need to got a frontend or backend visit to be triggered.

Please refer to the next section to integrate crons with a server cron-job system.

**Every 60 seconds difference** :
$yellowcube->cron_response();
_Get article and orders results_

**Every hour (effectively 30 minutes) difference** :
$yellowcube->cron_hourly();
_Get WAR results_
(This interval was adjusted from hourly.)

**Every day difference** :
$yellowcube->cron_daily();
_Get the inventory (BAR)_

### Cron-job system
Endpoint to call cron-jobs :
* http://example.com/?cron_response=true
* http://example.com/?cron_hourly=true
* http://example.com/?cron_daily=true
