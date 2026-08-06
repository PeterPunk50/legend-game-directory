/* Legend Create Developer Platform — Frontend JS */
(function($){
'use strict';

var LCDP = {
  init: function(){
    this.initTabs();
    this.initForms();
    this.initRatings();
    this.initTokens();
  },

  // Tab switcher
  initTabs: function(){
    $(document).on('click','.lcdp-tab',function(e){
      e.preventDefault();
      var target = $(this).attr('href');
      var $panels = $(this).closest('.lcdp-dashboard').find('.lcdp-tab-panel');
      var $tabs   = $(this).closest('.lcdp-dashboard__tabs').find('.lcdp-tab');
      $tabs.removeClass('lcdp-tab--active');
      $panels.removeClass('lcdp-active');
      $(this).addClass('lcdp-tab--active');
      $(target).addClass('lcdp-active');
    });
    // Activate first tab by default
    $('.lcdp-dashboard__tabs .lcdp-tab--active').trigger('click');
    // Also handle hash on load
    if(window.location.hash){
      var $match = $('.lcdp-dashboard__tabs .lcdp-tab[href="' + window.location.hash + '"]');
      if($match.length){ $match.trigger('click'); }
    }
  },

  // Game submission form
  initForms: function(){
    // Game submit
    $(document).on('submit','#lcdp-game-submit-form',function(e){
      e.preventDefault();
      var $btn = $(this).find('[type=submit]');
      var $msg = $('#lcdp-game-form-messages');
      var data = $(this).serializeArray();
      data.push({name:'action',value:'lcdp_submit_game'},{name:'nonce',value:lcdp.gameNonce});
      $btn.prop('disabled',true).text('Submitting…');
      $msg.removeClass('lcdp-success lcdp-error').hide();
      $.post(lcdp.ajaxUrl, data, function(res){
        if(res.success){
          $msg.addClass('lcdp-success').text(res.data.message).show();
          $('#lcdp-game-submit-form')[0].reset();
        } else {
          $msg.addClass('lcdp-error').text(res.data.message || 'Something went wrong.').show();
        }
        $btn.prop('disabled',false).text('Submit Game for Review');
      }).fail(function(){ $msg.addClass('lcdp-error').text('Request failed. Please try again.').show(); $btn.prop('disabled',false).text('Submit Game for Review'); });
    });

    // Tester apply form
    $(document).on('submit','#lcdp-tester-apply-form',function(e){
      e.preventDefault();
      var $btn = $(this).find('[type=submit]');
      var $msg = $('#lcdp-tester-form-messages');
      var data = $(this).serializeArray();
      // Append checkboxes that aren't checked (serializeArray skips unchecked)
      data.push({name:'action',value:'lcdp_save_tester_profile'},{name:'nonce',value:lcdp.testerNonce});
      $btn.prop('disabled',true).text('Submitting…');
      $.post(lcdp.ajaxUrl, data, function(res){
        if(res.success){
          $msg.addClass('lcdp-success').text(res.data.message).show();
          $('html,body').animate({scrollTop:$msg.offset().top - 100},400);
        } else {
          $msg.addClass('lcdp-error').text(res.data.message || 'Something went wrong.').show();
        }
        $btn.prop('disabled',false).text('Submit Application');
      }).fail(function(){ $msg.addClass('lcdp-error').text('Request failed. Please try again.').show(); $btn.prop('disabled',false).text('Submit Application'); });
    });

    // Developer profile form
    $(document).on('submit','.lcdp-developer-profile-form',function(e){
      e.preventDefault();
      var $btn = $(this).find('[type=submit]');
      var data = $(this).serializeArray();
      data.push({name:'action',value:'lcdp_save_developer_profile'},{name:'nonce',value:lcdp.devNonce});
      $btn.prop('disabled',true).text('Saving…');
      $.post(lcdp.ajaxUrl, data, function(res){
        $btn.prop('disabled',false).text('Save Profile');
        if(res.success){ LCDP.toast(res.data.message,'success'); }
        else { LCDP.toast(res.data.message || 'Error saving profile.','error'); }
      });
    });
  },

  // Star rating UI
  initRatings: function(){
    $(document).on('mouseover','.lcdp-star-btn',function(){
      var val = $(this).data('value');
      $(this).closest('.lcdp-star-select').find('.lcdp-star-btn').each(function(i){
        $(this).toggleClass('lcdp-selected', i < val);
      });
    }).on('mouseout','.lcdp-star-select',function(){
      var selected = $(this).data('selected') || 0;
      $(this).find('.lcdp-star-btn').each(function(i){
        $(this).toggleClass('lcdp-selected', i < selected);
      });
    }).on('click','.lcdp-star-btn',function(){
      var val = $(this).data('value');
      var $sel = $(this).closest('.lcdp-star-select');
      $sel.data('selected', val).find('.lcdp-star-btn').each(function(i){
        $(this).toggleClass('lcdp-selected', i < val);
      });
    });

    $(document).on('click','.lcdp-submit-rating',function(){
      var $form = $(this).closest('.lcdp-rating-form');
      var rating = $form.find('.lcdp-star-select').data('selected') || 0;
      if(!rating){ LCDP.toast('Please select a star rating.','error'); return; }
      var data = {
        action: 'lcdp_submit_rating',
        nonce:  lcdp.ratingNonce,
        entity_type: $form.data('entity-type'),
        entity_id:   $form.data('entity-id'),
        rating:      rating,
        review_text: $form.find('.lcdp-review-text').val(),
      };
      $(this).prop('disabled',true);
      $.post(lcdp.ajaxUrl, data, function(res){
        if(res.success){
          LCDP.toast('Rating submitted!','success');
          $form.find('.lcdp-star-select').data('selected',0).find('.lcdp-star-btn').removeClass('lcdp-selected');
          $form.find('.lcdp-review-text').val('');
        } else {
          LCDP.toast(res.data.message || 'Error.','error');
        }
      }).always(function(){ $('.lcdp-submit-rating').prop('disabled',false); });
    });
  },

  // Token redemption
  initTokens: function(){
    $(document).on('click','.lcdp-redeem-membership',function(){
      var $btn = $(this);
      if(!confirm('Redeem 5 Legend Tokens for 6 months of Developer Starter membership?')){ return; }
      $btn.prop('disabled',true).text('Redeeming…');
      $.post(lcdp.ajaxUrl, {action:'lcdp_redeem_membership', nonce:lcdp.nonce}, function(res){
        if(res.success){ LCDP.toast(res.data.message,'success'); $btn.closest('.lcdp-wallet__reward').fadeOut(); }
        else { LCDP.toast(res.data.message,'error'); $btn.prop('disabled',false).text('Claim Membership'); }
      });
    });
  },

  // Toast notifications
  toast: function(msg, type){
    var $t = $('<div class="lcdp-toast lcdp-toast--' + (type||'info') + '">' + msg + '</div>');
    $('body').append($t);
    setTimeout(function(){ $t.addClass('lcdp-toast--in'); },50);
    setTimeout(function(){ $t.removeClass('lcdp-toast--in'); setTimeout(function(){ $t.remove(); },400); },3500);
  }
};

$(function(){ LCDP.init(); });

})(jQuery);
