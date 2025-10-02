(function ($) {
  "use strict";

  const CouponAutomation = {
    init: function () {
      this.bindEvents();
      this.initTabs();
      this.initStatusRefresh();
    },

    bindEvents: function () {
      // Quick action buttons
      $("#fetch-coupons-btn").on("click", this.handleFetchCoupons);
      $("#stop-automation-btn").on("click", this.handleStopAutomation);
      $("#clear-cache-btn").on("click", this.handleClearCache);
      $("#purge-expired-btn").on("click", this.handlePurgeExpired);

      // Test API buttons
      $(".test-api-btn").on("click", this.handleTestAPI);

      // Brand population
      $("#start-population").on("click", this.handleBrandPopulation);

      // Clear notifications
      $("#clear-notifications").on("click", this.handleClearNotifications);
    },

    initTabs: function () {
      $(".ca-tab").on("click", function (e) {
        e.preventDefault();
        const tabId = $(this).data("tab");

        // Update tab buttons - FIXED: removed Tailwind classes
        $(".ca-tab").removeClass("active");
        $(this).addClass("active");

        // Update tab content - FIXED: use active class instead of hidden
        $(".ca-tab-content").removeClass("active");
        $(`#${tabId}-tab`).addClass("active");
      });
    },

    initStatusRefresh: function () {
      if (!couponAutomation.enableStatusPolling) {
        return;
      }

      setInterval(function () {
        const isRunning = jQuery(".ca-status-running").length > 0;
        if (isRunning) {
          // Reload the page to update status
          // Or implement AJAX refresh if preferred
          jQuery.ajax({
            url: couponAutomation.ajaxUrl,
            method: "POST",
            data: {
              action: "get_sync_status",
              nonce: couponAutomation.nonce,
            },
            success: function (response) {
              if (response.success && response.data) {
                // Update status display
                jQuery(".ca-status-item")
                  .first()
                  .find("span")
                  .text(response.data.status_text);
              }
            },
          });
        }
      }, 30000); 
    },

    handleFetchCoupons: function (e) {
      e.preventDefault();
      const $btn = $(this);

      CouponAutomation.setButtonLoading($btn, true);

      $.ajax({
        url: couponAutomation.ajaxUrl,
        method: "POST",
        data: {
          action: "fetch_coupons",
          nonce: couponAutomation.nonce,
        },
        success: function (response) {
          if (response.success) {
            CouponAutomation.showToast("success", "Sync started successfully!");
            CouponAutomation.updateLastSync();
          } else {
            CouponAutomation.showToast(
              "error",
              response.data || "Failed to start sync"
            );
          }
        },
        error: function () {
          CouponAutomation.showToast(
            "error",
            "An error occurred. Please try again."
          );
        },
        complete: function () {
          CouponAutomation.setButtonLoading($btn, false);
        },
      });
    },

    handleStopAutomation: function (e) {
      e.preventDefault();
      const $btn = $(this);

      if (!confirm("Are you sure you want to stop the automation?")) {
        return;
      }

      CouponAutomation.setButtonLoading($btn, true);

      $.ajax({
        url: couponAutomation.ajaxUrl,
        method: "POST",
        data: {
          action: "stop_automation",
          nonce: couponAutomation.nonce,
        },
        success: function (response) {
          if (response.success) {
            CouponAutomation.showToast("success", "Automation stopped");
          } else {
            CouponAutomation.showToast(
              "error",
              response.data || "Failed to stop automation"
            );
          }
        },
        error: function () {
          CouponAutomation.showToast(
            "error",
            "An error occurred. Please try again."
          );
        },
        complete: function () {
          CouponAutomation.setButtonLoading($btn, false);
        },
      });
    },

    handleClearCache: function (e) {
      e.preventDefault();
      const $btn = $(this);

      CouponAutomation.setButtonLoading($btn, true);

      $.ajax({
        url: couponAutomation.ajaxUrl,
        method: "POST",
        data: {
          action: "clear_coupon_flags",
          nonce: couponAutomation.nonce,
        },
        success: function (response) {
          if (response.success) {
            CouponAutomation.showToast("success", "Cache cleared successfully");
          } else {
            CouponAutomation.showToast(
              "error",
              response.data || "Failed to clear cache"
            );
          }
        },
        error: function () {
          CouponAutomation.showToast(
            "error",
            "An error occurred. Please try again."
          );
        },
        complete: function () {
          CouponAutomation.setButtonLoading($btn, false);
        },
      });
    },

    handlePurgeExpired: function (e) {
      e.preventDefault();
      const $btn = $(this);

      if (!confirm("Are you sure you want to purge all expired coupons?")) {
        return;
      }

      CouponAutomation.setButtonLoading($btn, true);

      $.ajax({
        url: couponAutomation.ajaxUrl,
        method: "POST",
        data: {
          action: "purge_expired_coupons",
          nonce: couponAutomation.nonce,
        },
        success: function (response) {
          if (response.success) {
            CouponAutomation.showToast("success", response.data);
          } else {
            CouponAutomation.showToast(
              "error",
              response.data || "Failed to purge coupons"
            );
          }
        },
        error: function () {
          CouponAutomation.showToast(
            "error",
            "An error occurred. Please try again."
          );
        },
        complete: function () {
          CouponAutomation.setButtonLoading($btn, false);
        },
      });
    },

    // FIXED: Preserve original button HTML and restore after test
    handleTestAPI: function (e) {
      e.preventDefault();
      const $btn = $(this);
      const api = $btn.data("api");
      const originalHtml = $btn.html(); // Save original button content

      // Show loading spinner
      $btn.html(
        '<svg class="ca-spinner" style="width:20px;height:20px;" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>'
      );

      $.ajax({
        url: couponAutomation.ajaxUrl,
        method: "POST",
        data: {
          action: "test_api_connection",
          api: api,
          nonce: couponAutomation.nonce,
        },
        success: function (response) {
          if (response.success) {
            $btn.html(
              '<svg style="width:20px;height:20px;color:#10b981;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>'
            );
            CouponAutomation.updateAPIStatus(api, true);
          } else {
            $btn.html(
              '<svg style="width:20px;height:20px;color:#ef4444;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>'
            );
            CouponAutomation.updateAPIStatus(api, false);
          }

          // Restore original button after 3 seconds
          setTimeout(function () {
            $btn.html(originalHtml);
          }, 3000);
        },
        error: function () {
          $btn.html(
            '<svg style="width:20px;height:20px;color:#ef4444;" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path></svg>'
          );
          CouponAutomation.updateAPIStatus(api, false);
          setTimeout(function () {
            $btn.html(originalHtml);
          }, 3000);
        },
      });
    },

    handleBrandPopulation: function (e) {
      e.preventDefault();
      const $btn = $(this);
      let isProcessing = true;
      let processedCount = 0;

      CouponAutomation.setButtonLoading($btn, true);
      $(".brand-population-progress").show();

      function processBatch() {
        if (!isProcessing) return;

        $.ajax({
          url: couponAutomation.ajaxUrl,
          method: "POST",
          data: {
            action: "populate_brands_batch",
            nonce: couponAutomation.nonce,
            offset: processedCount,
          },
          success: function (response) {
            if (response.success) {
              processedCount += response.data.processed;
              const totalBrands = response.data.total;
              const percentage = (processedCount / totalBrands) * 100;

              $(".progress").css("width", percentage + "%");
              $(".processed-count").text(processedCount);
              $(".total-count").text(totalBrands);

              response.data.log.forEach(function (entry) {
                $(".log-entries").prepend(
                  '<p class="text-sm text-gray-600">' + entry + "</p>"
                );
              });

              if (processedCount < totalBrands && isProcessing) {
                setTimeout(processBatch, 1000);
              } else {
                CouponAutomation.setButtonLoading($btn, false);
                CouponAutomation.showToast(
                  "success",
                  "Brand population completed!"
                );
              }
            }
          },
          error: function () {
            isProcessing = false;
            CouponAutomation.setButtonLoading($btn, false);
            CouponAutomation.showToast(
              "error",
              "An error occurred during processing"
            );
          },
        });
      }

      processBatch();
    },

    handleClearNotifications: function (e) {
      e.preventDefault();

      $.ajax({
        url: couponAutomation.ajaxUrl,
        method: "POST",
        data: {
          action: "clear_notifications",
          nonce: couponAutomation.nonce,
        },
        success: function (response) {
          if (response.success) {
            $("#notifications-container").fadeOut();
          }
        },
      });
    },

    // FIXED: Use custom classes instead of Tailwind
    updateAPIStatus: function (api, isConnected) {
      const $indicator = $(`.api-status-indicator[data-api="${api}"]`);

      if (isConnected) {
        $indicator.removeClass("disconnected").addClass("connected");
      } else {
        $indicator.removeClass("connected").addClass("disconnected");
      }
    },

    updateLastSync: function () {
      const now = new Date();
      $(".last-sync-time").text("Just now");
    },

    // FIXED: Use custom loading class instead of Tailwind opacity
    setButtonLoading: function ($btn, isLoading) {
      if (isLoading) {
        $btn.prop("disabled", true).addClass("ca-btn-loading");

        // Add spinner if not exists
        if (!$btn.find(".ca-spinner").length) {
          $btn.prepend(
            '<svg class="ca-spinner" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg> '
          );
        }
      } else {
        $btn
          .prop("disabled", false)
          .removeClass("ca-btn-loading")
          .find(".ca-spinner")
          .remove();
      }
    },

    // FIXED: Use custom toast classes
    showToast: function (type, message) {
      const toastId = "toast-" + Date.now();
      const bgColor = type === "success" ? "bg-green" : "bg-red";
      const icon =
        type === "success"
          ? '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z"></path>'
          : '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 14l2-2m0 0l2-2m-2 2l-2-2m2 2l2 2m7-2a9 9 0 11-18 0 9 9 0 0118 0z"></path>';

      const toast = `
                <div id="${toastId}" class="ca-toast ${bgColor}">
                    <svg class="ca-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                        ${icon}
                    </svg>
                    <div class="ca-toast-message">${message}</div>
                    <button class="ca-toast-close" onclick="$('#${toastId}').remove()">
                        <svg class="ca-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 18L18 6M6 6l12 12"></path>
                        </svg>
                    </button>
                </div>
            `;

      $("#toast-container").append(toast);

      // Auto remove after 5 seconds
      setTimeout(function () {
        $(`#${toastId}`).css("animation", "slideOut 0.3s ease-out forwards");
        setTimeout(function () {
          $(`#${toastId}`).remove();
        }, 300);
      }, 5000);
    },
  };

  // Initialize when document is ready
  $(document).ready(function () {
    CouponAutomation.init();
  });
})(jQuery);
