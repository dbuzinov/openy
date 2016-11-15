(function ($) {

  Drupal.behaviors.ymca_retention_progress = {};
  Drupal.behaviors.ymca_retention_progress.attach = function (context, settings) {
    if ($('body').hasClass('ymca-retention-progress-processed')) {
      return;
    }
    $('body').addClass('ymca-retention-progress-processed');

    Drupal.ymca_retention.angular_app.controller('ProgressController', function (storage) {
      var self = this;
      // Shared information.
      self.storage = storage;

      self.dateClass = function (date) {
        var classes = [];
        if (date.future) {
          classes.push('progress-row__disabled');
        }
        return classes.join(' ');
      };

      self.activityGroupClass = function (timestamp, activity_group) {
        var classes = [];
        classes.push('item-activity-type__' + activity_group.retention_activity_id);

        if (self.activitiesCount(timestamp, activity_group)) {
          classes.push('active');
        }

        return classes.join(' ');
      };

      self.checkInClass = function (timestamp) {
        if (typeof self.storage.member_checkins === 'undefined') {
          return '';
        }
        var classes = [];
        if (self.storage.member_checkins[timestamp] == 1) {
          classes.push('active');
          classes.push('check');
        }

        return classes.join(' ');
      };

      self.checkInStatus = function (date) {
        if (typeof self.storage.member_checkins === 'undefined' || date.future) {
          return '—';
        }
        if (self.storage.member_checkins[date.timestamp] == 1) {
          return Drupal.t('check-in');
        }

        return Drupal.t('no records');
      };

      self.activitiesCount = function (timestamp, activity_group) {
        if (typeof self.storage.member_activities_counts === 'undefined'
          || !self.storage.member_activities_counts) {
          return 0;
        }

        return self.storage.member_activities_counts[timestamp][activity_group.id];
      };
    });
  };

})(jQuery);
