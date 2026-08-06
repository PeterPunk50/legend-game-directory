/* Legend Create Developer Platform — Admin JS */
(function($){
'use strict';

$(function(){

  // Campaign status update
  $(document).on('click','.lcdp-update-status',function(){
    var id  = $(this).data('campaign');
    var sel = $(this).siblings('.lcdp-status-select').val();
    $.post(lcdpAdmin.ajaxUrl,{action:'lcdp_update_campaign_status',nonce:lcdpAdmin.nonce,campaign_id:id,status:sel},function(res){
      if(res.success){ location.reload(); }
      else { alert(res.data.message || 'Error.'); }
    });
  });

  // Tester status update
  $(document).on('click','.lcdp-update-tester-status',function(){
    var uid   = $(this).data('user');
    var sel   = $(this).siblings('.lcdp-tester-status').val();
    var notes = $(this).siblings('.lcdp-tester-notes').val();
    $.post(lcdpAdmin.ajaxUrl,{action:'lcdp_admin_update_tester_status',nonce:lcdpAdmin.nonce,user_id:uid,status:sel,notes:notes},function(res){
      if(res.success){ location.reload(); }
      else { alert(res.data.message || 'Error.'); }
    });
  });

  // Award points
  $(document).on('click','.lcdp-award-points-btn',function(){
    var uid    = $(this).data('user');
    var points = $(this).siblings('.lcdp-award-points-input').val();
    var reason = $(this).siblings('.lcdp-award-reason').val();
    if(!points || !reason){ alert('Points and reason are required.'); return; }
    var $btn = $(this).prop('disabled',true);
    $.post(lcdpAdmin.ajaxUrl,{action:'lcdp_admin_award_points',nonce:lcdpAdmin.nonce,user_id:uid,points:points,reason:reason},function(res){
      if(res.success){ alert(res.data.message + ' New token balance: ' + res.data.new_token_balance); location.reload(); }
      else { alert(res.data.message || 'Error.'); $btn.prop('disabled',false); }
    });
  });

  // Generate report draft
  $(document).on('click','.lcdp-generate-report',function(){
    var cid  = $(this).data('campaign');
    var $btn = $(this).prop('disabled',true).text('Generating…');
    $.post(lcdpAdmin.ajaxUrl,{action:'lcdp_admin_generate_report',nonce:lcdpAdmin.nonce,campaign_id:cid},function(res){
      $btn.prop('disabled',false).text('Generate AI Draft');
      if(res.success){ alert('Draft generated. ID: ' + res.data.draft_id + '. Review before sending.'); location.reload(); }
      else { alert(res.data.message || 'Error.'); }
    });
  });

});
})(jQuery);
