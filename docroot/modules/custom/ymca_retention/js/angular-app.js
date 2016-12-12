(function($) {

  Drupal.behaviors.ymca_retention_angular_app = {};
  Drupal.behaviors.ymca_retention_angular_app.attach = function (context, settings) {
    if ($('body').hasClass('ymca-retention-angular-app-processed')) {
      return;
    }
    $('body').addClass('ymca-retention-angular-app-processed');

    Drupal.ymca_retention = Drupal.ymca_retention || {};
    Drupal.ymca_retention.angular_app = Drupal.ymca_retention.angular_app || angular.module('Retention', ['ngCookies', 'ajoslin.promise-tracker']);

    Drupal.ymca_retention.angular_app.controller('RetentionController', function (storage) {
      var self = this;
      // Shared information.
      self.storage = storage;

      self.instantWinClass = function() {
        var classes = [];
        if (!self.storage.instantWinCount) {
          classes.push('empty');
        }
        return classes.join(' ');
      };
    });

    // Service to communicate with backend.
    Drupal.ymca_retention.angular_app.factory('courier', function($http, $q, $cookies, $httpParamSerializerJQLike) {
      function getMember(id) {
        var deferred = $q.defer();
        if (typeof id === 'undefined') {
          deferred.resolve(null);
        }
        else {
          $http.get(settings.ymca_retention.resources.member).then(function(response) {
            if ($.isEmptyObject(response.data)) {
              // We've got empty result - remove the member cookie.
              $cookies.remove('Drupal.visitor.ymca_retention_member', { path: '/' });
              deferred.resolve(null);
              return;
            }

            deferred.resolve(response.data);
          });
        }

        return deferred.promise;
      }

      /**
       * Get information about member check-in history.
       * @param id Member Id.
       * @returns {*}
       */
      function getMemberCheckIns(id) {
        var deferred = $q.defer();
        if (typeof id === 'undefined') {
          deferred.resolve(null);
        }
        else {
          $http.get(settings.ymca_retention.resources.member_checkins).then(function (response) {
            if ($.isEmptyObject(response.data)) {
              deferred.resolve(null);
              return;
            }

            deferred.resolve(response.data);
          });
        }

        return deferred.promise;
      }

      function getMemberActivities(id) {
        var deferred = $q.defer();
        if (typeof id === 'undefined') {
          deferred.resolve(null);
        }
        else {
          $http.get(settings.ymca_retention.resources.member_activities).then(function(response) {
            if ($.isEmptyObject(response.data)) {
              deferred.resolve(null);
              return;
            }

            deferred.resolve(response.data);
          });
        }

        return deferred.promise;
      }

      function setMemberActivities(data) {
        var id = $cookies.get('Drupal.visitor.ymca_retention_member'),
          deferred = $q.defer();
        if (typeof id === 'undefined') {
          deferred.resolve(null);
        }
        else {
          $http({
            method: 'POST',
            url: settings.ymca_retention.resources.member_activities,
            data: $httpParamSerializerJQLike(data),
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded'
            }
          }).then(function(response) {
            if ($.isEmptyObject(response.data)) {
              deferred.resolve(null);
              return;
            }

            deferred.resolve(response.data);
          });
        }

        return deferred.promise;
      }

      function getMemberChances(id) {
        var deferred = $q.defer();
        if (typeof id === 'undefined') {
          deferred.resolve(null);
        }
        else {
          $http.get(settings.ymca_retention.resources.member_chances).then(function(response) {
            if ($.isEmptyObject(response.data)) {
              deferred.resolve(null);
              return;
            }

            deferred.resolve(response.data);
          });
        }

        return deferred.promise;
      }

      function getMemberPrize() {
        var id = $cookies.get('Drupal.visitor.ymca_retention_member'),
          deferred = $q.defer();
        if (typeof id === 'undefined') {
          deferred.resolve(null);
        }
        else {
          $http({
            method: 'POST',
            url: settings.ymca_retention.resources.member_chances,
            // data: $httpParamSerializerJQLike(data),
            headers: {
              'Content-Type': 'application/x-www-form-urlencoded'
            }
          }).then(function(response) {
            if ($.isEmptyObject(response.data)) {
              deferred.resolve(null);
              return;
            }

            deferred.resolve(response.data);
          });
        }

        return deferred.promise;
      }

      function getRecentWinners() {
        var deferred = $q.defer();
        $http.get(settings.ymca_retention.resources.recent_winners).then(function(response) {
          if ($.isEmptyObject(response.data)) {
            deferred.resolve(null);
            return;
          }

          deferred.resolve(response.data);
        });

        return deferred.promise;
      }

      return {
        getMember: getMember,
        getMemberCheckIns: getMemberCheckIns,
        getMemberActivities: getMemberActivities,
        setMemberActivities: setMemberActivities,
        getMemberChances: getMemberChances,
        getMemberPrize: getMemberPrize,
        getRecentWinners: getRecentWinners
      };
    });

    // Service to hold information shared between controllers.
    Drupal.ymca_retention.angular_app.service('storage', function($rootScope, $interval, $timeout, $cookies, $filter, promiseTracker, courier) {
      var self = this;

      // Initiate the promise tracker to track submissions.
      self.progress = promiseTracker();

      // Force to check cookie value.
      $interval(function() {
        $cookies.get('Drupal.visitor.ymca_retention_member');
      }, 500);

      // Watch cookie value and update data on change.
      $rootScope.$watch(function() {
        return $cookies.get('Drupal.visitor.ymca_retention_member');
      }, function(newVal, oldVal) {
        self.getMember(newVal);
        self.getMemberChancesById(newVal);
        self.getMemberActivities(newVal);
        self.getMemberCheckIns(newVal);
        self.state = 'game';
      });

      // Watch member activities and update counts.
      $rootScope.$watch(function() {
        return self.member_activities;
      }, function(newVal, oldVal) {
        self.memberActivitiesCounts();
      });

      // Watch member chances to update available instant win count.
      $rootScope.$watch(function() {
        return self.member_chances;
      }, function(newVal, oldVal) {
        if (!newVal) {
          self.instantWinCount = 0;
        }
        else {
          self.instantWinCount = $filter('filter')(newVal, {'played': '0'}, true).length;
        }
      });

      self.setInitialValues = function() {
        self.dates = settings.ymca_retention.activity.dates;
        self.activity_groups = settings.ymca_retention.activity.activity_groups;
        self.loss_messages = settings.ymca_retention.loss_messages;
        self.member = null;
        self.member_activities = null;
        self.member_activities_counts = null;
        self.member_chances = null;
        self.instantWinCount = 0;
        self.member_checkins = null;
        self.recent_winners = null;
        self.last_played_chance = null;
        // Game state.
        self.state = 'game';
      }();

      self.calculateLastPlayedChance = function(data) {
        var timestamp = 0;
        var last_played_chance;

        $.each(data, function(index, value) {
          if (value.played != '0' && value.played >= timestamp) {
            last_played_chance = value;
          }
        });

        return last_played_chance;
      };

      self.getMember = function(id) {
        courier.getMember(id).then(function(data) {
          self.member = data;
          self.member_loaded = true;
        });
      };

      self.getMemberChances = function() {
        var id = $cookies.get('Drupal.visitor.ymca_retention_member');
        return self.getMemberChancesById(id);
      };
      self.getMemberChancesById = function(id) {
        return courier.getMemberChances(id).then(function(data) {
          self.last_played_chance = self.calculateLastPlayedChance(data);
          self.member_chances = data;
        });
      };

      self.getMemberCheckIns = function(id) {
        courier.getMemberCheckIns(id).then(function(data) {
          self.member_checkins = data;
        });
      };

      self.getMemberActivities = function(id) {
        courier.getMemberActivities(id).then(function(data) {
          self.member_activities = data;
        });
      };
      self.setMemberActivities = function(data) {
        var $promise = courier.setMemberActivities(data).then(function(data) {
          self.member_activities = data;
          self.getMemberChances();
        });

        // Track the request and show its progress to the user.
        self.progress.addPromise($promise);
      };
      self.memberActivitiesCounts = function() {
        if (!self.member_activities) {
          self.member_activities_counts = null;
          return;
        }

        var count;
        self.member_activities_counts = {};
        for (var timestamp in self.member_activities) {
          self.member_activities_counts[timestamp] = {};
          for (var activity_group in self.activity_groups) {
            count = 0;
            for (var activity in self.activity_groups[activity_group].activities) {
              if (self.member_activities[timestamp][self.activity_groups[activity_group].activities[activity].id]) {
                count++;
              }
            }
            self.member_activities_counts[timestamp][self.activity_groups[activity_group].id] = count;
          }
        }
      };

      self.getMemberPrize = function() {
        return courier.getMemberPrize().then(function(data) {
          $timeout(function() {
            self.member_chances = data;
          }, 3000);
          return data;
        });
      };

      self.getRecentWinners = function() {
        courier.getRecentWinners().then(function(data) {
          self.recent_winners = data;
        });
      };

      self.memberCookieRemove = function() {
        $cookies.remove('Drupal.visitor.ymca_retention_member', { path: '/' });
      };
    });
  };

})(jQuery);
