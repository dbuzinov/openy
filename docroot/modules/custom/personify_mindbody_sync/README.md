# Personify Mindbody Sync

The module have 2 syncers: fast and slow.

  * Fast proceeds withing last 24 hours.
  * Slow proceeds with all failed items.
 
By default Syncer is in DEBUG mode. In order to run it in production
you have to disable DEBUG mode.

To run the process use the next code:

  * With PHP:
  `ymca_sync_run("personify_mindbody_sync.syncer_fast", "proceed");`
  `ymca_sync_run("personify_mindbody_sync.syncer_slow", "proceed");`
  
  * With Drush:
  `drush ev 'ymca_sync_run("personify_mindbody_sync.syncer", "proceed");'`

## Help methods

### Clear cached entities

  `drush ev '\Drupal::service("personify_mindbody_sync.proxy")->clearCache();'`

## TODO

  * Phone validation, Birthday validation
  * Slow & fast pushers (slow should push clients one by one). 
